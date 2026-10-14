<?php
/**
 * Plugin Name: WP PinFeed
 * Description: RSS-ленты для автопубликации в Pinterest по таксономиям. Ленты настраиваются в админке (типы записей, условия по терминам, шаблоны текста, цепочка картинок, вертикальные пины-инфографики 2:3 через GD, текст и тезисы через LLM), плюс автоматическая лента у каждого термина выбранных таксономий. Интеграция с llm-agent-articles и llm-agent-github-repos. Самодостаточен — без ACF/CPT UI.
 * Version: 0.2.0
 * Author: llm-agent.ru
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wppf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPF_VERSION', '0.2.0' );
define( 'WPPF_PLUGIN_FILE', __FILE__ );
define( 'WPPF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPPF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-settings.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-log.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-cpt.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-integrations.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-feed-config.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-query.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-template.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-llm-client.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-llm-text.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-term-image.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-post-meta.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-image-presets.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-image-composer.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-infographic-presets.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-infographic-composer.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-infographic.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-image-resolver.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-pin-builder.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-feed-renderer.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-router.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-scheduler.php';
require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPPF_PLUGIN_DIR . 'includes/class-wppf-cli.php';
}

/**
 * Boot — every class hooks itself in its own init(), same convention as the
 * other plugins of the network (llm-agent-articles, llm-agent-github-repos).
 */
function wppf_boot() {
	WPPF_Settings::init();
	WPPF_Integrations::init();
	WPPF_CPT::init();
	WPPF_Feed_Config::init();
	WPPF_Term_Image::init();
	WPPF_Post_Meta::init();
	WPPF_Pin_Builder::init();
	WPPF_Feed_Renderer::init();
	WPPF_Router::init();
	WPPF_Scheduler::init();
	WPPF_Admin::init();
}
add_action( 'plugins_loaded', 'wppf_boot' );

function wppf_activate() {
	WPPF_CPT::register_post_types();
	WPPF_Router::add_rewrite_rules();
	WPPF_Image_Presets::ensure_defaults();
	WPPF_Infographic_Presets::ensure_defaults();
	WPPF_Scheduler::activate();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wppf_activate' );

function wppf_deactivate() {
	WPPF_Scheduler::deactivate();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wppf_deactivate' );
