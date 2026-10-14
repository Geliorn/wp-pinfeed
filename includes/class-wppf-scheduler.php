<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background "warm-up" of every feed: LLM text and composed pin images are
 * produced here, not while Pinterest waits for the XML.
 *
 * Three ways to trigger the same tick, as in the other plugins of the network:
 *  1) WP-Cron on a 10-minute schedule (default, zero setup);
 *  2) an HTTP endpoint guarded by a secret (?pinfeed_cron=...);
 *  3) WP-CLI (`wp pinfeed warm`) or cron.php for a real crontab.
 * All of them end in run_tick().
 */
class WPPF_Scheduler {

	const CRON_HOOK              = 'wppf_tick_event';
	const TICK_RUNNING_TRANSIENT = 'wppf_tick_running';
	const ENDPOINT_THROTTLE      = 'wppf_endpoint_throttle';
	const HTTP_QUERY_PARAM       = 'pinfeed_cron';
	const LAST_TICK_OPTION       = 'wppf_last_tick';
	const TICK_LOCK_SECONDS      = 9 * MINUTE_IN_SECONDS;

	/** Automatic feeds fetched within this window are kept warm. */
	const AUTO_ACTIVE_DAYS = 14;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_http_trigger' ), 5 );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'wppf_10_minutes', self::CRON_HOOK );
		}
	}

	public static function add_schedule( $schedules ) {
		$schedules['wppf_10_minutes'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Каждые 10 минут (WP PinFeed)',
		);
		return $schedules;
	}

	public static function activate() {
		self::ensure_scheduled();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function maybe_handle_http_trigger() {
		if ( ! isset( $_GET[ self::HTTP_QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$given  = sanitize_text_field( wp_unslash( $_GET[ self::HTTP_QUERY_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$secret = (string) WPPF_Settings::get( 'cron_secret', '' );

		if ( '' === $secret || ! hash_equals( $secret, $given ) ) {
			status_header( 403 );
			exit( 'forbidden' );
		}
		if ( get_transient( self::ENDPOINT_THROTTLE ) ) {
			exit( 'throttled' );
		}
		set_transient( self::ENDPOINT_THROTTLE, 1, MINUTE_IN_SECONDS );

		ignore_user_abort( true );
		$summary = self::run_tick();
		header( 'Content-Type: text/plain; charset=utf-8' );
		exit( esc_html( $summary ) );
	}

	/** Every feed config that should be kept warm right now. */
	public static function active_configs() {
		$configs = array();

		$feeds = get_posts(
			array(
				'post_type'      => WPPF_CPT::FEED,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $feeds as $feed_id ) {
			$config = WPPF_Feed_Config::get( $feed_id );
			if ( $config && $config['enabled'] ) {
				$configs[] = $config;
			}
		}

		if ( WPPF_Settings::get( 'auto_feeds_enabled' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => (array) WPPF_Settings::get( 'auto_feeds_taxonomies', array() ),
					'hide_empty' => true,
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => 'wppf_last_fetch_at',
							'value'   => time() - self::AUTO_ACTIVE_DAYS * DAY_IN_SECONDS,
							'compare' => '>=',
							'type'    => 'NUMERIC',
						),
					),
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$config = WPPF_Feed_Config::get_auto( $term->taxonomy, $term );
					if ( $config ) {
						$configs[] = $config;
					}
				}
			}
		}

		return $configs;
	}

	/**
	 * One pass over all active feeds, newest posts first, within two budgets:
	 * LLM calls and rendered images per tick.
	 */
	public static function run_tick() {
		if ( get_transient( self::TICK_RUNNING_TRANSIENT ) ) {
			return 'tick уже выполняется';
		}
		set_transient( self::TICK_RUNNING_TRANSIENT, time(), self::TICK_LOCK_SECONDS );

		$started    = microtime( true );
		$llm_left   = max( 0, (int) WPPF_Settings::get( 'llm_per_tick', 10 ) );
		$image_left = max( 0, (int) WPPF_Settings::get( 'images_per_tick', 30 ) );
		$done_llm   = 0;
		$done_img   = 0;
		$feeds      = 0;

		try {
			foreach ( self::active_configs() as $config ) {
				$feeds++;
				$changed = false;

				foreach ( WPPF_Query::posts( $config ) as $post ) {
					// Stop well before the lock expires.
					if ( microtime( true ) - $started > self::TICK_LOCK_SECONDS - 60 ) {
						break 2;
					}

					// Points first: the infographic can't be drawn without them.
					if ( $llm_left > 0 && WPPF_Infographic::needs_generation( $post, $config ) ) {
						$llm_left--;
						if ( WPPF_Infographic::generate( $post, $config ) ) {
							$done_llm++;
							$changed = true;
						}
					}

					if ( $llm_left > 0 && WPPF_LLM_Text::needs_generation( $post, $config ) ) {
						$llm_left--;
						if ( WPPF_LLM_Text::generate( $post, $config ) ) {
							$done_llm++;
							$changed = true;
						}
					}

					if ( $image_left > 0 ) {
						WPPF_Pin_Builder::set_sync_budget( 1 );
						$pin = WPPF_Pin_Builder::build( $post, $config, true );
						if ( $pin['rendered'] ) {
							$image_left--;
							$done_img++;
							$changed = true;
						}
					}

					if ( 0 === $llm_left && 0 === $image_left ) {
						break 2;
					}
				}

				if ( $changed ) {
					WPPF_Feed_Renderer::flush_cache( $config['feed_key'] );
				}
			}
		} finally {
			delete_transient( self::TICK_RUNNING_TRANSIENT );
		}

		$summary = sprintf( 'Лент: %d, ответов LLM (тексты и тезисы): %d, пинов собрано: %d, %.1f с', $feeds, $done_llm, $done_img, microtime( true ) - $started );
		update_option(
			self::LAST_TICK_OPTION,
			array(
				'time'    => time(),
				'summary' => $summary,
			),
			false
		);
		if ( $done_llm || $done_img ) {
			WPPF_Log::add( 'Тик: ' . $summary );
		}
		return $summary;
	}

	/** Ready-to-paste commands for an external cron, shown on the settings screen. */
	public static function get_trigger_commands() {
		$url = add_query_arg( self::HTTP_QUERY_PARAM, WPPF_Settings::get( 'cron_secret', '' ), home_url( '/' ) );
		return array(
			'curl' => '*/10 * * * * curl -s "' . $url . '" > /dev/null 2>&1',
			'wget' => '*/10 * * * * wget -q -O - "' . $url . '" > /dev/null 2>&1',
			'php'  => '*/10 * * * * php ' . WPPF_PLUGIN_DIR . 'cron.php > /dev/null 2>&1',
		);
	}
}
