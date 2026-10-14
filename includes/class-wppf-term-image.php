<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Изображение для Pinterest" on terms: an attachment ID in term meta.
 * Used by the "term" image source — a post with no picture of its own gets
 * the picture of its topic/category — and, in an automatic per-term feed,
 * that term's picture is checked first.
 */
class WPPF_Term_Image {

	const META = 'wppf_image_id';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'hook_taxonomies' ) );
	}

	public static function hook_taxonomies() {
		foreach ( WPPF_Settings::term_image_taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( __CLASS__, 'render_add_field' ) );
			add_action( $taxonomy . '_edit_form_fields', array( __CLASS__, 'render_edit_field' ) );
			add_action( 'created_' . $taxonomy, array( __CLASS__, 'save' ) );
			add_action( 'edited_' . $taxonomy, array( __CLASS__, 'save' ) );
		}
	}

	public static function render_add_field() {
		wp_nonce_field( 'wppf_term_image', 'wppf_term_image_nonce' );
		echo '<div class="form-field"><label>Изображение для Pinterest</label>';
		WPPF_Feed_Config::media_field( 'wppf_term_image', 0 );
		echo '<p>Запасная картинка пина для записей этого термина без своей картинки. Лучше вертикальная, 1000×1500.</p></div>';
	}

	public static function render_edit_field( $term ) {
		wp_nonce_field( 'wppf_term_image', 'wppf_term_image_nonce' );
		echo '<tr class="form-field"><th scope="row"><label>Изображение для Pinterest</label></th><td>';
		WPPF_Feed_Config::media_field( 'wppf_term_image', (int) get_term_meta( $term->term_id, self::META, true ) );
		echo '<p class="description">Запасная картинка пина для записей этого термина без своей картинки. Лучше вертикальная, 1000×1500.</p>';

		$url = WPPF_Router::term_feed_url( $term );
		if ( $url ) {
			echo '<p class="description">Автолента термина: <code>' . esc_html( $url ) . '</code></p>';
		}
		echo '</td></tr>';
	}

	public static function save( $term_id ) {
		if ( ! isset( $_POST['wppf_term_image_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppf_term_image_nonce'] ) ), 'wppf_term_image' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$id = isset( $_POST['wppf_term_image'] ) ? absint( $_POST['wppf_term_image'] ) : 0;
		if ( $id ) {
			update_term_meta( $term_id, self::META, $id );
		} else {
			delete_term_meta( $term_id, self::META );
		}
	}

	public static function get( $term_id ) {
		return (int) get_term_meta( (int) $term_id, self::META, true );
	}
}
