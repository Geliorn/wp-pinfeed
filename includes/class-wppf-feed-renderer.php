<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed config -> RSS 2.0 XML that Pinterest's "Auto-publish from RSS" reads:
 * one <item> per post, with the image in <enclosure> and <media:content>
 * (Pinterest takes either), plain-text <description> and a stable <guid>.
 *
 * The XML is cached per feed in a transient. Any post save bumps a global
 * cache version, so a feed never serves a deleted or unpublished post for
 * longer than one request; a feed with images still pending is not cached
 * at all, so the next fetch picks up the finished pins.
 */
class WPPF_Feed_Renderer {

	const CACHE_VERSION = 'wppf_cache_ver';

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_post_change' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_change' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'flush_cache_quiet' ) );
		add_action( 'edited_term', array( __CLASS__, 'flush_cache_quiet' ) );
	}

	public static function on_post_change( $post_id, $post = null ) {
		$type = $post ? $post->post_type : get_post_type( $post_id );
		if ( 'revision' === $type || 'nav_menu_item' === $type ) {
			return;
		}
		self::flush_cache();
	}

	public static function flush_cache_quiet() {
		self::flush_cache();
	}

	/** Without a key — every feed (one integer bump, no transient scan). */
	public static function flush_cache( $feed_key = null ) {
		if ( null === $feed_key ) {
			update_option( self::CACHE_VERSION, (int) get_option( self::CACHE_VERSION, 0 ) + 1, true );
			return;
		}
		delete_transient( self::cache_key( $feed_key ) );
	}

	private static function cache_key( $feed_key ) {
		return 'wppf_xml_' . md5( $feed_key . '|' . (int) get_option( self::CACHE_VERSION, 0 ) );
	}

	/** Builds (or takes from cache) the XML of one feed. */
	public static function xml( array $config, $self_url ) {
		$minutes = (int) WPPF_Settings::get( 'feed_cache_minutes', 30 );
		$key     = self::cache_key( $config['feed_key'] );

		if ( $minutes > 0 ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		WPPF_Pin_Builder::set_sync_budget( (int) WPPF_Settings::get( 'sync_render_limit', 3 ) );
		WPPF_Pin_Builder::set_defer_downloads( true );

		$items   = array();
		$pending = false;
		foreach ( WPPF_Query::posts( $config ) as $post ) {
			$pin = WPPF_Pin_Builder::build( $post, $config, true );
			if ( 'pending' === $pin['image_state'] ) {
				$pending = true;
			}
			if ( 'skip' === $pin['status'] ) {
				continue;
			}
			$items[] = array( $post, $pin );
		}

		$xml = self::to_xml( $config, $items, $self_url );

		if ( $minutes > 0 && ! $pending ) {
			set_transient( $key, $xml, $minutes * MINUTE_IN_SECONDS );
		}
		return $xml;
	}

	private static function to_xml( array $config, array $items, $self_url ) {
		$ctx = array(
			'post'   => null,
			'config' => $config,
			'llm'    => null,
		);

		$title       = WPPF_Template::render( $config['channel_title'], $ctx );
		$description = WPPF_Template::render( $config['channel_description'], $ctx );
		$link        = home_url( '/' );
		if ( ! empty( $config['term'] ) ) {
			$term_link = get_term_link( $config['term'] );
			if ( ! is_wp_error( $term_link ) ) {
				$link = $term_link;
			}
		}

		$last = 0;
		foreach ( $items as $item ) {
			$last = max( $last, (int) get_post_time( 'U', true, $item[0] ) );
		}

		$out   = array();
		$out[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$out[] = '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/" xmlns:dc="http://purl.org/dc/elements/1.1/">';
		$out[] = '<channel>';
		$out[] = '<title>' . self::esc( '' !== $title ? $title : get_bloginfo( 'name' ) ) . '</title>';
		$out[] = '<link>' . self::esc( $link ) . '</link>';
		$out[] = '<description>' . self::esc( $description ) . '</description>';
		$out[] = '<atom:link href="' . self::esc( $self_url ) . '" rel="self" type="application/rss+xml"/>';
		$out[] = '<language>' . self::esc( str_replace( '_', '-', get_locale() ) ) . '</language>';
		$out[] = '<lastBuildDate>' . gmdate( DATE_RSS, $last ? $last : time() ) . '</lastBuildDate>';
		$out[] = '<generator>WP PinFeed ' . self::esc( WPPF_VERSION ) . '</generator>';
		$out[] = '<ttl>' . max( 60, (int) WPPF_Settings::get( 'feed_cache_minutes', 30 ) ) . '</ttl>';

		foreach ( $items as $item ) {
			$out[] = self::item_xml( $item[0], $item[1], $config );
		}

		$out[] = '</channel>';
		$out[] = '</rss>';

		return implode( "\n", $out ) . "\n";
	}

	private static function item_xml( WP_Post $post, array $pin, array $config ) {
		$out   = array();
		$out[] = '<item>';
		$out[] = '<title>' . self::esc( $pin['title'] ) . '</title>';
		$out[] = '<link>' . self::esc( $pin['link'] ) . '</link>';
		// Stable across UTM/template changes: Pinterest must not see an
		// edited feed as a batch of new posts and pin them all again.
		$out[] = '<guid isPermaLink="false">' . self::esc( home_url( '/?p=' . $post->ID ) ) . '</guid>';
		$out[] = '<pubDate>' . gmdate( DATE_RSS, (int) get_post_time( 'U', true, $post ) ) . '</pubDate>';
		$out[] = '<dc:creator>' . self::cdata( get_the_author_meta( 'display_name', (int) $post->post_author ) ) . '</dc:creator>';

		$description = $pin['description'];
		if ( $config['description_html_img'] && $pin['image'] ) {
			$description = '<img src="' . esc_url( $pin['image']['url'] ) . '" alt="' . esc_attr( $pin['title'] ) . '" /><br />' . nl2br( esc_html( $description ) );
		}
		$out[] = '<description>' . self::cdata( $description ) . '</description>';

		foreach ( WPPF_Template::term_names( $post, '*', 10 ) as $name ) {
			$out[] = '<category>' . self::cdata( $name ) . '</category>';
		}

		if ( $pin['image'] ) {
			$img   = $pin['image'];
			$out[] = '<enclosure url="' . self::esc( $img['url'] ) . '" length="' . (int) $img['length'] . '" type="' . self::esc( $img['mime'] ) . '"/>';
			$media = '<media:content url="' . self::esc( $img['url'] ) . '" medium="image" type="' . self::esc( $img['mime'] ) . '"';
			if ( $img['width'] && $img['height'] ) {
				$media .= ' width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '"';
			}
			$out[] = $media . '>';
			$out[] = '<media:title type="plain">' . self::cdata( $pin['title'] ) . '</media:title>';
			$out[] = '</media:content>';
		}

		$out[] = '</item>';
		return implode( "\n", $out );
	}

	private static function esc( $text ) {
		return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private static function cdata( $text ) {
		// Drop characters XML 1.0 forbids — a stray control char from a
		// pasted README makes the whole feed unparseable.
		$text = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $text );
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', (string) $text ) . ']]>';
	}

	// -------------------------------------------------------------- preview

	/** Human-readable table of what the feed would serve — for editors only. */
	public static function preview_html( array $config, $feed_url ) {
		WPPF_Pin_Builder::set_sync_budget( (int) WPPF_Settings::get( 'sync_render_limit', 3 ) );

		$rows = '';
		foreach ( WPPF_Query::posts( $config ) as $post ) {
			$pin    = WPPF_Pin_Builder::build( $post, $config, true );
			$img    = $pin['image'] ? '<img src="' . esc_url( $pin['image']['url'] ) . '" alt="" loading="lazy">' : '<div class="noimg">нет картинки</div>';
			$status = array(
				'ready'   => '<span class="ok">в ленте</span>',
				'pending' => '<span class="wait">в ленте, пин ещё собирается</span>',
				'skip'    => '<span class="skip">не попадёт в ленту</span>',
			);
			$rows .= '<article class="pin">' . $img . '<div class="body">';
			$rows .= '<h2>' . esc_html( $pin['title'] ) . '</h2>';
			$rows .= '<p>' . nl2br( esc_html( $pin['description'] ) ) . '</p>';
			$rows .= '<p class="meta">' . ( $status[ $pin['status'] ] ?? '' ) . ' · ' . mb_strlen( $pin['title'] ) . ' / ' . mb_strlen( $pin['description'] ) . ' симв. · <a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">запись #' . (int) $post->ID . '</a></p>';
			$rows .= '<p class="meta"><a href="' . esc_url( $pin['link'] ) . '">' . esc_html( $pin['link'] ) . '</a></p>';
			if ( $pin['notes'] ) {
				$rows .= '<p class="meta">' . esc_html( implode( '; ', $pin['notes'] ) ) . '</p>';
			}
			$rows .= '</div></article>';
		}
		if ( '' === $rows ) {
			$rows = '<p>Под условия ленты сейчас не подходит ни одной записи.</p>';
		}

		return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Предпросмотр: ' . esc_html( $config['feed_title'] ) . '</title><style>'
			. 'body{font:15px/1.45 -apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:24px;background:#f3f4f6;color:#111827}'
			. 'header{margin-bottom:20px}code{background:#e5e7eb;padding:2px 6px;border-radius:4px}'
			. '.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px}'
			. '.pin{background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
			. '.pin img{display:block;width:100%;height:auto}.noimg{aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:#e5e7eb;color:#6b7280}'
			. '.body{padding:12px 14px}h2{font-size:16px;margin:0 0 6px}.body p{margin:0 0 8px;font-size:13px}.meta{color:#6b7280;word-break:break-all}'
			. '.ok{color:#047857}.wait{color:#b45309}.skip{color:#b91c1c}'
			. '</style></head><body><header><h1>' . esc_html( $config['feed_title'] ) . '</h1><p>Лента: <code>' . esc_html( $feed_url ) . '</code></p></header><div class="grid">' . $rows . '</div></body></html>';
	}
}
