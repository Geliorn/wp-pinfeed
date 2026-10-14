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

	private static function preset_labels() {
		return array(
			'name'                => array( 'Название', 'canvas' ),
			'width'               => array( 'Ширина, px', 'canvas' ),
			'height'              => array( 'Высота, px', 'canvas' ),
			'layout'              => array( 'Компоновка', 'canvas' ),
			'split_ratio'         => array( 'Высота картинки в «сверху/снизу», %', 'canvas' ),
			'panel_color'         => array( 'Цвет плашки с текстом', 'canvas' ),
			'fit'                 => array( 'Вписывание картинки', 'canvas' ),
			'focal'               => array( 'Фокус обрезки', 'canvas' ),
			'background'          => array( 'Цвет фона', 'canvas' ),
			'grayscale'           => array( 'Ч/б', 'filters' ),
			'brightness'          => array( 'Яркость (−255…255)', 'filters' ),
			'contrast'            => array( 'Контраст (−100…100, минус = сильнее)', 'filters' ),
			'blur'                => array( 'Размытие, проходов', 'filters' ),
			'tint_hex'            => array( 'Тонирование: цвет', 'filters' ),
			'tint_alpha'          => array( 'Тонирование: непрозрачность, %', 'filters' ),
			'gradient_enabled'    => array( 'Градиент', 'filters' ),
			'gradient_direction'  => array( 'Направление градиента', 'filters' ),
			'gradient_from_hex'   => array( 'Градиент: цвет в начале', 'filters' ),
			'gradient_from_alpha' => array( 'Градиент: непрозрачность в начале, %', 'filters' ),
			'gradient_to_hex'     => array( 'Градиент: цвет в конце', 'filters' ),
			'gradient_to_alpha'   => array( 'Градиент: непрозрачность в конце, %', 'filters' ),
			'caption_enabled'     => array( 'Текст на пине', 'caption' ),
			'caption_font'        => array( 'Шрифт', 'caption' ),
			'caption_size'        => array( 'Кегль', 'caption' ),
			'caption_autofit'     => array( 'Автоподбор кегля', 'caption' ),
			'caption_min_size'    => array( 'Минимальный кегль', 'caption' ),
			'caption_line_height' => array( 'Интерлиньяж', 'caption' ),
			'caption_color'       => array( 'Цвет текста', 'caption' ),
			'caption_align'       => array( 'Выравнивание', 'caption' ),
			'caption_position'    => array( 'Позиция (в плашке / на полотне)', 'caption' ),
			'caption_offset_x'    => array( 'Отступ по горизонтали', 'caption' ),
			'caption_offset_y'    => array( 'Отступ по вертикали', 'caption' ),
			'caption_max_width'   => array( 'Ширина блока, % полотна', 'caption' ),
			'caption_max_lines'   => array( 'Максимум строк', 'caption' ),
			'caption_uppercase'   => array( 'ПРОПИСНЫЕ', 'caption' ),
			'shadow_enabled'      => array( 'Тень текста', 'caption' ),
			'shadow_color'        => array( 'Цвет тени', 'caption' ),
			'shadow_alpha'        => array( 'Непрозрачность тени, %', 'caption' ),
			'shadow_offset_x'     => array( 'Тень: сдвиг X', 'caption' ),
			'shadow_offset_y'     => array( 'Тень: сдвиг Y', 'caption' ),
			'plate_enabled'       => array( 'Подложка под текстом', 'caption' ),
			'plate_color'         => array( 'Цвет подложки', 'caption' ),
			'plate_alpha'         => array( 'Непрозрачность подложки, %', 'caption' ),
			'plate_padding'       => array( 'Поля подложки', 'caption' ),
			'plate_radius'        => array( 'Скругление подложки', 'caption' ),
			'accent_enabled'      => array( 'Акцентная полоса над текстом', 'caption' ),
			'accent_color'        => array( 'Цвет полосы', 'caption' ),
			'accent_width'        => array( 'Длина полосы', 'caption' ),
			'accent_height'       => array( 'Толщина полосы', 'caption' ),
			'footer_enabled'      => array( 'Подпись внизу (домен и т.п.)', 'footer' ),
			'footer_size'         => array( 'Кегль подписи', 'footer' ),
			'footer_color'        => array( 'Цвет подписи', 'footer' ),
			'footer_position'     => array( 'Позиция подписи', 'footer' ),
			'footer_offset_x'     => array( 'Отступ по горизонтали', 'footer' ),
			'footer_offset_y'     => array( 'Отступ по вертикали', 'footer' ),
			'footer_uppercase'    => array( 'ПРОПИСНЫЕ', 'footer' ),
			'logo_id'             => array( 'Логотип (PNG с прозрачностью)', 'logo' ),
			'logo_position'       => array( 'Позиция логотипа', 'logo' ),
			'logo_width'          => array( 'Ширина логотипа, % полотна', 'logo' ),
			'logo_alpha'          => array( 'Непрозрачность логотипа, %', 'logo' ),
			'logo_offset_x'       => array( 'Отступ X', 'logo' ),
			'logo_offset_y'       => array( 'Отступ Y', 'logo' ),
			'border_width'        => array( 'Рамка: толщина', 'logo' ),
			'border_color'        => array( 'Рамка: цвет', 'logo' ),
			'border_inset'        => array( 'Рамка: отступ от края', 'logo' ),
			'format'              => array( 'Формат файла', 'output' ),
			'quality'             => array( 'Качество', 'output' ),
		);
	}

	public static function page_presets() {
		$presets = WPPF_Image_Presets::all();
		$current = isset( $_GET['preset'] ) ? sanitize_key( $_GET['preset'] ) : (string) key( $presets ); // phpcs:ignore WordPress.Security.NonceVerification
		$is_new  = 'new' === $current;
		$preset  = $is_new ? WPPF_Image_Presets::schema() : WPPF_Image_Presets::get( $current );

		echo '<div class="wrap wppf-wrap"><h1>Пресеты картинок</h1>';
		self::notice();

		echo '<p class="wppf-tabs">';
		foreach ( $presets as $id => $p ) {
			$class = $id === $current ? 'button button-primary' : 'button';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . self::PRESETS_SLUG . '&preset=' . $id ) ) . '">' . esc_html( $p['name'] ) . '</a> ';
		}
		echo '<a class="button' . ( $is_new ? ' button-primary' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . self::PRESETS_SLUG . '&preset=new' ) ) . '">+ Новый</a></p>';

		echo '<div class="wppf-preset-layout"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wppf-preset-form">';
		wp_nonce_field( 'wppf_save_preset' );
		echo '<input type="hidden" name="action" value="wppf_save_preset"><input type="hidden" name="preset_id" value="' . esc_attr( $is_new ? '' : $current ) . '">';

		$groups = array(
			'canvas'  => 'Полотно',
			'filters' => 'Картинка: фильтры и слои',
			'caption' => 'Текст',
			'footer'  => 'Подпись внизу',
			'logo'    => 'Логотип и рамка',
			'output'  => 'Файл',
		);
		$labels = self::preset_labels();

		foreach ( $groups as $group => $title ) {
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
		echo '<div class="wppf-preview-box"><img class="wppf-preview-img" alt="" style="display:none"><p class="wppf-preview-note description">Собирается с текущими (ещё не сохранёнными) значениями формы.</p></div>';

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
		$name = 'preset[' . $key . ']';

		$selects = array(
			'layout'             => WPPF_Image_Presets::layouts(),
			'fit'                => WPPF_Image_Presets::fit_modes(),
			'focal'              => WPPF_Image_Presets::positions(),
			'caption_position'   => WPPF_Image_Presets::positions(),
			'footer_position'    => WPPF_Image_Presets::positions(),
			'logo_position'      => WPPF_Image_Presets::positions(),
			'gradient_direction' => WPPF_Image_Presets::gradient_directions(),
			'caption_align'      => array(
				'left'   => 'По левому краю',
				'center' => 'По центру',
				'right'  => 'По правому краю',
			),
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

		if ( 'caption_font' === $key ) {
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

		if ( preg_match( '/(_hex|_color|^background)$/', $key ) ) {
			echo '<input type="text" class="wppf-color" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			return;
		}

		if ( 'name' === $key ) {
			echo '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			return;
		}

		$step = 'caption_line_height' === $key ? '0.05' : '1';
		echo '<input type="number" step="' . esc_attr( $step ) . '" class="small-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}

	public static function handle_save_preset() {
		self::guard( 'wppf_save_preset' );
		$values = isset( $_POST['preset'] ) && is_array( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$id     = WPPF_Image_Presets::save( isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '', $values );
		self::redirect( self::PRESETS_SLUG, 'saved', array( 'preset' => $id ) );
	}

	public static function handle_delete_preset() {
		self::guard( 'wppf_delete_preset' );
		WPPF_Image_Presets::delete( isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '' );
		self::redirect( self::PRESETS_SLUG, 'deleted' );
	}

	public static function handle_export_presets() {
		self::guard( 'wppf_export_presets' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wppf-presets-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( WPPF_Image_Presets::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function handle_import_presets() {
		self::guard( 'wppf_import_presets' );
		$file = isset( $_FILES['presets_json']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['presets_json']['tmp_name'] ) : '';
		$data = $file && is_uploaded_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore
		if ( ! is_array( $data ) ) {
			self::redirect( self::PRESETS_SLUG, 'import_error' );
		}
		foreach ( $data as $id => $preset ) {
			if ( is_array( $preset ) ) {
				WPPF_Image_Presets::save( (string) $id, $preset );
			}
		}
		self::redirect( self::PRESETS_SLUG, 'imported' );
	}

	/** Renders the posted (unsaved) preset onto a sample post and returns the image URL. */
	public static function ajax_preview_preset() {
		check_ajax_referer( 'wppf_preview_preset', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Недостаточно прав' );
		}

		$values = isset( $_POST['preset'] ) && is_array( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$preset = WPPF_Image_Presets::sanitize( $values );

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

		$config = WPPF_Feed_Config::normalise( array() );
		$config = array_merge(
			$config,
			array(
				'feed_key'   => 'preview',
				'feed_slug'  => 'preview',
				'feed_title' => 'Предпросмотр',
				'term'       => null,
			)
		);

		$found   = $post ? WPPF_Image_Resolver::find( $post, $config ) : null;
		$source  = $found ? WPPF_Image_Resolver::local_path( $found ) : '';
		$caption = $post ? get_the_title( $post ) : 'Пример заголовка пина: как собрать вертикальную картинку 2:3';
		$footer  = WPPF_Template::resolve( 'site_domain', array( 'config' => $config ) );

		$ext  = WPPF_Image_Composer::extension_for( $preset['format'] );
		$path = WPPF_Pin_Builder::base_dir() . 'preview/preset-' . get_current_user_id() . '.' . $ext;
		$res  = WPPF_Image_Composer::render( $preset, $source ? $source : null, $caption, $footer, $path );

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] );
		}
		wp_send_json_success(
			array(
				'url'  => WPPF_Pin_Builder::base_url() . 'preview/' . basename( $path ) . '?v=' . time(),
				'note' => trim( ( $post ? 'Запись #' . $post->ID . ' «' . get_the_title( $post ) . '»' : 'Без записи — текстовый пин' ) . ( $found ? '' : ', картинки не нашлось' ) . ( $res['error'] ? '. ' . $res['error'] : '' ) ),
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
		echo '<tr><th>Таксономии с «Изображением для Pinterest»</th><td>';
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			echo '<label class="wppf-inline"><input type="checkbox" name="s[term_image_taxonomies][]" value="' . esc_attr( $tax->name ) . '"' . checked( in_array( $tax->name, (array) $s['term_image_taxonomies'], true ), true, false ) . '> ' . esc_html( $tax->labels->singular_name ) . ' <code>' . esc_html( $tax->name ) . '</code></label>';
		}
		echo '<p class="description">Ничего не отмечено — поле есть у всех публичных таксономий.</p></td></tr>';
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
		$values['term_image_taxonomies'] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['term_image_taxonomies'] ?? array() ) ), 'taxonomy_exists' ) );
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
