<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the picture of a pin by walking the feed's ordered source chain.
 * The first hit wins; it is either served as is or used as the background
 * of a composed pin (WPPF_Pin_Builder decides which).
 */
class WPPF_Image_Resolver {

	/** A picture at least this much taller than wide counts as "already vertical". */
	const VERTICAL_RATIO = 1.25;

	public static function sources() {
		$sources = array(
			'pin_meta'  => 'Картинка Pinterest у записи (метабокс) — всегда как есть',
			'thumbnail' => 'Миниатюра записи (featured image)',
			'term'      => 'Картинка термина («Изображение для Pinterest»)',
			'content'   => 'Первая картинка в тексте записи',
			'meta'      => 'Мета-поле записи (ID вложения или URL)',
			'default'   => 'Картинка по умолчанию этой ленты',
		);

		/**
		 * Extra image sources: key => label. Resolve them on wppf_image_source.
		 *
		 * @param array $sources
		 */
		return (array) apply_filters( 'wppf_image_sources', $sources );
	}

	/**
	 * @return array{source:string,attachment_id:int,url:string,width:int,height:int}|null
	 */
	public static function find( WP_Post $post, array $config ) {
		foreach ( $config['image_sources'] as $source ) {
			$found = self::from_source( $source, $post, $config );
			if ( $found && '' !== $found['url'] ) {
				$found['source'] = $source;
				return $found;
			}
		}
		return null;
	}

	private static function from_source( $source, WP_Post $post, array $config ) {
		switch ( $source ) {
			case 'pin_meta':
				return self::attachment( (int) get_post_meta( $post->ID, WPPF_Post_Meta::IMAGE, true ) );

			case 'thumbnail':
				return self::attachment( (int) get_post_thumbnail_id( $post ) );

			case 'term':
				return self::attachment( self::term_image_id( $post, $config ) );

			case 'content':
				return self::from_content( $post );

			case 'meta':
				if ( '' === $config['image_meta_key'] ) {
					return null;
				}
				$value = get_post_meta( $post->ID, $config['image_meta_key'], true );
				if ( is_numeric( $value ) ) {
					return self::attachment( (int) $value );
				}
				return self::url( (string) $value );

			case 'default':
				return self::attachment( (int) $config['default_image_id'] );
		}

		/**
		 * Custom image source registered by a filter on wppf_image_sources.
		 *
		 * @param array|null $found  array(attachment_id, url, width, height) or null
		 * @param string     $source
		 * @param WP_Post    $post
		 * @param array      $config
		 */
		return apply_filters( 'wppf_image_source', null, $source, $post, $config );
	}

	/**
	 * The feed's own term first (auto feeds), then the post's terms in the
	 * chosen taxonomies — deepest term first, so "MCP" beats its parent "AI".
	 */
	public static function term_image_id( WP_Post $post, array $config ) {
		if ( ! empty( $config['term'] ) ) {
			$id = WPPF_Term_Image::get( $config['term']->term_id );
			if ( $id ) {
				return $id;
			}
		}

		$taxonomies = $config['image_term_taxonomies'] ? $config['image_term_taxonomies'] : get_object_taxonomies( $post->post_type );
		$taxonomies = array_intersect( $taxonomies, WPPF_Settings::term_image_taxonomies() );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}
			usort( $terms, function ( $a, $b ) {
				return count( get_ancestors( $b->term_id, $b->taxonomy, 'taxonomy' ) ) - count( get_ancestors( $a->term_id, $a->taxonomy, 'taxonomy' ) );
			} );
			foreach ( $terms as $term ) {
				$id = WPPF_Term_Image::get( $term->term_id );
				if ( $id ) {
					return $id;
				}
				// Inherit from the nearest ancestor that has one.
				foreach ( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) as $ancestor ) {
					$id = WPPF_Term_Image::get( $ancestor );
					if ( $id ) {
						return $id;
					}
				}
			}
		}
		return 0;
	}

	private static function from_content( WP_Post $post ) {
		$content = (string) $post->post_content;

		// Block editor and classic both tag library images with wp-image-ID.
		if ( preg_match( '/wp-image-(\d+)/', $content, $m ) ) {
			$found = self::attachment( (int) $m[1] );
			if ( $found ) {
				return $found;
			}
		}

		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			$url = html_entity_decode( $m[1] );
			$id  = attachment_url_to_postid( preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $url ) );
			return $id ? self::attachment( $id ) : self::url( $url );
		}

		return null;
	}

	/** Full-size data of a library image, or null. */
	public static function attachment( $attachment_id ) {
		if ( $attachment_id < 1 || ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! $src ) {
			return null;
		}
		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) $src[0],
			'width'         => (int) $src[1],
			'height'        => (int) $src[2],
		);
	}

	/** An external URL (e.g. a GitHub avatar). Size unknown until downloaded. */
	public static function url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return null;
		}
		return array(
			'attachment_id' => 0,
			'url'           => $url,
			'width'         => 0,
			'height'        => 0,
		);
	}

	public static function is_vertical( array $found ) {
		if ( $found['width'] < 1 || $found['height'] < 1 ) {
			return false;
		}
		return $found['height'] / $found['width'] >= self::VERTICAL_RATIO;
	}

	private static function cache_path( array $found ) {
		return WPPF_Pin_Builder::base_dir() . 'src/' . md5( $found['url'] ) . '.img';
	}

	/** Already downloaded (or a library file) — rendering won't touch the network. */
	public static function has_local_copy( array $found ) {
		if ( $found['attachment_id'] ) {
			return true;
		}
		$path = self::cache_path( $found );
		return is_readable( $path ) && filesize( $path ) > 0;
	}

	/**
	 * Local path of the picture, for GD. Library images are read from disk;
	 * external ones are downloaded once into uploads/wppf-pins/src/ (keyed by
	 * URL) so a re-render doesn't hit the remote host again.
	 */
	public static function local_path( array $found ) {
		if ( $found['attachment_id'] ) {
			$path = get_attached_file( $found['attachment_id'] );
			return $path && is_readable( $path ) ? $path : '';
		}

		$path = self::cache_path( $found );
		$dir  = dirname( $path );
		if ( is_readable( $path ) && filesize( $path ) > 0 ) {
			return $path;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $found['url'], 15 );
		if ( is_wp_error( $tmp ) ) {
			WPPF_Log::error( 'Не удалось скачать картинку ' . $found['url'] . ': ' . $tmp->get_error_message() );
			return '';
		}

		// Only keep it if it's actually an image GD can open.
		$info = @getimagesize( $tmp ); // phpcs:ignore
		if ( ! $info ) {
			@unlink( $tmp ); // phpcs:ignore
			return '';
		}

		wp_mkdir_p( $dir );
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore
			@copy( $tmp, $path ); // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
		}
		return is_readable( $path ) ? $path : '';
	}
}
