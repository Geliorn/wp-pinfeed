<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One post type: `pinfeed` — a configured RSS feed for one Pinterest board.
 * Not public (the feed itself is served by WPPF_Router, not as a post
 * permalink), but with a UI, same as laa_plan / github_repo_rule.
 * The post slug is the feed's URL slug: /pinfeed/<slug>/.
 */
class WPPF_CPT {

	const FEED = 'pinfeed';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
	}

	public static function register_post_types() {
		register_post_type(
			self::FEED,
			array(
				'label'        => 'Pinterest-ленты',
				'labels'       => array(
					'name'          => 'Pinterest-ленты',
					'singular_name' => 'Pinterest-лента',
					'add_new'       => 'Добавить ленту',
					'add_new_item'  => 'Новая лента',
					'edit_item'     => 'Лента',
					'search_items'  => 'Поиск лент',
					'all_items'     => 'Все ленты',
					'not_found'     => 'Лент пока нет',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'menu_icon'    => 'dashicons-rss',
				'show_in_menu' => WPPF_Admin::MAIN_SLUG,
			)
		);
	}
}
