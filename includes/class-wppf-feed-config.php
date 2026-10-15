<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed settings. The fields table is the single source of truth — the
 * metabox, the save handler, the auto-feed settings page and get() all read
 * it, so adding a knob is one line here plus one row of markup.
 *
 * Two kinds of feed share this shape:
 *  - a `pinfeed` post (meta `_wppf_<key>`), served at /pinfeed/<slug>/;
 *  - the automatic feed of a term (one option for all of them), served at
 *    /pinfeed/term/<taxonomy>/<term-slug>/. Its own term is added as an
 *    extra taxonomy rule on top of whatever rules the option holds.
 */
class WPPF_Feed_Config {

	const PREFIX      = '_wppf_';
	const AUTO_OPTION = 'wppf_auto_feed';
	const NONCE       = 'wppf_feed_nonce';

	/** key => array(type, default) */
	public static function fields() {
		return array(
			'enabled'               => array( 'bool', 1 ),

			// -------------------------------------------------- what goes in
			'post_types'            => array( 'list', array( 'post' ) ),
			// [{taxonomy, terms (slugs, comma-separated), operator IN|NOT IN|AND, children}]
			'tax_rules'             => array( 'rules', array() ),
			'tax_relation'          => array( 'text', 'AND' ),
			'orderby'               => array( 'text', 'date' ), // date | modified | rand | meta_num | comment_count
			'order_meta_key'        => array( 'text', '' ),
			'limit'                 => array( 'int', 25 ),
			'max_age_days'          => array( 'int', 0 ),   // 0 = no limit
			'min_age_minutes'       => array( 'int', 0 ),   // delay before a fresh post enters the feed
			'exclude_ids'           => array( 'text', '' ),

			// ------------------------------------------------------- channel
			'channel_title'         => array( 'text', '{feed_title}' ),
			'channel_description'   => array( 'text', '{site_description}' ),

			// ---------------------------------------------------------- text
			'title_template'        => array( 'text', '{pin_title|llm_title|seo_title|title}' ),
			'description_template'  => array( 'textarea', "{pin_description|llm_description|seo_description|short_description|excerpt|content:400}\n\n{hashtags:post_tag:5}" ),
			'title_max'             => array( 'int', 100 ),  // Pinterest limit
			'description_max'       => array( 'int', 500 ),  // Pinterest limit
			'description_html_img'  => array( 'bool', 0 ),   // also put <img> into <description>

			// ----------------------------------------------------------- LLM
			'llm_enabled'           => array( 'bool', 0 ),
			'llm_provider'          => array( 'text', 'inherit' ),
			'llm_language'          => array( 'text', 'русский' ),
			'llm_prompt'            => array( 'textarea', '' ),
			'llm_keywords'          => array( 'text', '' ),

			// ---------------------------------------------------------- link
			'utm_enabled'           => array( 'bool', 1 ),
			'utm_source'            => array( 'text', 'pinterest' ),
			'utm_medium'            => array( 'text', 'social' ),
			'utm_campaign'          => array( 'text', '{feed_slug}' ),

			// --------------------------------------------------------- image
			// Ordered chain, first hit wins. See WPPF_Image_Resolver::sources().
			'image_sources'         => array( 'list', array( 'pin_meta', 'gh_pin', 'thumbnail', 'content' ) ),
			'image_meta_key'        => array( 'text', '' ),       // for the "meta" source: attachment ID or URL
			'image_horizontal'      => array( 'text', 'skip' ),   // use | skip — wider-than-tall pictures
			'image_missing'         => array( 'text', 'generate' ), // generate | skip — no picture found
			'infographic_preset'    => array( 'text', 'ig-list' ),
			'infographic_points'    => array( 'int', 5 ),
			'infographic_title'     => array( 'text', '{pin_title|llm_title|title}' ),
			'footer_template'       => array( 'text', '{site_domain}' ),
		);
	}

	/** Runtime counters — written by the router, never by the form. */
	public static function runtime_keys() {
		return array( 'last_fetch_at', 'last_fetch_ua', 'fetch_count' );
	}

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . WPPF_CPT::FEED, array( __CLASS__, 'save_feed' ), 10, 2 );
	}

	// ------------------------------------------------------------- reading

	public static function defaults() {
		$out = array();
		foreach ( self::fields() as $key => $spec ) {
			$out[ $key ] = $spec[1];
		}
		return $out;
	}

	/** Typed, defaulted settings of a `pinfeed` post — everything downstream reads this. */
	public static function get( $feed_id ) {
		$post = get_post( $feed_id );
		if ( ! $post || WPPF_CPT::FEED !== $post->post_type ) {
			return null;
		}

		$raw = array();
		foreach ( self::fields() as $key => $spec ) {
			$value = get_post_meta( $post->ID, self::PREFIX . $key, true );
			if ( '' !== $value && null !== $value && false !== $value ) {
				$raw[ $key ] = $value;
			}
		}

		$config                = self::normalise( $raw );
		$config['kind']        = 'feed';
		$config['feed_id']     = (int) $post->ID;
		$config['feed_key']    = 'feed-' . $post->ID;
		$config['feed_slug']   = $post->post_name ? $post->post_name : 'feed-' . $post->ID;
		$config['feed_title']  = get_the_title( $post );
		$config['published']  = 'publish' === $post->post_status;
		$config['term']        = null;

		return $config;
	}

	/** Settings of the automatic feed of one term, or null when there is none. */
	public static function get_auto( $taxonomy, $term ) {
		if ( ! WPPF_Settings::get( 'auto_feeds_enabled' ) ) {
			return null;
		}
		if ( ! in_array( $taxonomy, (array) WPPF_Settings::get( 'auto_feeds_taxonomies', array() ), true ) ) {
			return null;
		}

		$term = $term instanceof WP_Term ? $term : get_term_by( 'slug', (string) $term, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		if ( (int) $term->count < max( 1, (int) WPPF_Settings::get( 'auto_feeds_min_count', 1 ) ) ) {
			return null;
		}

		$config = self::normalise( self::get_auto_raw() );

		// A per-term feed only makes sense for the post types the taxonomy is
		// attached to; intersect, and fall back to the taxonomy's own types.
		$tax_obj = get_taxonomy( $taxonomy );
		$types   = $tax_obj ? (array) $tax_obj->object_type : array();
		$both    = array_values( array_intersect( $config['post_types'], $types ) );
		$config['post_types'] = $both ? $both : $types;

		array_unshift(
			$config['tax_rules'],
			array(
				'taxonomy' => $taxonomy,
				'terms'    => $term->slug,
				'operator' => 'IN',
				'children' => 1,
			)
		);
		// The term rule must always hold, whatever relation the extra rules use.
		$config['tax_relation'] = 'AND';

		$config['kind']       = 'auto';
		$config['feed_id']    = 0;
		$config['feed_key']   = 'term-' . $term->term_id;
		$config['feed_slug']  = $taxonomy . '-' . $term->slug;
		$config['feed_title'] = $term->name;
		$config['published']  = true;
		$config['term']       = $term;

		return $config;
	}

	public static function get_auto_raw() {
		$stored = get_option( self::AUTO_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/** Casts raw stored values to their declared types, filling in defaults. */
	public static function normalise( array $raw ) {
		$raw = self::migrate( $raw );
		$out = array();

		foreach ( self::fields() as $key => $spec ) {
			list( $type, $default ) = $spec;

			if ( ! array_key_exists( $key, $raw ) ) {
				$out[ $key ] = $default;
				continue;
			}

			$value = $raw[ $key ];
			switch ( $type ) {
				case 'int':
					$out[ $key ] = (int) $value;
					break;
				case 'bool':
					$out[ $key ] = (bool) (int) $value;
					break;
				case 'list':
					$out[ $key ] = self::to_list( $value );
					break;
				case 'rules':
					$out[ $key ] = self::to_rules( $value );
					break;
				default:
					$out[ $key ] = (string) $value;
			}
		}

		$out['limit']        = max( 1, min( 200, $out['limit'] ) );
		// Only sources that still exist (and, for "gh_pin", whose plugin is present).
		$out['image_sources']    = array_values( array_intersect( $out['image_sources'], array_keys( WPPF_Image_Resolver::sources() ) ) );
		$out['image_horizontal'] = 'use' === $out['image_horizontal'] ? 'use' : 'skip';
		$out['image_missing']    = 'skip' === $out['image_missing'] ? 'skip' : 'generate';
		$out['tax_relation'] = 'OR' === strtoupper( $out['tax_relation'] ) ? 'OR' : 'AND';
		if ( empty( $out['post_types'] ) ) {
			$out['post_types'] = array( 'post' );
		}

		return $out;
	}

	/**
	 * Feeds saved by 0.1–0.2 had a different image block: a "generate_mode"
	 * (none | not_vertical | always), term/default picture sources and the
	 * repository avatar as the "meta" source. Map them onto the current
	 * fields once, on read, so old feeds keep working without a re-save.
	 */
	private static function migrate( array $raw ) {
		if ( isset( $raw['generate_mode'] ) && ! isset( $raw['image_horizontal'] ) ) {
			$mode                    = (string) $raw['generate_mode'];
			$raw['image_horizontal'] = 'none' === $mode ? 'use' : 'skip';
			$raw['image_missing']    = 'none' === $mode ? 'skip' : 'generate';
		}
		if ( isset( $raw['image_sources'] ) ) {
			$sources = self::to_list( $raw['image_sources'] );
			// The avatar is part of the site design, never a pin.
			if ( '_gh_owner_avatar' === ( $raw['image_meta_key'] ?? '' ) ) {
				$sources              = array_diff( $sources, array( 'meta' ) );
				$raw['image_meta_key'] = '';
				if ( ! in_array( 'gh_pin', $sources, true ) ) {
					array_splice( $sources, (int) array_search( 'pin_meta', $sources, true ) + 1, 0, array( 'gh_pin' ) );
				}
			}
			$raw['image_sources'] = array_values( array_diff( $sources, array( 'term', 'default' ) ) );
		}
		return $raw;
	}

	private static function to_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		$value = array_map( 'trim', array_map( 'strval', (array) $value ) );
		return array_values( array_unique( array_filter( $value, 'strlen' ) ) );
	}

	private static function to_rules( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}

		$out = array();
		foreach ( (array) $value as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['taxonomy'] ) ) {
				continue;
			}
			$operator = strtoupper( (string) ( $rule['operator'] ?? 'IN' ) );
			$out[]    = array(
				'taxonomy' => sanitize_key( $rule['taxonomy'] ),
				'terms'    => (string) ( $rule['terms'] ?? '' ),
				'operator' => in_array( $operator, array( 'IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS' ), true ) ? $operator : 'IN',
				'children' => empty( $rule['children'] ) ? 0 : 1,
			);
		}
		return $out;
	}

	/**
	 * Hash of everything that changes the *text* or *image* of a pin, so the
	 * caches (LLM answers, rendered images) can tell a stale entry from a
	 * fresh one without being cleared by hand.
	 */
	public static function hash( array $config, array $keys ) {
		$subset = array();
		foreach ( $keys as $key ) {
			$subset[ $key ] = $config[ $key ] ?? null;
		}
		return substr( md5( wp_json_encode( $subset ) ), 0, 12 );
	}

	// ------------------------------------------------------------- writing

	/** Sanitises posted form values (`$input` = already unslashed $_POST['wppf']) into the stored shape. */
	public static function sanitize_input( array $input ) {
		$out = array();

		foreach ( self::fields() as $key => $spec ) {
			list( $type ) = $spec;
			$value        = $input[ $key ] ?? null;

			switch ( $type ) {
				case 'bool':
					$out[ $key ] = empty( $value ) ? 0 : 1;
					break;
				case 'int':
					$out[ $key ] = (int) $value;
					break;
				case 'list':
					$list        = self::to_list( is_array( $value ) ? $value : (string) $value );
					$out[ $key ] = array_map( 'sanitize_key', $list );
					break;
				case 'rules':
					$rules = array();
					foreach ( (array) $value as $rule ) {
						if ( ! is_array( $rule ) || empty( $rule['taxonomy'] ) ) {
							continue;
						}
						$operator = (string) ( $rule['operator'] ?? 'IN' );
						$terms    = sanitize_text_field( (string) ( $rule['terms'] ?? '' ) );
						if ( '' === $terms && ! in_array( $operator, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
							continue;
						}
						$rules[] = array(
							'taxonomy' => sanitize_key( $rule['taxonomy'] ),
							'terms'    => $terms,
							'operator' => $operator,
							'children' => empty( $rule['children'] ) ? 0 : 1,
						);
					}
					$out[ $key ] = self::to_rules( $rules );
					break;
				case 'textarea':
					$out[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				default:
					$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		// The order of the image chain comes from a separate hidden field the
		// sortable list maintains; checkboxes alone lose it.
		if ( isset( $input['image_sources_order'] ) ) {
			$order   = self::to_list( sanitize_text_field( (string) $input['image_sources_order'] ) );
			$checked = $out['image_sources'];
			$out['image_sources'] = array_values( array_filter( $order, function ( $s ) use ( $checked ) {
				return in_array( $s, $checked, true );
			} ) );
		}

		return $out;
	}

	public static function add_meta_boxes() {
		add_meta_box( 'wppf_feed_url', 'Адрес ленты', array( __CLASS__, 'render_url_box' ), WPPF_CPT::FEED, 'side', 'high' );
		add_meta_box( 'wppf_feed_settings', 'Настройки ленты', array( __CLASS__, 'render_settings_box' ), WPPF_CPT::FEED, 'normal', 'high' );
	}

	public static function render_url_box( $post ) {
		if ( 'publish' !== $post->post_status ) {
			echo '<p>Опубликуйте ленту — после этого у неё появится адрес. Ссылка на черновик отдаёт 404, чтобы Pinterest не подхватил недонастроенную ленту.</p>';
			return;
		}
		$url = WPPF_Router::feed_url( $post->ID );
		echo '<p><input type="text" class="widefat code" readonly value="' . esc_attr( $url ) . '" onclick="this.select()"></p>';
		echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Открыть XML</a> · ';
		echo '<a href="' . esc_url( WPPF_Router::preview_url( $url ) ) . '" target="_blank" rel="noopener">Предпросмотр пинов</a></p>';
		echo '<p class="description">Pinterest → Settings → Bulk create Pins → Auto-publish → Connect RSS feed. Одна лента = одна доска.</p>';

		$config = WPPF_Feed_Config::get( $post->ID );
		if ( $config ) {
			$count = WPPF_Query::count( $config );
			echo '<p><strong>Подходит записей сейчас:</strong> ' . (int) $count . '</p>';
		}

		$last = (int) get_post_meta( $post->ID, self::PREFIX . 'last_fetch_at', true );
		if ( $last ) {
			echo '<p><strong>Последнее обращение:</strong> ' . esc_html( wp_date( 'd.m.Y H:i', $last ) ) . '<br><span class="description">' . esc_html( (string) get_post_meta( $post->ID, self::PREFIX . 'last_fetch_ua', true ) ) . '</span></p>';
		}
	}

	public static function render_settings_box( $post ) {
		wp_nonce_field( 'wppf_save_feed', self::NONCE );

		$raw = array();
		foreach ( self::fields() as $key => $spec ) {
			$value = get_post_meta( $post->ID, self::PREFIX . $key, true );
			if ( '' !== $value && null !== $value && false !== $value ) {
				$raw[ $key ] = $value;
			}
		}

		$is_new = 'auto-draft' === $post->post_status;
		self::render_form( self::normalise( $raw ), 'feed', $is_new );
	}

	public static function save_feed( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'wppf_save_feed' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input  = isset( $_POST['wppf'] ) && is_array( $_POST['wppf'] ) ? wp_unslash( $_POST['wppf'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$values = self::sanitize_input( $input );

		$profile = isset( $input['apply_profile'] ) ? sanitize_key( $input['apply_profile'] ) : '';
		if ( '' !== $profile ) {
			$values = array_merge( $values, WPPF_Integrations::profile( $profile ) );
		}

		// update_post_meta() unslashes its value; slash it, or a "\" in a template is lost.
		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, self::PREFIX . $key, wp_slash( $value ) );
		}

		WPPF_Feed_Renderer::flush_cache( 'feed-' . $post_id );
	}

	/** Saves the shared config of the automatic per-term feeds. */
	public static function save_auto( array $input ) {
		$values  = self::sanitize_input( $input );
		$profile = isset( $input['apply_profile'] ) ? sanitize_key( $input['apply_profile'] ) : '';
		if ( '' !== $profile ) {
			$values = array_merge( $values, WPPF_Integrations::profile( $profile ) );
		}
		update_option( self::AUTO_OPTION, $values, false );
		WPPF_Feed_Renderer::flush_cache();
	}

	// ---------------------------------------------------------------- form

	/**
	 * The one form for both a feed post and the auto-feed settings page.
	 *
	 * @param array  $c       Normalised config.
	 * @param string $context feed | auto
	 * @param bool   $is_new  Show the profile picker prominently.
	 */
	public static function render_form( array $c, $context, $is_new = false ) {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$profiles   = WPPF_Integrations::profiles();

		echo '<div class="wppf-form">';

		// ---- profile
		if ( $profiles ) {
			echo '<div class="wppf-section wppf-profile' . ( $is_new ? ' is-new' : '' ) . '"><h3>Быстрый старт</h3>';
			echo '<p><select name="wppf[apply_profile]"><option value="">— не применять профиль —</option>';
			foreach ( $profiles as $key => $profile ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $profile['label'] ) . '</option>';
			}
			echo '</select></p><p class="description">Профиль перезапишет типы записей, шаблоны текста и цепочку картинок готовыми значениями под плагин сети — при сохранении. Остальное можно поправить после.</p></div>';
		}

		// ---- what goes in
		echo '<div class="wppf-section"><h3>Что попадает в ленту</h3><table class="form-table">';

		if ( 'feed' === $context ) {
			self::row_checkbox( 'enabled', 'Лента включена', $c['enabled'], 'Выключенная лента отдаёт 404 — Pinterest перестанет её забирать, но доска сохранится.' );
		}

		echo '<tr><th>Типы записей</th><td>';
		foreach ( $post_types as $type ) {
			$checked = in_array( $type->name, $c['post_types'], true );
			echo '<label class="wppf-inline"><input type="checkbox" name="wppf[post_types][]" value="' . esc_attr( $type->name ) . '"' . checked( $checked, true, false ) . '> ' . esc_html( $type->labels->singular_name ) . ' <code>' . esc_html( $type->name ) . '</code></label>';
		}
		if ( 'auto' === $context ) {
			echo '<p class="description">Для ленты термина берётся пересечение с типами, к которым привязана его таксономия.</p>';
		}
		echo '</td></tr>';

		echo '<tr><th>Условия по таксономиям</th><td>';
		if ( 'auto' === $context ) {
			echo '<p class="description">Условие «запись в этом термине» добавляется автоматически. Здесь — дополнительные фильтры для всех автолент (например, исключить рубрику «Новости»).</p>';
		}
		echo '<table class="wppf-rules widefat striped"><thead><tr><th>Таксономия</th><th>Условие</th><th>Термины (слаги через запятую)</th><th>С дочерними</th><th></th></tr></thead><tbody>';
		$rules   = $c['tax_rules'];
		$rules[] = array(
			'taxonomy' => '',
			'terms'    => '',
			'operator' => 'IN',
			'children' => 1,
		);
		foreach ( $rules as $i => $rule ) {
			self::rule_row( $i, $rule, $taxonomies );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button wppf-add-rule">+ Условие</button></p>';
		echo '<p><label>Связь условий: <select name="wppf[tax_relation]">';
		foreach ( array( 'AND' => 'И — все условия сразу', 'OR' => 'ИЛИ — любое из условий' ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $c['tax_relation'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p class="description">«Любой из» = IN, «Ни один из» = NOT IN, «Все сразу» = AND, «Есть любой термин» / «Нет терминов» не требуют списка. Слаги, а не ID — конфиг переносим между сайтами сети.</p>';
		echo '</td></tr>';

		echo '<tr><th>Сортировка</th><td><select name="wppf[orderby]">';
		foreach ( array(
			'date'          => 'Сначала новые (дата публикации)',
			'modified'      => 'Сначала обновлённые',
			'rand'          => 'Случайно',
			'meta_num'      => 'По числовому мета-полю (по убыванию)',
			'comment_count' => 'По числу комментариев',
		) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $c['orderby'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <input type="text" name="wppf[order_meta_key]" value="' . esc_attr( $c['order_meta_key'] ) . '" placeholder="мета-ключ, напр. _gh_stars" class="regular-text"></td></tr>';

		self::row_number( 'limit', 'Записей в ленте', $c['limit'], 'Pinterest забирает ленту примерно раз в сутки и создаёт пины только из новых элементов. 25–50 достаточно.' );
		self::row_number( 'max_age_days', 'Не старше, дней', $c['max_age_days'], '0 — без ограничения.' );
		self::row_number( 'min_age_minutes', 'Задержка публикации, минут', $c['min_age_minutes'], 'Свежая запись попадёт в ленту не раньше чем через N минут — успеют проставиться обложка и SEO-поля, а фоновая задача сгенерирует картинку и текст.' );
		self::row_text( 'exclude_ids', 'Исключить ID записей', $c['exclude_ids'], 'Через запятую. Плюс у любой записи есть флажок «Не публиковать в Pinterest».' );
		echo '</table></div>';

		// ---- text
		echo '<div class="wppf-section"><h3>Текст пина</h3><table class="form-table">';
		self::row_text( 'channel_title', 'Заголовок канала', $c['channel_title'] );
		self::row_text( 'channel_description', 'Описание канала', $c['channel_description'] );
		self::row_text( 'title_template', 'Заголовок пина', $c['title_template'], '', 'large-text code' );
		self::row_textarea( 'description_template', 'Описание пина', $c['description_template'] );
		self::row_number( 'title_max', 'Заголовок, макс. символов', $c['title_max'], 'Pinterest: до 100.' );
		self::row_number( 'description_max', 'Описание, макс. символов', $c['description_max'], 'Pinterest: до 500. Обрезка — по границе слова, с многоточием.' );
		self::row_checkbox( 'description_html_img', '&lt;img&gt; в описании', $c['description_html_img'], 'Картинка всегда отдаётся в &lt;enclosure&gt; и &lt;media:content&gt;. Некоторые агрегаторы смотрят только в описание — тогда включите.' );
		echo '<tr><th>Подстановки</th><td>' . WPPF_Template::help_html() . '</td></tr>'; // phpcs:ignore
		echo '</table></div>';

		// ---- LLM
		echo '<div class="wppf-section"><h3>Генерация текста (LLM)</h3><table class="form-table">';
		self::row_checkbox( 'llm_enabled', 'Генерировать текст пина', $c['llm_enabled'], 'Модель пишет {llm_title} и {llm_description} по заголовку, описанию и тексту записи. Ответ кэшируется в мете записи и обновляется только при правке записи или промпта. Генерация идёт в фоне — до готовности сработает следующий вариант цепочки.' );
		echo '<tr><th>Провайдер</th><td><select name="wppf[llm_provider]"><option value="inherit"' . selected( $c['llm_provider'], 'inherit', false ) . '>Как в настройках плагина</option>';
		foreach ( WPPF_Settings::llm_providers() as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $c['llm_provider'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		if ( ! WPPF_Settings::llm_ready() ) {
			echo ' <span class="wppf-warn">Ключи провайдера не заполнены — см. «Настройки».</span>';
		}
		echo '</td></tr>';
		self::row_text( 'llm_language', 'Язык пина', $c['llm_language'] );
		self::row_text( 'llm_keywords', 'Ключевые слова доски', $c['llm_keywords'], 'Через запятую. Модель вплетёт подходящие в описание — поиск Pinterest работает по тексту пина.' );
		self::row_textarea( 'llm_prompt', 'Дополнительные требования', $c['llm_prompt'], 'Например: «без эмодзи», «обращение на ты», «упоминать, что инструмент бесплатный».' );
		echo '</table></div>';

		// ---- link
		echo '<div class="wppf-section"><h3>Ссылка</h3><table class="form-table">';
		self::row_checkbox( 'utm_enabled', 'Добавлять UTM-метки', $c['utm_enabled'] );
		self::row_text( 'utm_source', 'utm_source', $c['utm_source'] );
		self::row_text( 'utm_medium', 'utm_medium', $c['utm_medium'] );
		self::row_text( 'utm_campaign', 'utm_campaign', $c['utm_campaign'], 'Подстановки работают: {feed_slug}, {term_slug}, {post_type}.' );
		echo '</table></div>';

		// ---- image
		echo '<div class="wppf-section"><h3>Картинка пина</h3>';
		echo '<p class="wppf-flow">Порядок: <strong>1)</strong> источники сверху вниз — берётся первое найденное изображение → <strong>2)</strong> горизонтальное изображение берётся или пропускается → <strong>3)</strong> если изображения нет — плагин генерирует инфографику (тезисы пишет модель) или запись не попадает в ленту.</p>';
		echo '<table class="form-table">';

		echo '<tr><th>1. Источники изображения</th><td>';
		echo '<ul class="wppf-sortable">';
		$all_sources = WPPF_Image_Resolver::sources();
		$order       = array_merge( $c['image_sources'], array_diff( array_keys( $all_sources ), $c['image_sources'] ) );
		foreach ( $order as $source ) {
			if ( ! isset( $all_sources[ $source ] ) ) {
				continue;
			}
			$checked = in_array( $source, $c['image_sources'], true );
			echo '<li data-source="' . esc_attr( $source ) . '"><span class="dashicons dashicons-menu"></span><label><input type="checkbox" name="wppf[image_sources][]" value="' . esc_attr( $source ) . '"' . checked( $checked, true, false ) . '> <strong>' . esc_html( $all_sources[ $source ]['label'] ) . '</strong><br><span class="description">' . esc_html( $all_sources[ $source ]['help'] ) . '</span></label>';
			if ( 'meta' === $source ) {
				echo '<br><input type="text" class="regular-text code" name="wppf[image_meta_key]" value="' . esc_attr( $c['image_meta_key'] ) . '" placeholder="мета-ключ">';
			}
			echo '</li>';
		}
		echo '</ul><input type="hidden" name="wppf[image_sources_order]" class="wppf-sortable-order" value="' . esc_attr( implode( ',', $order ) ) . '">';
		echo '<p class="description">Перетаскивайте строки, чтобы поменять порядок; снимите флажок, чтобы источник не использовался.</p></td></tr>';

		echo '<tr><th>2. Горизонтальные изображения</th><td>';
		foreach ( array(
			'skip' => 'Пропускать — искать в следующем источнике, иначе генерировать',
			'use'  => 'Брать как есть',
		) as $v => $l ) {
			echo '<label class="wppf-block"><input type="radio" name="wppf[image_horizontal]" value="' . esc_attr( $v ) . '"' . checked( $c['image_horizontal'], $v, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '<p class="description">Горизонтальное — шире, чем высота (например, обложка 1200×800). Pinterest лучше показывает вертикальные 2:3. Изображение из блока «Pinterest» записи берётся всегда — его выбрали вручную.</p></td></tr>';

		echo '<tr><th>3. Если изображения нет</th><td>';
		foreach ( array(
			'generate' => 'Сгенерировать инфографику',
			'skip'     => 'Не включать запись в ленту',
		) as $v => $l ) {
			echo '<label class="wppf-block"><input type="radio" name="wppf[image_missing]" value="' . esc_attr( $v ) . '"' . checked( $c['image_missing'], $v, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '<p class="description">Инфографика: заголовок и нумерованные тезисы. Тезисы — из блока «Pinterest» записи, иначе их пишет модель по тексту записи (в фоне, ключи — в «Настройках»). Пока модель не ответила, если она недоступна или текста в записи мало — запись в ленту не попадает.';
		if ( ! WPPF_Settings::llm_ready() ) {
			echo ' <span class="wppf-warn">Ключи модели не заполнены — генерация не сработает.</span>';
		}
		echo '</p></td></tr>';

		echo '<tr><th>Пресет инфографики</th><td><select name="wppf[infographic_preset]">';
		foreach ( WPPF_Infographic_Presets::all() as $id => $preset ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $c['infographic_preset'], $id, false ) . '>' . esc_html( $preset['name'] . ' (' . WPPF_Infographic_Presets::layouts()[ $preset['layout'] ] . ')' ) . '</option>';
		}
		echo '</select> <a href="' . esc_url( admin_url( 'admin.php?page=' . WPPF_Admin::PRESETS_SLUG ) ) . '">Пресеты</a></td></tr>';
		self::row_number( 'infographic_points', 'Тезисов', $c['infographic_points'], '3–8. На 2:3 с телефона хорошо читаются 5.' );
		self::row_text( 'infographic_title', 'Заголовок инфографики', $c['infographic_title'], 'Те же подстановки, что для текста пина.' );
		self::row_text( 'footer_template', 'Подпись внизу', $c['footer_template'], 'Обычно домен сайта. Пусто — без подписи.' );
		echo '</table></div>';

		echo '</div>';
	}

	private static function rule_row( $i, array $rule, array $taxonomies ) {
		$name = 'wppf[tax_rules][' . (int) $i . ']';
		echo '<tr class="wppf-rule">';
		echo '<td><select name="' . esc_attr( $name ) . '[taxonomy]"><option value="">—</option>';
		foreach ( $taxonomies as $tax ) {
			echo '<option value="' . esc_attr( $tax->name ) . '"' . selected( $rule['taxonomy'], $tax->name, false ) . '>' . esc_html( $tax->labels->singular_name . ' (' . $tax->name . ')' ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><select name="' . esc_attr( $name ) . '[operator]">';
		foreach ( array(
			'IN'         => 'Любой из',
			'NOT IN'     => 'Ни один из',
			'AND'        => 'Все сразу',
			'EXISTS'     => 'Есть любой термин',
			'NOT EXISTS' => 'Нет терминов',
		) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $rule['operator'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input type="text" class="widefat" name="' . esc_attr( $name ) . '[terms]" value="' . esc_attr( $rule['terms'] ) . '" placeholder="mcp, ai-agent"></td>';
		echo '<td><input type="checkbox" name="' . esc_attr( $name ) . '[children]" value="1"' . checked( ! empty( $rule['children'] ), true, false ) . '></td>';
		echo '<td><button type="button" class="button-link wppf-remove-rule" aria-label="Удалить условие">✕</button></td>';
		echo '</tr>';
	}

	// ------------------------------------------------------- field helpers

	private static function row_text( $key, $label, $value, $help = '', $class = 'regular-text' ) {
		echo '<tr><th><label for="wppf-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" id="wppf-' . esc_attr( $key ) . '" class="' . esc_attr( $class ) . '" name="wppf[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function row_textarea( $key, $label, $value, $help = '' ) {
		echo '<tr><th><label for="wppf-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="wppf-' . esc_attr( $key ) . '" class="large-text code" rows="4" name="wppf[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function row_number( $key, $label, $value, $help = '' ) {
		echo '<tr><th><label for="wppf-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" id="wppf-' . esc_attr( $key ) . '" class="small-text" name="wppf[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '">';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function row_checkbox( $key, $label, $value, $help = '' ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="wppf[' . esc_attr( $key ) . ']" value="1"' . checked( (bool) $value, true, false ) . '> Да</label>';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Attachment picker: hidden ID + preview + buttons, wired up in admin.js. */
	public static function media_field( $name, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$thumb         = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
		echo '<div class="wppf-media">';
		echo '<input type="hidden" class="wppf-media-id" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $attachment_id ) . '">';
		echo '<img class="wppf-media-preview" src="' . esc_url( (string) $thumb ) . '" alt=""' . ( $thumb ? '' : ' style="display:none"' ) . '>';
		echo '<button type="button" class="button wppf-media-pick">Выбрать</button> ';
		echo '<button type="button" class="button-link wppf-media-clear"' . ( $attachment_id ? '' : ' style="display:none"' ) . '>Убрать</button>';
		echo '</div>';
	}
}
