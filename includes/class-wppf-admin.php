<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens: overview, automatic feeds, image presets (with a live
 * preview), settings and the log. The feeds themselves are the `pinfeed`
 * post type, listed under the same menu. All writes go through
 * admin-post.php with a nonce and manage_options.
 */
class WPPF_Admin {

	const MAIN_SLUG     = 'wppf-main';
	const AUTO_SLUG     = 'wppf-auto';
	const PRESETS_SLUG  = 'wppf-presets';
	const SETTINGS_SLUG = 'wppf-settings';
	const LOG_SLUG      = 'wppf-log';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		foreach ( array( 'save_auto', 'save_preset', 'delete_preset', 'import_presets', 'export_presets', 'save_settings', 'import_llm', 'test_llm', 'regen_secret', 'run_tick', 'clear_log', 'upload_font' ) as $action ) {
			add_action( 'admin_post_wppf_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'wp_ajax_wppf_preview_preset', array( __CLASS__, 'ajax_preview_preset' ) );

		add_filter( 'manage_' . WPPF_CPT::FEED . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . WPPF_CPT::FEED . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function menu() {
		add_menu_page( 'Pinterest-ленты', 'Pinterest', 'edit_posts', self::MAIN_SLUG, array( __CLASS__, 'page_overview' ), 'dashicons-rss', 58 );
		add_submenu_page( self::MAIN_SLUG, 'Обзор', 'Обзор', 'edit_posts', self::MAIN_SLUG, array( __CLASS__, 'page_overview' ) );
		// The `pinfeed` list and "add new" are attached by show_in_menu.
		add_submenu_page( self::MAIN_SLUG, 'Автоленты терминов', 'Автоленты', 'manage_options', self::AUTO_SLUG, array( __CLASS__, 'page_auto' ) );
		add_submenu_page( self::MAIN_SLUG, 'Пресеты картинок', 'Пресеты картинок', 'manage_options', self::PRESETS_SLUG, array( __CLASS__, 'page_presets' ) );
		add_submenu_page( self::MAIN_SLUG, 'Настройки', 'Настройки', 'manage_options', self::SETTINGS_SLUG, array( __CLASS__, 'page_settings' ) );
		add_submenu_page( self::MAIN_SLUG, 'Журнал', 'Журнал', 'manage_options', self::LOG_SLUG, array( __CLASS__, 'page_log' ) );
	}

	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$ours    = false !== strpos( (string) $hook, 'wppf-' ) || ( $screen && WPPF_CPT::FEED === $screen->post_type );
		$editing = $screen && in_array( $screen->base, array( 'post', 'term', 'edit-tags' ), true );
		if ( ! $ours && ! $editing ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'wppf-admin', WPPF_PLUGIN_URL . 'assets/css/admin.css', array(), WPPF_VERSION );
		wp_enqueue_script( 'wppf-admin', WPPF_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), WPPF_VERSION, true );
		wp_localize_script(
			'wppf-admin',
			'wppfAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'previewNonce' => wp_create_nonce( 'wppf_preview_preset' ),
			)
		);
	}

	// ----------------------------------------------------------- feed list

	public static function columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['wppf_url']   = 'Адрес ленты';
				$out['wppf_types'] = 'Что внутри';
				$out['wppf_fetch'] = 'Pinterest забирал';
			}
		}
		unset( $out['date'] );
		return $out;
	}

	public static function column( $column, $post_id ) {
		$config = WPPF_Feed_Config::get( $post_id );
		if ( ! $config ) {
			return;
		}

		switch ( $column ) {
			case 'wppf_url':
				if ( ! $config['published'] ) {
					echo '<em>черновик</em>';
					break;
				}
				$url = WPPF_Router::feed_url( $post_id );
				echo '<code>' . esc_html( $url ) . '</code>';
				echo '<div class="row-actions"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">XML</a> | <a href="' . esc_url( WPPF_Router::preview_url( $url ) ) . '" target="_blank" rel="noopener">Предпросмотр</a></div>';
				if ( ! $config['enabled'] ) {
					echo '<span class="wppf-warn">выключена</span>';
				}
				break;
			case 'wppf_types':
				echo esc_html( implode( ', ', $config['post_types'] ) );
				foreach ( $config['tax_rules'] as $rule ) {
					echo '<br><small>' . esc_html( $rule['taxonomy'] . ' ' . $rule['operator'] . ' ' . $rule['terms'] ) . '</small>';
				}
				break;
			case 'wppf_fetch':
				$last = (int) get_post_meta( $post_id, WPPF_Feed_Config::PREFIX . 'last_fetch_at', true );
				echo $last ? esc_html( wp_date( 'd.m.Y H:i', $last ) ) . '<br><small>всего: ' . (int) get_post_meta( $post_id, WPPF_Feed_Config::PREFIX . 'fetch_count', true ) . '</small>' : '—';
				break;
		}
	}

	// -------------------------------------------------------------- pages

	private static function notice() {
		$messages = array(
			'saved'        => 'Сохранено.',
			'deleted'      => 'Удалено.',
			'imported'     => 'Импортировано.',
			'nothing'      => 'Нечего импортировать.',
			'secret'       => 'Новый секрет сгенерирован — обновите команды во внешнем кроне.',
			'tick'         => 'Тик выполнен: ',
			'cleared'      => 'Журнал очищен.',
			'font'         => 'Шрифт загружен.',
			'font_error'   => 'Шрифт не загружен: нужен файл .ttf или .otf.',
			'llm_ok'       => 'Модель ответила: ',
			'llm_error'    => 'Ошибка LLM: ',
			'import_error' => 'Не удалось прочитать JSON с пресетами.',
		);
		$key = isset( $_GET['wppf_msg'] ) ? sanitize_key( $_GET['wppf_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		$extra = isset( $_GET['wppf_extra'] ) ? sanitize_text_field( wp_unslash( $_GET['wppf_extra'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$class = in_array( $key, array( 'llm_error', 'font_error', 'import_error', 'nothing' ), true ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $key ] . $extra ) . '</p></div>';
	}

	private static function redirect( $page, $msg, array $extra = array() ) {
		$args = array_merge(
			array(
				'page'     => $page,
				'wppf_msg' => $msg,
			),
			$extra
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав' );
		}
		check_admin_referer( $action );
	}

	public static function page_overview() {
		echo '<div class="wrap wppf-wrap"><h1>Pinterest-ленты</h1>';
		self::notice();

		echo '<p>Каждая лента — RSS для одной доски Pinterest: <em>Settings → Bulk create Pins → Auto-publish → Connect RSS feed</em>. Pinterest забирает ленту примерно раз в сутки и создаёт пины из новых элементов (до 200 в день на аккаунт).</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . WPPF_CPT::FEED ) ) . '">Добавить ленту</a> <a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . WPPF_CPT::FEED ) ) . '">Все ленты</a></p>';

		$feeds = get_posts(
			array(
				'post_type'      => WPPF_CPT::FEED,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
			)
		);

		echo '<h2>Ленты</h2>';
		if ( ! $feeds ) {
			echo '<p>Лент пока нет.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Лента</th><th>Адрес</th><th>Записей</th><th>Pinterest забирал</th></tr></thead><tbody>';
			foreach ( $feeds as $feed ) {
				$config = WPPF_Feed_Config::get( $feed->ID );
				$url    = $config['published'] ? WPPF_Router::feed_url( $feed->ID ) : '';
				$last   = (int) get_post_meta( $feed->ID, WPPF_Feed_Config::PREFIX . 'last_fetch_at', true );
				echo '<tr><td><a href="' . esc_url( get_edit_post_link( $feed->ID ) ) . '"><strong>' . esc_html( get_the_title( $feed ) ) . '</strong></a>' . ( $config['enabled'] ? '' : ' <span class="wppf-warn">выключена</span>' ) . '</td>';
				echo '<td>' . ( $url ? '<code>' . esc_html( $url ) . '</code> <a href="' . esc_url( WPPF_Router::preview_url( $url ) ) . '" target="_blank" rel="noopener">предпросмотр</a>' : '<em>черновик</em>' ) . '</td>';
				echo '<td>' . (int) WPPF_Query::count( $config ) . '</td>';
				echo '<td>' . ( $last ? esc_html( wp_date( 'd.m.Y H:i', $last ) ) : '—' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>Автоленты терминов</h2>';
		if ( WPPF_Settings::get( 'auto_feeds_enabled' ) && WPPF_Settings::get( 'auto_feeds_taxonomies' ) ) {
			$example = '';
			foreach ( (array) WPPF_Settings::get( 'auto_feeds_taxonomies' ) as $tax ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $tax,
						'number'     => 1,
						'hide_empty' => true,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);
				if ( $terms && ! is_wp_error( $terms ) ) {
					$example = WPPF_Router::term_feed_url( $terms[0] );
					break;
				}
			}
			echo '<p>Включены для: <code>' . esc_html( implode( ', ', (array) WPPF_Settings::get( 'auto_feeds_taxonomies' ) ) ) . '</code>. Адрес у каждого термина виден на экране его редактирования.';
			if ( $example ) {
				echo ' Например: <code>' . esc_html( $example ) . '</code> (<a href="' . esc_url( WPPF_Router::preview_url( $example ) ) . '" target="_blank" rel="noopener">предпросмотр</a>)';
			}
			echo '</p>';
		} else {
			echo '<p>Выключены. <a href="' . esc_url( admin_url( 'admin.php?page=' . self::AUTO_SLUG ) ) . '">Настроить</a></p>';
		}

		$tick = get_option( WPPF_Scheduler::LAST_TICK_OPTION );
		echo '<h2>Фоновая задача</h2><p>';
		echo is_array( $tick ) ? 'Последний тик: ' . esc_html( wp_date( 'd.m.Y H:i', (int) $tick['time'] ) . ' — ' . $tick['summary'] ) : 'Ещё не запускалась.';
		echo '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wppf_run_tick' );
		echo '<input type="hidden" name="action" value="wppf_run_tick"><button class="button">Запустить сейчас</button></form>';

		if ( ! WPPF_Image_Composer::text_available() ) {
			echo '<div class="notice notice-warning inline"><p>GD с FreeType недоступен — пины не будут генерироваться, ленты отдадут исходные картинки.</p></div>';
		}
		echo '</div>';
	}

	public static function page_auto() {
		echo '<div class="wrap wppf-wrap"><h1>Автоленты терминов</h1>';
		self::notice();
		echo '<p>У каждого термина отмеченных таксономий появляется своя лента: <code>' . esc_html( home_url( '/pinfeed/term/&lt;таксономия&gt;/&lt;слаг-термина&gt;/' ) ) . '</code>. Удобно, когда доски Pinterest повторяют рубрики сайта. Все автоленты настраиваются здесь одним набором; если какой-то доске нужно особое — заведите для неё обычную ленту.</p>';

		$s          = WPPF_Settings::get_all();
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wppf_save_auto' );
		echo '<input type="hidden" name="action" value="wppf_save_auto">';

		echo '<div class="wppf-section"><h3>Включение</h3><table class="form-table">';
		echo '<tr><th>Автоленты</th><td><label><input type="checkbox" name="auto_feeds_enabled" value="1"' . checked( (bool) $s['auto_feeds_enabled'], true, false ) . '> Включены</label></td></tr>';
		echo '<tr><th>Таксономии</th><td>';
		foreach ( $taxonomies as $tax ) {
			echo '<label class="wppf-inline"><input type="checkbox" name="auto_feeds_taxonomies[]" value="' . esc_attr( $tax->name ) . '"' . checked( in_array( $tax->name, (array) $s['auto_feeds_taxonomies'], true ), true, false ) . '> ' . esc_html( $tax->labels->singular_name ) . ' <code>' . esc_html( $tax->name ) . '</code></label>';
		}
		echo '</td></tr>';
		echo '<tr><th>Минимум записей в термине</th><td><input type="number" class="small-text" name="auto_feeds_min_count" value="' . esc_attr( (string) $s['auto_feeds_min_count'] ) . '"><p class="description">У терминов с меньшим числом записей лента отдаёт 404.</p></td></tr>';
		echo '</table></div>';

		WPPF_Feed_Config::render_form( WPPF_Feed_Config::normalise( WPPF_Feed_Config::get_auto_raw() ), 'auto' );

		submit_button( 'Сохранить' );
		echo '</form></div>';
	}

	public static function handle_save_auto() {
		self::guard( 'wppf_save_auto' );

		$taxonomies = isset( $_POST['auto_feeds_taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['auto_feeds_taxonomies'] ) ) : array();
		WPPF_Settings::update(
			array(
				'auto_feeds_enabled'    => empty( $_POST['auto_feeds_enabled'] ) ? 0 : 1,
				'auto_feeds_taxonomies' => array_values( array_filter( $taxonomies, 'taxonomy_exists' ) ),
				'auto_feeds_min_count'  => max( 1, isset( $_POST['auto_feeds_min_count'] ) ? (int) $_POST['auto_feeds_min_count'] : 1 ),
			)
		);

		$input = isset( $_POST['wppf'] ) && is_array( $_POST['wppf'] ) ? wp_unslash( $_POST['wppf'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		WPPF_Feed_Config::save_auto( $input );

		self::redirect( self::AUTO_SLUG, 'saved' );
	}

	// ------------------------------------------------------------- presets

	private static function preset_groups() {
		return array(
			'canvas'  => 'Полотно и фон',
			'heading' => 'Заголовок',
			'items'   => 'Тезисы: раскладка и блоки',
			'text'    => 'Тезисы: маркер и текст',
			'footer'  => 'Подпись внизу и логотип',
		);
	}

	private static function preset_labels() {
		return array(
			'name'                => array( 'Название', 'canvas' ),
			'width'               => array( 'Ширина, px', 'canvas' ),
			'height'              => array( 'Высота, px', 'canvas' ),
			'padding'             => array( 'Поля, px', 'canvas' ),
			'background'          => array( 'Цвет фона', 'canvas' ),
			'background_mode'     => array( 'Фон', 'canvas' ),
			'fit'                 => array( 'Вписывание картинки фона', 'canvas' ),
			'focal'               => array( 'Фокус обрезки', 'canvas' ),
			'grayscale'           => array( 'Фон ч/б', 'canvas' ),
			'brightness'          => array( 'Яркость фона (−255…255)', 'canvas' ),
			'blur'                => array( 'Размытие фона, проходов', 'canvas' ),
			'tint_hex'            => array( 'Затемнение: цвет', 'canvas' ),
			'tint_alpha'          => array( 'Затемнение: непрозрачность, %', 'canvas' ),
			'gradient_enabled'    => array( 'Градиент', 'canvas' ),
			'gradient_direction'  => array( 'Направление градиента', 'canvas' ),
			'gradient_from_hex'   => array( 'Градиент: цвет в начале', 'canvas' ),
			'gradient_from_alpha' => array( 'Градиент: непрозрачность в начале, %', 'canvas' ),
			'gradient_to_hex'     => array( 'Градиент: цвет в конце', 'canvas' ),
			'gradient_to_alpha'   => array( 'Градиент: непрозрачность в конце, %', 'canvas' ),
			'font'                => array( 'Шрифт', 'canvas' ),
			'format'              => array( 'Формат файла', 'canvas' ),
			'quality'             => array( 'Качество', 'canvas' ),
			'accent_enabled'      => array( 'Акцентная полоса', 'heading' ),
			'accent_color'        => array( 'Цвет полосы', 'heading' ),
			'accent_width'        => array( 'Длина полосы', 'heading' ),
			'accent_height'       => array( 'Толщина полосы', 'heading' ),
			'accent_gap'          => array( 'Отступ под полосой', 'heading' ),
			'title_enabled'       => array( 'Заголовок', 'heading' ),
			'title_size'          => array( 'Кегль', 'heading' ),
			'title_min_size'      => array( 'Минимальный кегль', 'heading' ),
			'title_color'         => array( 'Цвет', 'heading' ),
			'title_align'         => array( 'Выравнивание', 'heading' ),
			'title_max_lines'     => array( 'Максимум строк', 'heading' ),
			'title_line_height'   => array( 'Интерлиньяж', 'heading' ),
			'title_gap'           => array( 'Отступ под заголовком', 'heading' ),
			'layout'              => array( 'Раскладка', 'items' ),
			'columns'             => array( 'Колонок (для сетки)', 'items' ),
			'gap_x'               => array( 'Зазор по горизонтали', 'items' ),
			'gap_y'               => array( 'Зазор по вертикали', 'items' ),
			'plate_enabled'       => array( 'Плашка под пунктом', 'items' ),
			'plate_color'         => array( 'Цвет плашки', 'items' ),
			'plate_alpha'         => array( 'Непрозрачность плашки, %', 'items' ),
			'plate_radius'        => array( 'Скругление', 'items' ),
			'plate_padding'       => array( 'Внутренний отступ', 'items' ),
			'plate_border_width'  => array( 'Обводка: толщина', 'items' ),
			'plate_border_color'  => array( 'Обводка: цвет', 'items' ),
			'badge_style'         => array( 'Маркер', 'text' ),
			'badge_size'          => array( 'Размер маркера', 'text' ),
			'badge_bg'            => array( 'Цвет маркера', 'text' ),
			'badge_color'         => array( 'Цвет цифры', 'text' ),
			'badge_gap'           => array( 'Отступ от маркера', 'text' ),
			'label_enabled'       => array( 'Метка над тезисом (1–3 слова)', 'text' ),
			'label_size'          => array( 'Кегль метки', 'text' ),
			'label_color'         => array( 'Цвет метки', 'text' ),
			'label_uppercase'     => array( 'Метка ПРОПИСНЫМИ', 'text' ),
			'label_gap'           => array( 'Отступ под меткой', 'text' ),
			'text_size'           => array( 'Кегль тезиса', 'text' ),
			'text_min_size'       => array( 'Минимальный кегль тезиса', 'text' ),
			'text_color'          => array( 'Цвет тезиса', 'text' ),
			'text_line_height'    => array( 'Интерлиньяж тезиса', 'text' ),
			'footer_enabled'      => array( 'Подпись внизу', 'footer' ),
			'footer_size'         => array( 'Кегль подписи', 'footer' ),
			'footer_color'        => array( 'Цвет подписи', 'footer' ),
			'footer_align'        => array( 'Выравнивание подписи', 'footer' ),
			'footer_gap'          => array( 'Отступ над подписью', 'footer' ),
			'logo_id'             => array( 'Логотип (PNG с прозрачностью)', 'footer' ),
			'logo_position'       => array( 'Позиция логотипа', 'footer' ),
			'logo_width'          => array( 'Ширина логотипа, % полотна', 'footer' ),
			'logo_alpha'          => array( 'Непрозрачность логотипа, %', 'footer' ),
			'logo_offset_x'       => array( 'Отступ логотипа X', 'footer' ),
			'logo_offset_y'       => array( 'Отступ логотипа Y', 'footer' ),
		);
	}

	private static function presets_url( $preset = '' ) {
		$args = array( 'page' => self::PRESETS_SLUG );
		if ( '' !== $preset ) {
			$args['preset'] = $preset;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function page_presets() {
		$presets = WPPF_Infographic_Presets::all();
		$current = isset( $_GET['preset'] ) ? sanitize_key( $_GET['preset'] ) : (string) key( $presets ); // phpcs:ignore WordPress.Security.NonceVerification
		$is_new  = 'new' === $current;
		$preset  = $is_new ? WPPF_Infographic_Presets::schema() : WPPF_Infographic_Presets::get( $current );

		echo '<div class="wrap wppf-wrap"><h1>Пресеты картинок</h1>';
		self::notice();

		echo '<p class="description">Как выглядит инфографика, которую плагин генерирует для записей без подходящего изображения: заголовок и нумерованные тезисы списком или сеткой, вертикально 2:3. Все тезисы — одним кеглем; не влезающий тезис модель переписывает короче, иначе он убирается целиком.</p>';

		echo '<p class="wppf-tabs">';
		foreach ( $presets as $id => $p ) {
			$btn = $id === $current ? 'button button-primary' : 'button';
			echo '<a class="' . esc_attr( $btn ) . '" href="' . esc_url( self::presets_url( $id ) ) . '">' . esc_html( $p['name'] ) . '</a> ';
		}
		echo '<a class="button' . ( $is_new ? ' button-primary' : '' ) . '" href="' . esc_url( self::presets_url( 'new' ) ) . '">+ Новый</a></p>';

		echo '<div class="wppf-preset-layout"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wppf-preset-form">';
		wp_nonce_field( 'wppf_save_preset' );
		echo '<input type="hidden" name="action" value="wppf_save_preset"><input type="hidden" name="preset_id" value="' . esc_attr( $is_new ? '' : $current ) . '">';

		$labels = self::preset_labels();
		foreach ( self::preset_groups() as $group => $title ) {
			echo '<div class="wppf-section"><h3>' . esc_html( $title ) . '</h3><table class="form-table">';
			foreach ( $labels as $key => $meta ) {
				if ( $meta[1] !== $group ) {
					continue;
				}
				echo '<tr><th>' . esc_html( $meta[0] ) . '</th><td>';
				self::preset_field( $key, $preset[ $key ] ?? '' );
				echo '</td></tr>';
			}
			echo '</table></div>';
		}

		submit_button( 'Сохранить пресет' );
		echo '</form>';

		echo '<div class="wppf-preview-pane"><h3>Предпросмотр</h3>';
		echo '<p><label>ID записи для примера <input type="number" class="small-text wppf-preview-post" placeholder="последняя"></label> <button type="button" class="button wppf-preview-btn">Собрать</button></p>';
		echo '<div class="wppf-preview-box"><img class="wppf-preview-img" alt="" style="display:none"><p class="wppf-preview-note description">Собирается с текущими (ещё не сохранёнными) значениями формы. Тезисы — из блока «Pinterest» записи или уже готовые от модели, иначе — пример.</p></div>';

		if ( ! $is_new ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Удалить пресет?\')">';
			wp_nonce_field( 'wppf_delete_preset' );
			echo '<input type="hidden" name="action" value="wppf_delete_preset"><input type="hidden" name="preset_id" value="' . esc_attr( $current ) . '"><button class="button-link wppf-danger">Удалить пресет</button></form>';
		}

		echo '<h3>Перенос между сайтами</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wppf_export_presets' );
		echo '<input type="hidden" name="action" value="wppf_export_presets"><button class="button">Скачать все пресеты (JSON)</button></form>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wppf_import_presets' );
		echo '<input type="hidden" name="action" value="wppf_import_presets"><p><input type="file" name="presets_json" accept=".json,application/json"> <button class="button">Импорт</button></p><p class="description">ID логотипов при переносе не совпадут — перевыберите их после импорта.</p></form>';

		echo '</div></div></div>';
	}

	private static function preset_field( $key, $value ) {
		$name      = 'preset[' . $key . ']';
		$positions = WPPF_Image_Util::positions();
		$aligns    = array(
			'left'   => 'По левому краю',
			'center' => 'По центру',
			'right'  => 'По правому краю',
		);

		$selects = array(
			'layout'             => WPPF_Infographic_Presets::layouts(),
			'fit'                => WPPF_Image_Util::fit_modes(),
			'focal'              => $positions,
			'caption_position'   => $positions,
			'footer_position'    => $positions,
			'logo_position'      => $positions,
			'gradient_direction' => WPPF_Image_Util::gradient_directions(),
			'caption_align'      => $aligns,
			'title_align'        => $aligns,
			'footer_align'       => $aligns,
			'badge_style'        => WPPF_Infographic_Presets::badge_styles(),
			'background_mode'    => WPPF_Infographic_Presets::background_modes(),
			'format'             => array(
				'jpg'  => 'JPEG',
				'webp' => 'WebP',
				'png'  => 'PNG',
			),
		);

		if ( isset( $selects[ $key ] ) ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			foreach ( $selects[ $key ] as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( (string) $value, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select>';
			return;
		}

		if ( 'caption_font' === $key || 'font' === $key ) {
			echo '<select name="' . esc_attr( $name ) . '"><option value="">Автоматически</option>';
			foreach ( WPPF_Image_Composer::uploaded_fonts() as $font ) {
				echo '<option value="' . esc_attr( $font['path'] ) . '"' . selected( (string) $value, $font['path'], false ) . '>' . esc_html( $font['name'] ) . '</option>';
			}
			echo '</select> <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) . '#fonts">Загрузить шрифт</a>';
			return;
		}

		if ( 'logo_id' === $key ) {
			WPPF_Feed_Config::media_field( $name, (int) $value );
			return;
		}

		if ( preg_match( '/(_enabled|grayscale|_autofit|_uppercase)$/', $key ) ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '> Да</label>';
			return;
		}

		if ( preg_match( '/(_hex|_color|^background|^badge_bg)$/', $key ) ) {
			echo '<input type="text" class="wppf-color" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			return;
		}

		if ( 'name' === $key ) {
			echo '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			return;
		}

		$step = preg_match( '/_line_height$/', $key ) ? '0.05' : '1';
		echo '<input type="number" step="' . esc_attr( $step ) . '" class="small-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}

	public static function handle_save_preset() {
		self::guard( 'wppf_save_preset' );
		$class  = 'WPPF_Infographic_Presets';
		$values = isset( $_POST['preset'] ) && is_array( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$id     = $class::save( isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '', $values );
		self::redirect( self::PRESETS_SLUG, 'saved', array( 'preset' => $id ) );
	}

	public static function handle_delete_preset() {
		self::guard( 'wppf_delete_preset' );
		$class = 'WPPF_Infographic_Presets';
		$class::delete( isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '' );
		self::redirect( self::PRESETS_SLUG, 'deleted' );
	}

	public static function handle_export_presets() {
		self::guard( 'wppf_export_presets' );
		$class = 'WPPF_Infographic_Presets';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wppf-infographic-presets-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $class::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function handle_import_presets() {
		self::guard( 'wppf_import_presets' );
		$class = 'WPPF_Infographic_Presets';
		$file  = isset( $_FILES['presets_json']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['presets_json']['tmp_name'] ) : '';
		$data  = $file && is_uploaded_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore
		if ( ! is_array( $data ) ) {
			self::redirect( self::PRESETS_SLUG, 'import_error' );
		}
		foreach ( $data as $id => $preset ) {
			if ( is_array( $preset ) ) {
				$class::save( (string) $id, $preset );
			}
		}
		self::redirect( self::PRESETS_SLUG, 'imported' );
	}

	/** Demo points for the preview when the sample post has none of its own. */
	private static function sample_points() {
		return array(
			array( 'label' => 'Установка', 'text' => 'Сервер ставится одной командой и сразу виден в списке инструментов.' ),
			array( 'label' => 'Настройка', 'text' => 'Пути и ключи задаются в одном конфиге, без правки кода.' ),
			array( 'label' => 'Проверка', 'text' => 'Тестовый запрос показывает, что модель получила доступ к инструменту.' ),
			array( 'label' => 'Ошибки', 'text' => 'Чаще всего мешают неверный путь к файлу и забытый перезапуск клиента.' ),
			array( 'label' => 'Безопасность', 'text' => 'Давайте серверу доступ только к нужным папкам и сервисам.' ),
		);
	}

	/** Renders the posted (unsaved) preset onto a sample post and returns the image URL. */
	public static function ajax_preview_preset() {
		check_ajax_referer( 'wppf_preview_preset', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Недостаточно прав' );
		}

		$values = isset( $_POST['preset'] ) && is_array( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$preset = WPPF_Infographic_Presets::sanitize( $values );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			$latest = get_posts(
				array(
					'post_type'      => WPPF_Post_Meta::post_types(),
					'posts_per_page' => 1,
					'post_status'    => 'publish',
				)
			);
			$post   = $latest ? $latest[0] : null;
		}

		$config = array_merge(
			WPPF_Feed_Config::normalise( array() ),
			array(
				'feed_key'   => 'preview',
				'feed_slug'  => 'preview',
				'feed_title' => 'Предпросмотр',
				'term'       => null,
			)
		);

		// For a "picture of the post" background: its image, else the first one in the text.
		$background = null;
		if ( $post ) {
			$background = WPPF_Image_Resolver::from_source( 'thumbnail', $post, $config );
			if ( ! $background ) {
				$background = WPPF_Image_Resolver::from_source( 'content', $post, $config );
			}
		}
		$source = $background ? WPPF_Image_Resolver::local_path( $background ) : '';
		$title  = $post ? get_the_title( $post ) : 'Как подключить MCP-сервер: 5 шагов';
		$footer = WPPF_Template::resolve( 'site_domain', array( 'config' => $config ) );
		$path   = WPPF_Pin_Builder::base_dir() . 'preview/infographic-' . get_current_user_id() . '.' . WPPF_Image_Composer::extension_for( $preset['format'] );
		$note   = $post ? 'Запись #' . $post->ID . ' «' . $title . '»' : 'Без записи';

		$ig = $post ? WPPF_Infographic::points_for( $post, $config ) : array( 'state' => 'none' );
		if ( 'ready' === $ig['state'] ) {
			$points = $ig['points'];
			$note  .= 'manual' === $ig['source'] ? ', тезисы из блока «Pinterest»' : ', тезисы от модели';
		} else {
			$points = self::sample_points();
			$note  .= ', тезисы — пример (у записи их ещё нет)';
		}
		$res = WPPF_Infographic::render_fitting( $preset, $points, $title, $footer, $source ? $source : null, $path );
		$err = $res['ok'] ? implode( '; ', $res['notes'] ) : $res['error'];

		if ( ! $res['ok'] ) {
			wp_send_json_error( $err );
		}
		wp_send_json_success(
			array(
				'url'  => WPPF_Pin_Builder::base_url() . 'preview/' . basename( $path ) . '?v=' . time(),
				'note' => $note . ( '' !== $err ? '. ' . $err : '' ),
			)
		);
	}

	// ------------------------------------------------------------ settings

	public static function page_settings() {
		$s = WPPF_Settings::get_all();

		echo '<div class="wrap wppf-wrap"><h1>Настройки</h1>';
		self::notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wppf_save_settings' );
		echo '<input type="hidden" name="action" value="wppf_save_settings">';

		echo '<div class="wppf-section"><h3>LLM</h3><table class="form-table">';
		echo '<tr><th>Провайдер по умолчанию</th><td><select name="s[llm_provider]">';
		foreach ( WPPF_Settings::llm_providers() as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $s['llm_provider'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>' . ( WPPF_Settings::llm_ready() ? ' <span class="wppf-ok">ключи заполнены</span>' : ' <span class="wppf-warn">ключи не заполнены</span>' ) . '</td></tr>';

		$fields = array(
			'yandex_api_key'       => array( 'Yandex: API-ключ', 'password' ),
			'yandex_folder_id'     => array( 'Yandex: folder ID', 'text' ),
			'yandex_model'         => array( 'Yandex: модель', 'text' ),
			'deepseek_api_key'     => array( 'DeepSeek: API-ключ', 'password' ),
			'deepseek_model'       => array( 'DeepSeek: модель', 'text' ),
			'qwen_api_key'         => array( 'Qwen: API-ключ', 'password' ),
			'qwen_model'           => array( 'Qwen: модель', 'text' ),
			'qwen_base_url'        => array( 'Qwen: base URL', 'text' ),
			'timeweb_agent_id'     => array( 'Timeweb: ID агента', 'text' ),
			'timeweb_token'        => array( 'Timeweb: токен', 'password' ),
			'timeweb_proxy_source' => array( 'Timeweb: x-proxy-source', 'text' ),
			'openrouter_api_key'   => array( 'OpenRouter: API-ключ', 'password' ),
			'openrouter_model'     => array( 'OpenRouter: модель', 'text' ),
			'llm_timeout'          => array( 'Таймаут запроса, с', 'number' ),
			'llm_per_tick'         => array( 'Запросов к модели за тик', 'number' ),
		);
		foreach ( $fields as $key => $meta ) {
			echo '<tr><th>' . esc_html( $meta[0] ) . '</th><td><input type="' . esc_attr( $meta[1] ) . '" class="' . ( 'number' === $meta[1] ? 'small-text' : 'regular-text' ) . '" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '" autocomplete="off"></td></tr>';
		}
		echo '</table></div>';

		echo '<div class="wppf-section"><h3>Картинки</h3><table class="form-table">';
		echo '<tr><th>Пинов сразу, при запросе ленты</th><td><input type="number" class="small-text" name="s[sync_render_limit]" value="' . esc_attr( (string) $s['sync_render_limit'] ) . '"><p class="description">Остальные соберёт фоновая задача. Сборка одного пина — доли секунды, но Pinterest не ждёт долго.</p></td></tr>';
		echo '<tr><th>Пинов за тик</th><td><input type="number" class="small-text" name="s[images_per_tick]" value="' . esc_attr( (string) $s['images_per_tick'] ) . '"></td></tr>';
		echo '<tr><th>Путь к шрифту TTF/OTF</th><td><input type="text" class="regular-text code" name="s[caption_font_path]" value="' . esc_attr( (string) $s['caption_font_path'] ) . '"><p class="description">Пусто — загруженный ниже, затем системный (DejaVu, Noto, Liberation). Шрифты из папки llm-agent-articles тоже подхватываются.</p></td></tr>';
		echo '</table></div>';

		echo '<div class="wppf-section"><h3>Ленты</h3><table class="form-table">';
		echo '<tr><th>Кэш XML, минут</th><td><input type="number" class="small-text" name="s[feed_cache_minutes]" value="' . esc_attr( (string) $s['feed_cache_minutes'] ) . '"><p class="description">0 — без кэша. Сбрасывается сам при сохранении любой записи.</p></td></tr>';
		echo '<tr><th>SEO-плагин</th><td><select name="s[seo_plugin]">';
		foreach ( array(
			'auto'     => 'Определить автоматически',
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'none'     => 'Нет',
		) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $s['seo_plugin'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><p class="description">Откуда брать {seo_title} и {seo_description}. Сейчас: ' . esc_html( WPPF_Settings::seo_plugin() ) . '.</p></td></tr>';
		echo '</table></div>';

		submit_button( 'Сохранить настройки' );
		echo '</form>';

		// ---- import / test LLM
		echo '<div class="wppf-section"><h3>Ключи LLM из других плагинов сети</h3>';
		$sources = WPPF_Integrations::llm_import_sources();
		if ( $sources ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wppf_import_llm' );
			echo '<input type="hidden" name="action" value="wppf_import_llm"><select name="source">';
			foreach ( $sources as $key => $source ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $source['label'] ) . '</option>';
			}
			echo '</select> <button class="button">Скопировать провайдера и ключи</button></form>';
		} else {
			echo '<p>Ни llm-agent-articles, ни llm-agent-github-repos с заполненными ключами на этом сайте не найдено.</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
		wp_nonce_field( 'wppf_test_llm' );
		echo '<input type="hidden" name="action" value="wppf_test_llm"><button class="button">Проверить соединение с моделью</button></form></div>';

		// ---- fonts
		echo '<div class="wppf-section" id="fonts"><h3>Шрифты</h3>';
		$fonts = WPPF_Image_Composer::uploaded_fonts();
		echo '<p>Найдено: ' . ( $fonts ? esc_html( implode( ', ', wp_list_pluck( $fonts, 'name' ) ) ) : 'нет загруженных' ) . '. Используется сейчас: <code>' . esc_html( WPPF_Image_Composer::resolve_font() ? WPPF_Image_Composer::resolve_font() : 'не найден' ) . '</code></p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wppf_upload_font' );
		echo '<input type="hidden" name="action" value="wppf_upload_font"><input type="file" name="font" accept=".ttf,.otf"> <button class="button">Загрузить</button></form></div>';

		// ---- cron
		echo '<div class="wppf-section"><h3>Фоновая задача</h3>';
		echo '<p>По умолчанию работает WP-Cron раз в 10 минут. На сайте с малой посещаемостью лучше внешний крон:</p>';
		foreach ( WPPF_Scheduler::get_trigger_commands() as $label => $cmd ) {
			echo '<p><strong>' . esc_html( $label ) . '</strong><br><input type="text" class="large-text code" readonly value="' . esc_attr( $cmd ) . '" onclick="this.select()"></p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wppf_regen_secret' );
		echo '<input type="hidden" name="action" value="wppf_regen_secret"><button class="button" onclick="return confirm(\'Старые команды перестанут работать. Продолжить?\')">Сгенерировать новый секрет</button></form></div>';

		echo '</div>';
	}

	public static function handle_save_settings() {
		self::guard( 'wppf_save_settings' );
		$input  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$values = array();

		foreach ( WPPF_Settings::llm_keys() as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$values[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		if ( ! isset( WPPF_Settings::llm_providers()[ $values['llm_provider'] ?? '' ] ) ) {
			$values['llm_provider'] = 'deepseek';
		}

		foreach ( array( 'llm_timeout', 'llm_per_tick', 'sync_render_limit', 'images_per_tick', 'feed_cache_minutes' ) as $key ) {
			$values[ $key ] = max( 0, (int) ( $input[ $key ] ?? 0 ) );
		}
		$values['caption_font_path']     = sanitize_text_field( (string) ( $input['caption_font_path'] ?? '' ) );
		$values['seo_plugin']            = in_array( $input['seo_plugin'] ?? '', array( 'auto', 'yoast', 'rankmath', 'none' ), true ) ? $input['seo_plugin'] : 'auto';

		WPPF_Settings::update( $values );
		WPPF_Feed_Renderer::flush_cache();
		self::redirect( self::SETTINGS_SLUG, 'saved' );
	}

	public static function handle_import_llm() {
		self::guard( 'wppf_import_llm' );
		$count = WPPF_Integrations::import_llm( isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '' );
		self::redirect( self::SETTINGS_SLUG, $count ? 'imported' : 'nothing' );
	}

	public static function handle_test_llm() {
		self::guard( 'wppf_test_llm' );
		$client = new WPPF_LLM_Client();
		$answer = $client->json( 'Ответь строго JSON-объектом.', 'Верни {"ok": true, "word": "пин"}', 50, 0 );
		if ( is_array( $answer ) ) {
			self::redirect( self::SETTINGS_SLUG, 'llm_ok', array( 'wppf_extra' => rawurlencode( $client->provider() . ' — ' . wp_json_encode( $answer, JSON_UNESCAPED_UNICODE ) ) ) );
		}
		self::redirect( self::SETTINGS_SLUG, 'llm_error', array( 'wppf_extra' => rawurlencode( mb_substr( $client->last_error(), 0, 200 ) ) ) );
	}

	public static function handle_regen_secret() {
		self::guard( 'wppf_regen_secret' );
		WPPF_Settings::regenerate_secret();
		self::redirect( self::SETTINGS_SLUG, 'secret' );
	}

	public static function handle_run_tick() {
		self::guard( 'wppf_run_tick' );
		$summary = WPPF_Scheduler::run_tick();
		self::redirect( self::MAIN_SLUG, 'tick', array( 'wppf_extra' => rawurlencode( $summary ) ) );
	}

	public static function handle_upload_font() {
		self::guard( 'wppf_upload_font' );
		$name = isset( $_FILES['font']['name'] ) ? sanitize_file_name( wp_unslash( (string) $_FILES['font']['name'] ) ) : '';
		$tmp  = isset( $_FILES['font']['tmp_name'] ) ? (string) $_FILES['font']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! $tmp || ! is_uploaded_file( $tmp ) || ! preg_match( '/\.(ttf|otf)$/i', $name ) ) {
			self::redirect( self::SETTINGS_SLUG, 'font_error' );
		}
		// A font file starts with one of these four signatures; anything else
		// is not a font, whatever its extension says.
		$magic = (string) file_get_contents( $tmp, false, null, 0, 4 ); // phpcs:ignore
		if ( ! in_array( $magic, array( "\x00\x01\x00\x00", 'OTTO', 'true', 'ttcf' ), true ) ) {
			self::redirect( self::SETTINGS_SLUG, 'font_error' );
		}

		$dir = WPPF_Image_Composer::fonts_dir();
		wp_mkdir_p( $dir );
		move_uploaded_file( $tmp, trailingslashit( $dir ) . $name );
		self::redirect( self::SETTINGS_SLUG, 'font' );
	}

	// ------------------------------------------------------------------ log

	public static function page_log() {
		echo '<div class="wrap wppf-wrap"><h1>Журнал</h1>';
		self::notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wppf_clear_log' );
		echo '<input type="hidden" name="action" value="wppf_clear_log"><button class="button">Очистить</button></form>';

		$entries = WPPF_Log::get_recent();
		if ( ! $entries ) {
			echo '<p>Записей нет.</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th style="width:150px">Время</th><th style="width:70px">Уровень</th><th>Сообщение</th></tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			echo '<tr><td>' . esc_html( wp_date( 'd.m.Y H:i:s', (int) $entry['time'] ) ) . '</td><td>' . esc_html( $entry['level'] ) . '</td><td>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function handle_clear_log() {
		self::guard( 'wppf_clear_log' );
		WPPF_Log::clear();
		self::redirect( self::LOG_SLUG, 'cleared' );
	}
}
