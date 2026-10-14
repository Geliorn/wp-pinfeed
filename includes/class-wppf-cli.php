<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: `wp pinfeed <command>`.
 */
class WPPF_CLI {

	/**
	 * Lists configured feeds and their URLs.
	 *
	 * ## EXAMPLES
	 *     wp pinfeed list
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array();
		foreach ( get_posts( array( 'post_type' => WPPF_CPT::FEED, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $feed ) {
			$config = WPPF_Feed_Config::get( $feed->ID );
			$rows[] = array(
				'id'      => $feed->ID,
				'title'   => get_the_title( $feed ),
				'status'  => $feed->post_status . ( $config['enabled'] ? '' : ' (off)' ),
				'types'   => implode( ',', $config['post_types'] ),
				'matches' => WPPF_Query::count( $config ),
				'url'     => $config['published'] ? WPPF_Router::feed_url( $feed->ID ) : '',
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'title', 'status', 'types', 'matches', 'url' ) );
	}

	/**
	 * Runs the background tick now: LLM texts and pin images for all active feeds.
	 *
	 * ## EXAMPLES
	 *     wp pinfeed warm
	 */
	public function warm( $args, $assoc ) {
		WP_CLI::success( WPPF_Scheduler::run_tick() );
	}

	/**
	 * Shows what a feed would serve.
	 *
	 * ## OPTIONS
	 * <feed>
	 * : Feed post ID, or taxonomy:term-slug for an automatic feed.
	 *
	 * [--render]
	 * : Render missing pin images now (no budget).
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *     wp pinfeed preview 123
	 *     wp pinfeed preview repo_topic:mcp --render
	 */
	public function preview( $args, $assoc ) {
		$config = $this->config( $args[0] );
		if ( empty( $assoc['render'] ) ) {
			WPPF_Pin_Builder::set_sync_budget( 0 );
		}

		$rows = array();
		foreach ( WPPF_Query::posts( $config ) as $post ) {
			$pin    = WPPF_Pin_Builder::build( $post, $config, ! empty( $assoc['render'] ) );
			$rows[] = array(
				'post'        => $post->ID,
				'status'      => $pin['status'],
				'title'       => $pin['title'],
				'description' => mb_substr( str_replace( "\n", ' ', $pin['description'] ), 0, 80 ),
				'image'       => $pin['image'] ? $pin['image']['url'] : '',
				'source'      => $pin['image'] ? $pin['image']['source'] : '',
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'post', 'status', 'title', 'description', 'source', 'image' ) );
	}

	/**
	 * Prints the feed XML.
	 *
	 * ## OPTIONS
	 * <feed>
	 * : Feed post ID, or taxonomy:term-slug.
	 *
	 * ## EXAMPLES
	 *     wp pinfeed xml 123 > feed.xml
	 */
	public function xml( $args, $assoc ) {
		$config = $this->config( $args[0] );
		$url    = 'feed' === $config['kind'] ? WPPF_Router::feed_url( $config['feed_id'] ) : WPPF_Router::term_feed_url( $config['term'] );
		echo WPPF_Feed_Renderer::xml( $config, $url ); // phpcs:ignore
	}

	/**
	 * Copies the LLM provider and keys from another plugin of the network.
	 *
	 * ## OPTIONS
	 * --from=<source>
	 * : articles | github_repos
	 *
	 * ## EXAMPLES
	 *     wp pinfeed import-llm --from=articles
	 *
	 * @subcommand import-llm
	 */
	public function import_llm( $args, $assoc ) {
		$count = WPPF_Integrations::import_llm( (string) ( $assoc['from'] ?? '' ) );
		if ( ! $count ) {
			WP_CLI::error( 'Нечего импортировать: плагин не найден или ключи пусты.' );
		}
		WP_CLI::success( 'Скопировано полей: ' . $count );
	}

	/**
	 * Drops the cached XML of every feed.
	 *
	 * @subcommand flush-cache
	 */
	public function flush_cache( $args, $assoc ) {
		WPPF_Feed_Renderer::flush_cache();
		WP_CLI::success( 'Кэш лент сброшен.' );
	}

	private function config( $ref ) {
		if ( false !== strpos( $ref, ':' ) ) {
			list( $taxonomy, $slug ) = explode( ':', $ref, 2 );
			$config                  = WPPF_Feed_Config::get_auto( $taxonomy, $slug );
		} else {
			$config = WPPF_Feed_Config::get( (int) $ref );
		}
		if ( ! $config ) {
			WP_CLI::error( 'Лента не найдена: ' . $ref . ' (для автоленты проверьте, что автоленты включены для этой таксономии).' );
		}
		return $config;
	}
}

WP_CLI::add_command( 'pinfeed', 'WPPF_CLI' );
