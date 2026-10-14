<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pin title + description written by the model, for {llm_title} and
 * {llm_description}.
 *
 * Never called while Pinterest waits for the feed: a feed of 25 items would
 * be 25 sequential LLM calls. The background tick (WPPF_Scheduler) fills the
 * cache, the feed only reads it, and until an answer exists the template
 * chain simply falls through to the next placeholder ({seo_description},
 * {excerpt}, ...).
 *
 * Cache: one meta array per post, keyed by a hash of the prompt-relevant
 * feed settings, with the post's modified time stored next to the answer —
 * editing the post or the feed's prompt makes the entry stale on its own.
 */
class WPPF_LLM_Text {

	const META        = '_wppf_llm';
	const MAX_ENTRIES = 6;

	const SYSTEM_PROMPT = 'Ты — SMM-редактор, пишешь тексты для пинов Pinterest. Pinterest — поисковая система по картинкам: заголовок и описание должны содержать слова, которые человек введёт в поиск, и при этом читаться естественно. Не используй хэштеги (их добавят отдельно), не используй кавычки-ёлочки вокруг всего текста, не выдумывай факты, которых нет в исходных данных. Ответ — строго JSON-объект {"title": "...", "description": "..."} без пояснений и без markdown.';

	public static function enabled( array $config ) {
		return ! empty( $config['llm_enabled'] ) && WPPF_Settings::llm_ready( $config['llm_provider'] );
	}

	public static function config_hash( array $config ) {
		return WPPF_Feed_Config::hash( $config, array( 'llm_provider', 'llm_language', 'llm_prompt', 'llm_keywords', 'title_max', 'description_max' ) );
	}

	/** @return array{title:string,description:string}|null */
	public static function cached( WP_Post $post, array $config ) {
		if ( empty( $config['llm_enabled'] ) ) {
			return null;
		}

		$all  = self::all( $post->ID );
		$hash = self::config_hash( $config );
		if ( empty( $all[ $hash ] ) || ( $all[ $hash ]['modified'] ?? '' ) !== $post->post_modified_gmt ) {
			return null;
		}
		return array(
			'title'       => (string) $all[ $hash ]['title'],
			'description' => (string) $all[ $hash ]['description'],
		);
	}

	public static function needs_generation( WP_Post $post, array $config ) {
		return self::enabled( $config ) && null === self::cached( $post, $config ) && ! self::recently_failed( $post->ID, $config );
	}

	/**
	 * Calls the model and stores the answer.
	 *
	 * @return array{title:string,description:string}|null
	 */
	public static function generate( WP_Post $post, array $config ) {
		$client = new WPPF_LLM_Client( $config['llm_provider'] );
		$data   = $client->json( self::SYSTEM_PROMPT, self::user_prompt( $post, $config ), 800, 0.7 );

		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['description'] ) ) {
			$error = $client->last_error() ? $client->last_error() : 'в ответе нет title/description';
			WPPF_Log::error( 'LLM: запись #' . $post->ID . ' («' . get_the_title( $post ) . '»): ' . $error );
			self::mark_failed( $post->ID, $config );
			return null;
		}

		$answer = array(
			'title'       => WPPF_Template::truncate( WPPF_Template::plain( (string) $data['title'] ), (int) $config['title_max'] ),
			'description' => WPPF_Template::plain( (string) $data['description'] ),
		);

		$all                                = self::all( $post->ID );
		$all[ self::config_hash( $config ) ] = array_merge(
			$answer,
			array(
				'modified' => $post->post_modified_gmt,
				'at'       => time(),
				'provider' => $client->provider(),
			)
		);
		// Oldest first out: feeds come and go, the meta must not grow forever.
		uasort( $all, function ( $a, $b ) {
			return (int) ( $b['at'] ?? 0 ) - (int) ( $a['at'] ?? 0 );
		} );
		$all = array_slice( $all, 0, self::MAX_ENTRIES, true );

		update_post_meta( $post->ID, self::META, wp_slash( $all ) );
		return $answer;
	}

	private static function user_prompt( WP_Post $post, array $config ) {
		$ctx = array(
			'post'   => $post,
			'config' => $config,
			'llm'    => null,
		);

		$title_max = max( 30, (int) $config['title_max'] );
		// Leave room for the hashtag line most description templates append.
		$desc_max  = max( 100, (int) $config['description_max'] - 120 );

		$facts = array(
			'Заголовок записи'  => get_the_title( $post ),
			'Краткое описание'  => WPPF_Template::resolve_chain( 'seo_description|short_description|excerpt', $ctx ),
			'Рубрики и метки'   => implode( ', ', WPPF_Template::term_names( $post, '*', 12 ) ),
			'Начало текста'     => WPPF_Template::truncate( WPPF_Template::content_text( $post ), 1500 ),
		);
		if ( $config['term'] ) {
			$facts['Тема доски'] = $config['term']->name;
		} else {
			$facts['Тема доски'] = $config['feed_title'];
		}

		$lines = array();
		foreach ( $facts as $label => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$lines[] = $label . ': ' . $value;
			}
		}

		$rules = array(
			'Язык: ' . ( $config['llm_language'] ? $config['llm_language'] : 'русский' ) . '.',
			'title — до ' . $title_max . ' символов, конкретно о пользе или сути, без кликбейта.',
			'description — 2–3 предложения, до ' . $desc_max . ' символов: что это, кому полезно, что человек найдёт по ссылке.',
		);

		$keywords = WPPF_Template::render( (string) $config['llm_keywords'], $ctx );
		if ( '' !== $keywords ) {
			$rules[] = 'Если уместно, используй ключевые слова: ' . $keywords . '.';
		}
		if ( '' !== trim( (string) $config['llm_prompt'] ) ) {
			$rules[] = trim( (string) $config['llm_prompt'] );
		}

		return "Исходные данные:\n" . implode( "\n", $lines ) . "\n\nТребования:\n- " . implode( "\n- ", $rules );
	}

	private static function all( $post_id ) {
		$all = get_post_meta( $post_id, self::META, true );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * A post the model keeps failing on (bad key, refusal, garbage) must not
	 * eat the per-tick budget forever: back off for a few hours.
	 */
	private static function failed_key( $post_id, array $config ) {
		return 'wppf_llm_fail_' . $post_id . '_' . self::config_hash( $config );
	}

	private static function mark_failed( $post_id, array $config ) {
		set_transient( self::failed_key( $post_id, $config ), 1, 6 * HOUR_IN_SECONDS );
	}

	private static function recently_failed( $post_id, array $config ) {
		return (bool) get_transient( self::failed_key( $post_id, $config ) );
	}
}
