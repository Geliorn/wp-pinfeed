<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin-wide settings in one option array. Per-feed settings live as post
 * meta on the `pinfeed` post (see WPPF_Feed_Config); the config of the
 * automatic per-term feeds lives in its own option (WPPF_Feed_Config::AUTO_OPTION),
 * because it has the same shape as a feed and is edited with the same form.
 * Infographic presets have their own option too (WPPF_Infographic_Presets), so a tuned
 * set can be exported between sites of the network.
 */
class WPPF_Settings {

	const OPTION_KEY = 'wppf_settings';

	public static function defaults() {
		return array(
			// ---------------------------------------------------------- LLM
			// Same five OpenAI-compatible providers as llm-agent-articles /
			// llm-agent-github-repos; keys can be imported from either of them.
			'llm_provider'          => 'deepseek',
			'yandex_api_key'        => '',
			'yandex_folder_id'      => '',
			'yandex_model'          => 'deepseek-v32/latest',
			'deepseek_api_key'      => '',
			'deepseek_model'        => 'deepseek-chat',
			'qwen_api_key'          => '',
			'qwen_model'            => 'qwen-plus',
			'qwen_base_url'         => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
			'timeweb_agent_id'      => '',
			'timeweb_token'         => '',
			'timeweb_proxy_source'  => '',
			'openrouter_api_key'    => '',
			'openrouter_model'      => 'deepseek/deepseek-chat',
			'llm_timeout'           => 60,
			'llm_per_tick'          => 10,   // LLM calls per background tick, across all feeds

			// ------------------------------------------------- automatic feeds
			'auto_feeds_enabled'    => 0,
			'auto_feeds_taxonomies' => array(), // taxonomy names with a feed per term
			'auto_feeds_min_count'  => 1,       // terms with fewer posts get no feed (404)

			// ----------------------------------------------------------- images
			'caption_font_path'     => '',
			// How many pin images may be rendered synchronously while Pinterest
			// waits for the feed. The rest are rendered by the background tick;
			// until then the item falls back (see the feed's image settings).
			'sync_render_limit'     => 3,
			'images_per_tick'       => 30,

			// ---------------------------------------------------------- feeds
			'feed_cache_minutes'    => 30,   // whole-XML cache per feed, 0 = off
			'seo_plugin'            => 'auto', // auto | yoast | rankmath | none

			'cron_secret'           => '',
		);
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_generate_secret' ) );
	}

	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return $all[ $key ] ?? $default;
	}

	public static function update( array $values ) {
		$all = self::get_all();
		foreach ( $values as $k => $v ) {
			$all[ $k ] = $v;
		}
		update_option( self::OPTION_KEY, $all );
	}

	public static function maybe_generate_secret() {
		if ( '' === (string) self::get( 'cron_secret' ) ) {
			self::regenerate_secret();
		}
	}

	public static function regenerate_secret() {
		$secret = bin2hex( random_bytes( 16 ) );
		self::update( array( 'cron_secret' => $secret ) );
		return $secret;
	}

	public static function llm_providers() {
		return array(
			'yandex'     => 'Yandex Foundation Models',
			'deepseek'   => 'DeepSeek напрямую',
			'qwen'       => 'Qwen (Alibaba DashScope)',
			'timeweb'    => 'Timeweb (TWC AI API)',
			'openrouter' => 'OpenRouter',
		);
	}

	/** Every key that belongs to the LLM block — what "import keys" copies. */
	public static function llm_keys() {
		return array(
			'llm_provider',
			'yandex_api_key',
			'yandex_folder_id',
			'yandex_model',
			'deepseek_api_key',
			'deepseek_model',
			'qwen_api_key',
			'qwen_model',
			'qwen_base_url',
			'timeweb_agent_id',
			'timeweb_token',
			'timeweb_proxy_source',
			'openrouter_api_key',
			'openrouter_model',
		);
	}

	/**
	 * OpenAI-compatible provider presets — endpoint + auth + model resolution.
	 * Identical to LAA_Settings::llm_provider_config(), kept per-plugin on
	 * purpose: each plugin of the network must work on its own.
	 */
	public static function llm_provider_config( $provider = null ) {
		$s        = self::get_all();
		$provider = $provider && 'inherit' !== $provider ? $provider : $s['llm_provider'];

		switch ( $provider ) {
			case 'yandex':
				return array(
					'provider' => 'yandex',
					'endpoint' => 'https://ai.api.cloud.yandex.net/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Api-Key ' . $s['yandex_api_key'],
						'x-folder-id'   => $s['yandex_folder_id'],
					),
					'model'    => 'gpt://' . $s['yandex_folder_id'] . '/' . ltrim( $s['yandex_model'], '/' ),
				);
			case 'timeweb':
				return array(
					'provider' => 'timeweb',
					'endpoint' => 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/' . rawurlencode( $s['timeweb_agent_id'] ) . '/v1/chat/completions',
					'headers'  => array(
						'Authorization'  => 'Bearer ' . $s['timeweb_token'],
						'x-proxy-source' => $s['timeweb_proxy_source'],
					),
					'model'    => 'gpt-4', // ignored by Timeweb — the agent's own model is used.
				);
			case 'openrouter':
				return array(
					'provider' => 'openrouter',
					'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['openrouter_api_key'],
					),
					'model'    => $s['openrouter_model'],
				);
			case 'qwen':
				return array(
					'provider' => 'qwen',
					'endpoint' => rtrim( $s['qwen_base_url'], '/' ) . '/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['qwen_api_key'],
					),
					'model'    => $s['qwen_model'],
				);
			case 'deepseek':
			default:
				return array(
					'provider' => 'deepseek',
					'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['deepseek_api_key'],
					),
					'model'    => $s['deepseek_model'],
				);
		}
	}

	/** True when the configured LLM provider actually has its credentials filled in. */
	public static function llm_ready( $provider = null ) {
		$s        = self::get_all();
		$provider = $provider && 'inherit' !== $provider ? $provider : $s['llm_provider'];

		switch ( $provider ) {
			case 'yandex':
				return '' !== $s['yandex_api_key'] && '' !== $s['yandex_folder_id'];
			case 'timeweb':
				return '' !== $s['timeweb_agent_id'] && '' !== $s['timeweb_token'];
			case 'openrouter':
				return '' !== $s['openrouter_api_key'];
			case 'qwen':
				return '' !== $s['qwen_api_key'];
			default:
				return '' !== $s['deepseek_api_key'];
		}
	}

	/**
	 * Which SEO plugin's meta to read {seo_title}/{seo_description} from.
	 * 'auto' picks whichever is active, so the same feed config works across
	 * sites of the network that run different SEO plugins.
	 */
	public static function seo_plugin() {
		$value = (string) self::get( 'seo_plugin', 'auto' );
		if ( 'auto' !== $value ) {
			return $value;
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		return 'none';
	}
}
