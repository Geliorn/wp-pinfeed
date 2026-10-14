<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-post overrides, one small metabox on every public post type:
 * a dedicated Pinterest image, hand-written pin title/description
 * ({pin_title} / {pin_description}) and an opt-out from all feeds.
 */
class WPPF_Post_Meta {

	const IMAGE       = '_wppf_image_id';
	const TITLE       = '_wppf_pin_title';
	const DESCRIPTION = '_wppf_pin_description';
	const EXCLUDE     = '_wppf_exclude';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return (array) apply_filters( 'wppf_metabox_post_types', array_values( $types ) );
	}

	public static function add_meta_box() {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'wppf_pin', 'Pinterest', array( __CLASS__, 'render' ), $type, 'side', 'default' );
		}
	}

	public static function render( $post ) {
		wp_nonce_field( 'wppf_post_meta', 'wppf_post_meta_nonce' );

		echo '<p><strong>Картинка пина</strong></p>';
		WPPF_Feed_Config::media_field( 'wppf_pin_image', (int) get_post_meta( $post->ID, self::IMAGE, true ) );
		echo '<p class="description">Вертикальная 1000×1500. Если источник «Картинка Pinterest у записи» стоит в ленте первым — пин возьмёт её как есть.</p>';

		echo '<p><label for="wppf-pin-title"><strong>Заголовок пина</strong></label><br>';
		echo '<input type="text" id="wppf-pin-title" class="widefat" maxlength="100" name="wppf_pin_title" value="' . esc_attr( (string) get_post_meta( $post->ID, self::TITLE, true ) ) . '"></p>';

		echo '<p><label for="wppf-pin-description"><strong>Описание пина</strong></label><br>';
		echo '<textarea id="wppf-pin-description" class="widefat" rows="4" maxlength="500" name="wppf_pin_description">' . esc_textarea( (string) get_post_meta( $post->ID, self::DESCRIPTION, true ) ) . '</textarea></p>';
		echo '<p class="description">Пусто — текст соберётся по шаблону ленты ({pin_title} / {pin_description} в начале цепочки).</p>';

		echo '<p><label><input type="checkbox" name="wppf_exclude" value="1"' . checked( '1', (string) get_post_meta( $post->ID, self::EXCLUDE, true ), false ) . '> Не публиковать в Pinterest</label></p>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['wppf_post_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppf_post_meta_nonce'] ) ), 'wppf_post_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$image = isset( $_POST['wppf_pin_image'] ) ? absint( $_POST['wppf_pin_image'] ) : 0;
		$title = isset( $_POST['wppf_pin_title'] ) ? sanitize_text_field( wp_unslash( $_POST['wppf_pin_title'] ) ) : '';
		$desc  = isset( $_POST['wppf_pin_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wppf_pin_description'] ) ) : '';

		self::put( $post_id, self::IMAGE, $image ? $image : '' );
		self::put( $post_id, self::TITLE, $title );
		self::put( $post_id, self::DESCRIPTION, $desc );
		self::put( $post_id, self::EXCLUDE, empty( $_POST['wppf_exclude'] ) ? '' : '1' );
	}

	private static function put( $post_id, $key, $value ) {
		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}
}
