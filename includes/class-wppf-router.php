<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed URLs:
 *   /pinfeed/<feed-slug>/                    a configured feed (`pinfeed` post)
 *   /pinfeed/term/<taxonomy>/<term-slug>/    the automatic feed of a term
 * and the same as query args (?wppf_feed=... / ?wppf_tax=...&wppf_term=...)
 * for sites without pretty permalinks. ?wppf_preview=1 on either shows an
 * HTML preview of the pins instead of XML (editors only).
 */
class WPPF_Router {

	const REWRITE_VERSION = 'wppf_rewrite_ver';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rules' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
		// No trailing-slash redirect dance on the feed URL — Pinterest's
		// validator is picky about redirects.
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical_redirect' ), 10, 2 );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^pinfeed/term/([^/]+)/([^/]+)/?$', 'index.php?wppf_tax=$matches[1]&wppf_term=$matches[2]', 'top' );
		add_rewrite_rule( '^pinfeed/([^/]+)/?$', 'index.php?wppf_feed=$matches[1]', 'top' );
	}

	/** Self-heal after an in-place update that changed the rules. */
	public static function maybe_flush_rules() {
		if ( get_option( self::REWRITE_VERSION ) !== WPPF_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_VERSION, WPPF_VERSION, true );
		}
	}

	public static function query_vars( $vars ) {
		return array_merge( $vars, array( 'wppf_feed', 'wppf_tax', 'wppf_term', 'wppf_preview' ) );
	}

	public static function no_canonical_redirect( $redirect, $requested ) {
		if ( get_query_var( 'wppf_feed' ) || get_query_var( 'wppf_tax' ) ) {
			return false;
		}
		return $redirect;
	}

	public static function maybe_serve() {
		$feed_slug = (string) get_query_var( 'wppf_feed' );
		$taxonomy  = (string) get_query_var( 'wppf_tax' );
		if ( '' === $feed_slug && '' === $taxonomy ) {
			return;
		}

		$preview = (bool) get_query_var( 'wppf_preview' ) && current_user_can( 'edit_posts' );
		$config  = null;

		if ( '' !== $feed_slug ) {
			$post = get_page_by_path( sanitize_title( $feed_slug ), OBJECT, WPPF_CPT::FEED );
			if ( $post ) {
				$config = WPPF_Feed_Config::get( $post->ID );
			}
			if ( $config && ! $preview && ( ! $config['published'] || ! $config['enabled'] ) ) {
				$config = null;
			}
		} else {
			$config = WPPF_Feed_Config::get_auto( sanitize_key( $taxonomy ), sanitize_title( (string) get_query_var( 'wppf_term' ) ) );
		}

		if ( ! $config ) {
			self::not_found();
		}

		$self_url = '' !== $feed_slug ? self::feed_url( $config['feed_id'] ) : self::term_feed_url( $config['term'] );

		if ( $preview ) {
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			echo WPPF_Feed_Renderer::preview_html( $config, $self_url ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		self::record_fetch( $config );

		$xml = WPPF_Feed_Renderer::xml( $config, $self_url );

		status_header( 200 );
		header( 'Content-Type: application/rss+xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'Cache-Control: public, max-age=' . 60 * max( 1, (int) WPPF_Settings::get( 'feed_cache_minutes', 30 ) ) );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function not_found() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Feed not found';
		exit;
	}

	/**
	 * Who fetched the feed and when — shown on the feed screen, and for
	 * automatic feeds it decides which terms the background tick keeps warm
	 * (a site can have thousands of terms; only fetched ones matter).
	 */
	private static function record_fetch( array $config ) {
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$now = time();

		if ( 'feed' === $config['kind'] ) {
			$prefix = WPPF_Feed_Config::PREFIX;
			update_post_meta( $config['feed_id'], $prefix . 'last_fetch_at', $now );
			update_post_meta( $config['feed_id'], $prefix . 'last_fetch_ua', mb_substr( $ua, 0, 200 ) );
			update_post_meta( $config['feed_id'], $prefix . 'fetch_count', (int) get_post_meta( $config['feed_id'], $prefix . 'fetch_count', true ) + 1 );
		} elseif ( $config['term'] ) {
			update_term_meta( $config['term']->term_id, 'wppf_last_fetch_at', $now );
		}
	}

	// ----------------------------------------------------------------- urls

	public static function feed_url( $feed_id ) {
		$post = get_post( $feed_id );
		if ( ! $post ) {
			return '';
		}
		$slug = $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( 'pinfeed/' . $slug ) );
		}
		return add_query_arg( 'wppf_feed', $slug, home_url( '/' ) );
	}

	/** Empty string when this term has no automatic feed. */
	public static function term_feed_url( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return '';
		}
		if ( ! WPPF_Settings::get( 'auto_feeds_enabled' ) || ! in_array( $term->taxonomy, (array) WPPF_Settings::get( 'auto_feeds_taxonomies', array() ), true ) ) {
			return '';
		}
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( 'pinfeed/term/' . $term->taxonomy . '/' . $term->slug ) );
		}
		return add_query_arg(
			array(
				'wppf_tax'  => $term->taxonomy,
				'wppf_term' => $term->slug,
			),
			home_url( '/' )
		);
	}

	public static function preview_url( $feed_url ) {
		return add_query_arg( 'wppf_preview', '1', $feed_url );
	}
}
