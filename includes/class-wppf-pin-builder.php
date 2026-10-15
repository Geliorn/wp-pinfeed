<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One post + one feed config -> one pin: title, description, link, image.
 *
 * Composed images are plain files in uploads/wppf-pins/<post_id>/, named
 * <feed_key>-<hash>.<ext>, where the hash covers everything that changes
 * the picture (preset, caption, footer, source picture). A changed title or
 * preset therefore produces a new file on its own, with no cache to clear —
 * and since Pinterest never re-fetches the image of an existing pin, keeping
 * the URL stable for unchanged input is what matters. Not attachments on
 * purpose: hundreds of generated pins don't belong in the media library.
 */
class WPPF_Pin_Builder {

	/**
	 * Part of every pin's file hash. Bump it when the drawing code changes in
	 * a way that should replace already rendered pins.
	 */
	const RENDER_VERSION = 3;

	/** Images that may still be rendered synchronously in this request. */
	private static $sync_budget = null;

	/** While Pinterest waits, never download a remote picture — leave it to the tick. */
	private static $defer_downloads = false;

	public static function init() {
		add_action( 'before_delete_post', array( __CLASS__, 'delete_post_files' ) );
	}

	public static function base_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'wppf-pins/';
	}

	public static function base_url() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . 'wppf-pins/';
	}

	public static function set_sync_budget( $n ) {
		self::$sync_budget = max( 0, (int) $n );
	}

	public static function set_defer_downloads( $defer ) {
		self::$defer_downloads = (bool) $defer;
	}

	/**
	 * @param WP_Post $post
	 * @param array   $config
	 * @param bool    $allow_render Render a missing pin now (subject to the sync budget).
	 * @return array{status:string,title:string,description:string,link:string,image:array|null,image_state:string,notes:array}
	 *         status: ready | skip — a pin without a finished image never goes
	 *         into the feed: Pinterest creates a pin once and never re-reads it.
	 */
	public static function build( WP_Post $post, array $config, $allow_render = true ) {
		$ctx = array(
			'post'   => $post,
			'config' => $config,
			'llm'    => WPPF_LLM_Text::cached( $post, $config ),
		);

		$pin = array(
			'status'      => 'ready',
			'title'       => WPPF_Template::truncate( self::one_line( WPPF_Template::render( $config['title_template'], $ctx ) ), (int) $config['title_max'] ),
			'description' => self::fit_description( WPPF_Template::render( $config['description_template'], $ctx ), (int) $config['description_max'] ),
			'link'        => self::link( $post, $config, $ctx ),
			'image'       => null,
			'image_state' => 'none',
			'rendered'    => false,
			'notes'       => array(),
		);

		if ( '' === $pin['title'] ) {
			$pin['title'] = WPPF_Template::truncate( get_the_title( $post ), (int) $config['title_max'] );
		}

		$image = self::image( $post, $config, $ctx, $allow_render );
		$pin['image']       = $image['image'];
		$pin['image_state'] = $image['state'];
		$pin['rendered']    = ! empty( $image['rendered'] );
		$pin['notes']       = $image['notes'];

		if ( 'ready' !== $image['state'] || ! $pin['image'] ) {
			$pin['status'] = 'skip';
		}

		/**
		 * Last word on one pin.
		 *
		 * @param array   $pin
		 * @param WP_Post $post
		 * @param array   $config
		 */
		return (array) apply_filters( 'wppf_pin', $pin, $post, $config );
	}

	// ---------------------------------------------------------------- text

	private static function one_line( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Truncates the body but keeps a trailing hashtag paragraph intact:
	 * cutting "#MCP #AIАгенты" in half is worse than a shorter sentence.
	 */
	public static function fit_description( $text, $max ) {
		$text = trim( (string) $text );
		if ( $max < 1 || mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$paragraphs = preg_split( "/\n\n+/u", $text );
		$last       = trim( (string) end( $paragraphs ) );
		if ( count( $paragraphs ) > 1 && preg_match( '/^(#\S+\s*)+$/u', $last ) && mb_strlen( $last ) < $max / 2 ) {
			array_pop( $paragraphs );
			$body = WPPF_Template::truncate( implode( "\n\n", $paragraphs ), $max - mb_strlen( $last ) - 2 );
			return $body . "\n\n" . $last;
		}

		return WPPF_Template::truncate( $text, $max );
	}

	private static function link( WP_Post $post, array $config, array $ctx ) {
		$url = get_permalink( $post );
		if ( ! $config['utm_enabled'] ) {
			return $url;
		}

		$params = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $key ) {
			$value = self::one_line( WPPF_Template::render( (string) $config[ $key ], $ctx ) );
			if ( '' !== $value ) {
				$params[ $key ] = rawurlencode( $value );
			}
		}
		return $params ? add_query_arg( $params, $url ) : $url;
	}

	// --------------------------------------------------------------- image

	/**
	 * @return array{state:string,image:array|null,notes:array}
	 *         state: ready | pending (the model or the background render hasn't
	 *         finished yet) | none (no picture and nothing to generate from)
	 */
	private static function image( WP_Post $post, array $config, array $ctx, $allow_render ) {
		$notes  = array();
		$pick   = WPPF_Image_Resolver::find( $post, $config );
		$labels = WPPF_Image_Resolver::sources();

		foreach ( $pick['skipped'] as $skip ) {
			$notes[] = 'пропущено горизонтальное: ' . ( $labels[ $skip['source'] ]['label'] ?? $skip['source'] );
		}

		if ( $pick['found'] ) {
			$found   = $pick['found'];
			$notes[] = 'изображение: ' . ( $labels[ $found['source'] ]['label'] ?? $found['source'] );
			return array( 'state' => 'ready', 'image' => self::describe( $found ), 'notes' => $notes );
		}

		if ( 'skip' === $config['image_missing'] ) {
			$notes[] = 'изображения нет, генерация выключена — запись не попадёт в ленту';
			return array( 'state' => 'none', 'image' => null, 'notes' => $notes );
		}

		if ( ! WPPF_Image_Composer::text_available() ) {
			$notes[] = 'GD с FreeType недоступен — инфографику не собрать';
			return array( 'state' => 'none', 'image' => null, 'notes' => $notes );
		}

		$ig = WPPF_Infographic::points_for( $post, $config );
		if ( 'pending' === $ig['state'] ) {
			$notes[] = 'тезисы для инфографики ещё пишет модель — фоновая задача';
			return array( 'state' => 'pending', 'image' => null, 'notes' => $notes );
		}
		if ( 'none' === $ig['state'] ) {
			$notes[] = $ig['reason'];
			return array( 'state' => 'none', 'image' => null, 'notes' => $notes );
		}

		// A skipped horizontal picture can still be the infographic's background.
		$background = $pick['skipped'] ? $pick['skipped'][0] : null;
		$plan       = self::render_plan( $post, $config, $ctx, $background, $ig['points'] );
		$source     = 'manual' === $ig['source'] ? 'тезисы из блока «Pinterest»' : 'тезисы от модели';

		if ( is_readable( $plan['path'] ) ) {
			$notes[] = 'инфографика (' . $source . ')';
			return array( 'state' => 'ready', 'image' => self::describe_file( $plan ), 'notes' => $notes );
		}

		$can_render = $allow_render && ( null === self::$sync_budget || self::$sync_budget > 0 );
		if ( $can_render && self::$defer_downloads && $plan['needs_source'] && ! WPPF_Image_Resolver::has_local_copy( $background ) ) {
			$can_render = false;
		}
		if ( $can_render ) {
			if ( null !== self::$sync_budget ) {
				self::$sync_budget--;
			}
			$result = self::render( $plan, $background );
			if ( $result['ok'] ) {
				if ( '' !== $result['error'] ) {
					$notes[] = $result['error'];
				}
				$notes[] = 'инфографика собрана сейчас (' . $source . ')';
				return array( 'state' => 'ready', 'image' => self::describe_file( $plan ), 'notes' => $notes, 'rendered' => true );
			}
			$notes[] = 'ошибка сборки: ' . $result['error'];
			WPPF_Log::error( 'Пин для записи #' . $post->ID . ' (' . $config['feed_key'] . '): ' . $result['error'] );
			return array( 'state' => 'none', 'image' => null, 'notes' => $notes );
		}

		$notes[] = 'инфографика ещё не собрана — фоновая задача';
		return array( 'state' => 'pending', 'image' => null, 'notes' => $notes );
	}

	/**
	 * Where the generated infographic lives and what goes into it.
	 *
	 * @param array|null $background A skipped horizontal picture, for background_mode=source.
	 * @param array      $points     Infographic points.
	 */
	public static function render_plan( WP_Post $post, array $config, array $ctx, $background, array $points ) {
		$preset       = WPPF_Infographic_Presets::get( $config['infographic_preset'] );
		$title        = self::one_line( WPPF_Template::render( $config['infographic_title'], $ctx ) );
		$footer       = self::one_line( WPPF_Template::render( $config['footer_template'], $ctx ) );
		$needs_source = $background && 'source' === $preset['background_mode'];

		$hash = substr(
			md5(
				wp_json_encode(
					array(
						self::RENDER_VERSION,
						$preset,
						$title,
						$footer,
						$points,
						$needs_source ? array( $background['attachment_id'], $background['url'] ) : null,
						$needs_source && $background['attachment_id'] ? (string) get_post_modified_time( 'U', true, $background['attachment_id'] ) : '',
					)
				)
			),
			0,
			12
		);

		$ext  = WPPF_Image_Composer::extension_for( $preset['format'] );
		$name = sanitize_file_name( $config['feed_key'] ) . '-' . $hash . '.' . $ext;

		return array(
			'post_id'      => (int) $post->ID,
			'feed_key'     => $config['feed_key'],
			'preset'       => $preset,
			'title'        => $title,
			'footer'       => $footer,
			'points'       => $points,
			'needs_source' => $needs_source,
			'path'         => self::base_dir() . $post->ID . '/' . $name,
			'url'          => self::base_url() . $post->ID . '/' . $name,
		);
	}

	public static function render( array $plan, $background ) {
		// No background picture is no reason to fail: the solid colour stays.
		$source = $plan['needs_source'] ? WPPF_Image_Resolver::local_path( $background ) : '';
		$fit    = WPPF_Infographic::render_fitting( $plan['preset'], $plan['points'], $plan['title'], $plan['footer'], $source ? $source : null, $plan['path'] );
		if ( $fit['ok'] ) {
			self::delete_stale( $plan );
		}
		return array(
			'ok'    => $fit['ok'],
			'error' => $fit['ok'] ? implode( '; ', $fit['notes'] ) : $fit['error'],
		);
	}

	/** Older renders of the same post in the same feed. */
	private static function delete_stale( array $plan ) {
		$prefix = sanitize_file_name( $plan['feed_key'] ) . '-';
		foreach ( (array) glob( dirname( $plan['path'] ) . '/' . $prefix . '*' ) as $file ) {
			if ( $file && $file !== $plan['path'] && 0 === strpos( basename( $file ), $prefix ) ) {
				@unlink( $file ); // phpcs:ignore
			}
		}
	}

	public static function delete_post_files( $post_id ) {
		$dir = self::base_dir() . (int) $post_id;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( $file && is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	private static function describe( array $found ) {
		$mime = '';
		$size = 0;
		if ( $found['attachment_id'] ) {
			$mime = (string) get_post_mime_type( $found['attachment_id'] );
			$path = get_attached_file( $found['attachment_id'] );
			$size = $path && is_readable( $path ) ? (int) filesize( $path ) : 0;
		}
		if ( '' === $mime ) {
			$type = wp_check_filetype( (string) wp_parse_url( $found['url'], PHP_URL_PATH ) );
			$mime = $type['type'] ? $type['type'] : 'image/jpeg';
		}
		return array(
			'url'    => $found['url'],
			'width'  => (int) $found['width'],
			'height' => (int) $found['height'],
			'mime'   => $mime,
			'length' => $size,
			'source' => $found['source'] ?? '',
		);
	}

	private static function describe_file( array $plan ) {
		$type = wp_check_filetype( $plan['path'] );
		return array(
			'url'    => $plan['url'],
			'width'  => (int) $plan['preset']['width'],
			'height' => (int) $plan['preset']['height'],
			'mime'   => $type['type'] ? $type['type'] : 'image/jpeg',
			'length' => is_readable( $plan['path'] ) ? (int) filesize( $plan['path'] ) : 0,
			'source' => 'generated',
		);
	}
}
