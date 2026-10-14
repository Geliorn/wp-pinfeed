<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pin image presets: canvas, layout, filters, overlay layers, caption,
 * footer line and logo. Same model as LAA_Image_Presets (covers in
 * llm-agent-articles), with two pin-specific additions:
 *  - the default canvas is 1000×1500 (2:3, what Pinterest shows best);
 *  - a "split" layout — the source picture on top, a solid panel with the
 *    caption below — because a horizontal cover stretched to 2:3 loses
 *    two thirds of itself to the crop.
 * Stored in their own option so a tuned set can be exported as JSON.
 */
class WPPF_Image_Presets {

	const OPTION_KEY = 'wppf_image_presets';

	public static function positions() {
		return array(
			'top-left'      => 'Сверху слева',
			'top-center'    => 'Сверху по центру',
			'top-right'     => 'Сверху справа',
			'middle-left'   => 'По центру слева',
			'middle-center' => 'По центру',
			'middle-right'  => 'По центру справа',
			'bottom-left'   => 'Снизу слева',
			'bottom-center' => 'Снизу по центру',
			'bottom-right'  => 'Снизу справа',
		);
	}

	public static function layouts() {
		return array(
			'full'  => 'Картинка на всё полотно, текст поверх',
			'split' => 'Картинка сверху, текст на плашке снизу',
		);
	}

	public static function gradient_directions() {
		return array(
			'to-bottom' => 'Сверху вниз',
			'to-top'    => 'Снизу вверх',
			'to-right'  => 'Слева направо',
			'to-left'   => 'Справа налево',
		);
	}

	public static function fit_modes() {
		return array(
			'cover'   => 'Заполнить и обрезать',
			'contain' => 'Вписать целиком (с полями)',
		);
	}

	/** Every field a preset has, with its default. The form, the sanitiser and the composer read this. */
	public static function schema() {
		return array(
			// canvas
			'name'                   => 'Новый пресет',
			'width'                  => 1000,
			'height'                 => 1500,
			'layout'                 => 'split',
			'split_ratio'            => 58,        // % of height the picture takes in "split"
			'panel_color'            => '#111827', // the text panel in "split"
			'fit'                    => 'cover',
			'focal'                  => 'middle-center',
			'background'             => '#111827', // canvas; shows in `contain` and text-only pins

			// filters (on the picture only)
			'grayscale'              => 0,
			'brightness'             => 0,         // -255..255
			'contrast'               => 0,         // -100..100 (GD: negative = more contrast)
			'blur'                   => 0,         // 0..10 passes

			// solid tint over the picture
			'tint_hex'               => '#000000',
			'tint_alpha'             => 0,

			// gradient over the whole canvas
			'gradient_enabled'       => 0,
			'gradient_direction'     => 'to-bottom',
			'gradient_from_hex'      => '#000000',
			'gradient_from_alpha'    => 0,
			'gradient_to_hex'        => '#000000',
			'gradient_to_alpha'      => 80,

			// caption
			'caption_enabled'        => 1,
			'caption_font'           => '',
			'caption_size'           => 64,
			'caption_autofit'        => 1,
			'caption_min_size'       => 34,
			'caption_line_height'    => 1.2,
			'caption_color'          => '#ffffff',
			'caption_align'          => 'left',
			'caption_position'       => 'middle-left', // inside the panel in "split", the canvas in "full"
			'caption_offset_x'       => 64,
			'caption_offset_y'       => 56,
			'caption_max_width'      => 87,            // % of canvas width
			'caption_max_lines'      => 5,
			'caption_uppercase'      => 0,

			'shadow_enabled'         => 0,
			'shadow_color'           => '#000000',
			'shadow_alpha'           => 60,
			'shadow_offset_x'        => 2,
			'shadow_offset_y'        => 3,

			'plate_enabled'          => 0,
			'plate_color'            => '#000000',
			'plate_alpha'            => 55,
			'plate_padding'          => 28,
			'plate_radius'           => 12,

			// accent bar above the caption — reads as "brand" at feed size
			'accent_enabled'         => 1,
			'accent_color'           => '#e60023',
			'accent_width'           => 120,
			'accent_height'          => 8,

			// footer line (domain, stars, ...)
			'footer_enabled'         => 1,
			'footer_size'            => 28,
			'footer_color'           => '#cbd5e1',
			'footer_position'        => 'bottom-left',
			'footer_offset_x'        => 64,
			'footer_offset_y'        => 48,
			'footer_uppercase'       => 0,

			// logo / watermark
			'logo_id'                => 0,
			'logo_position'          => 'top-right',
			'logo_width'             => 16,
			'logo_alpha'             => 95,
			'logo_offset_x'          => 40,
			'logo_offset_y'          => 40,

			// frame
			'border_width'           => 0,
			'border_color'           => '#ffffff',
			'border_inset'           => 0,

			// output
			'format'                 => 'jpg',
			'quality'                => 86,
		);
	}

	public static function ensure_defaults() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			update_option( self::OPTION_KEY, self::seed_presets(), false );
		}
	}

	/** @return array<string,array> keyed by preset id */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return self::seed_presets();
		}
		$out = array();
		foreach ( $stored as $id => $preset ) {
			$out[ $id ] = wp_parse_args( is_array( $preset ) ? $preset : array(), self::schema() );
		}
		return $out;
	}

	public static function get( $id ) {
		$all = self::all();
		if ( isset( $all[ $id ] ) ) {
			return $all[ $id ];
		}
		return $all ? reset( $all ) : self::schema();
	}

	public static function save( $id, array $values ) {
		$all = self::all();
		$id  = sanitize_key( $id );
		if ( '' === $id ) {
			$id = 'preset-' . substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
		}
		$all[ $id ] = self::sanitize( $values );
		update_option( self::OPTION_KEY, $all, false );
		return $id;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		if ( empty( $all ) ) {
			$all = self::seed_presets();
		}
		update_option( self::OPTION_KEY, $all, false );
	}

	/** Coerce every field to its expected type and range. */
	public static function sanitize( array $values ) {
		$schema = self::schema();
		$out    = array();

		$int_ranges = array(
			'width'               => array( 300, 3000 ),
			'height'              => array( 300, 4500 ),
			'split_ratio'         => array( 20, 85 ),
			'grayscale'           => array( 0, 1 ),
			'brightness'          => array( -255, 255 ),
			'contrast'            => array( -100, 100 ),
			'blur'                => array( 0, 10 ),
			'tint_alpha'          => array( 0, 100 ),
			'gradient_enabled'    => array( 0, 1 ),
			'gradient_from_alpha' => array( 0, 100 ),
			'gradient_to_alpha'   => array( 0, 100 ),
			'caption_enabled'     => array( 0, 1 ),
			'caption_size'        => array( 10, 300 ),
			'caption_autofit'     => array( 0, 1 ),
			'caption_min_size'    => array( 8, 300 ),
			'caption_offset_x'    => array( 0, 2000 ),
			'caption_offset_y'    => array( 0, 2000 ),
			'caption_max_width'   => array( 10, 100 ),
			'caption_max_lines'   => array( 1, 12 ),
			'caption_uppercase'   => array( 0, 1 ),
			'shadow_enabled'      => array( 0, 1 ),
			'shadow_alpha'        => array( 0, 100 ),
			'shadow_offset_x'     => array( -40, 40 ),
			'shadow_offset_y'     => array( -40, 40 ),
			'plate_enabled'       => array( 0, 1 ),
			'plate_alpha'         => array( 0, 100 ),
			'plate_padding'       => array( 0, 200 ),
			'plate_radius'        => array( 0, 120 ),
			'accent_enabled'      => array( 0, 1 ),
			'accent_width'        => array( 0, 2000 ),
			'accent_height'       => array( 0, 100 ),
			'footer_enabled'      => array( 0, 1 ),
			'footer_size'         => array( 8, 200 ),
			'footer_offset_x'     => array( 0, 2000 ),
			'footer_offset_y'     => array( 0, 2000 ),
			'footer_uppercase'    => array( 0, 1 ),
			'logo_id'             => array( 0, PHP_INT_MAX ),
			'logo_width'          => array( 2, 100 ),
			'logo_alpha'          => array( 0, 100 ),
			'logo_offset_x'       => array( 0, 2000 ),
			'logo_offset_y'       => array( 0, 2000 ),
			'border_width'        => array( 0, 80 ),
			'border_inset'        => array( 0, 200 ),
			'quality'             => array( 1, 100 ),
		);

		foreach ( $schema as $key => $default ) {
			$value = $values[ $key ] ?? $default;

			if ( isset( $int_ranges[ $key ] ) ) {
				$out[ $key ] = max( $int_ranges[ $key ][0], min( $int_ranges[ $key ][1], (int) $value ) );
				continue;
			}

			switch ( $key ) {
				case 'caption_line_height':
					$out[ $key ] = max( 0.8, min( 3.0, (float) $value ) );
					break;
				case 'background':
				case 'panel_color':
				case 'tint_hex':
				case 'gradient_from_hex':
				case 'gradient_to_hex':
				case 'caption_color':
				case 'shadow_color':
				case 'plate_color':
				case 'accent_color':
				case 'footer_color':
				case 'border_color':
					$out[ $key ] = self::sanitize_hex( $value, $default );
					break;
				case 'layout':
					$out[ $key ] = isset( self::layouts()[ $value ] ) ? $value : $default;
					break;
				case 'fit':
					$out[ $key ] = isset( self::fit_modes()[ $value ] ) ? $value : $default;
					break;
				case 'focal':
				case 'caption_position':
				case 'footer_position':
				case 'logo_position':
					$out[ $key ] = isset( self::positions()[ $value ] ) ? $value : $default;
					break;
				case 'gradient_direction':
					$out[ $key ] = isset( self::gradient_directions()[ $value ] ) ? $value : $default;
					break;
				case 'caption_align':
					$out[ $key ] = in_array( $value, array( 'left', 'center', 'right' ), true ) ? $value : $default;
					break;
				case 'format':
					$out[ $key ] = in_array( $value, array( 'jpg', 'webp', 'png' ), true ) ? $value : $default;
					break;
				default:
					$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		if ( '' === trim( $out['name'] ) ) {
			$out['name'] = 'Без названия';
		}
		if ( $out['caption_min_size'] > $out['caption_size'] ) {
			$out['caption_min_size'] = $out['caption_size'];
		}

		return $out;
	}

	public static function sanitize_hex( $value, $default = '#000000' ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$clean = sanitize_hex_color( 0 === strpos( $value, '#' ) ? $value : '#' . $value );
		return $clean ? $clean : $default;
	}

	/** @return array{0:int,1:int,2:int} */
	public static function hex_to_rgb( $hex, $fallback = array( 0, 0, 0 ) ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return $fallback;
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/** Three starting points, so neither the feed form nor the screen is empty. */
	public static function seed_presets() {
		$base = self::schema();

		$split = wp_parse_args( array( 'name' => 'Картинка сверху, текст снизу' ), $base );

		$full = wp_parse_args(
			array(
				'name'                => 'Текст поверх картинки',
				'layout'              => 'full',
				'brightness'          => -10,
				'tint_hex'            => '#0b1220',
				'tint_alpha'          => 30,
				'gradient_enabled'    => 1,
				'gradient_direction'  => 'to-bottom',
				'gradient_from_alpha' => 0,
				'gradient_to_alpha'   => 85,
				'caption_position'    => 'bottom-left',
				'caption_offset_y'    => 140,
				'shadow_enabled'      => 1,
				'footer_color'        => '#e2e8f0',
			),
			$base
		);

		$light = wp_parse_args(
			array(
				'name'          => 'Светлый, текст на плашке',
				'layout'        => 'split',
				'split_ratio'   => 55,
				'panel_color'   => '#fff7ed',
				'background'    => '#fff7ed',
				'caption_color' => '#1f2937',
				'footer_color'  => '#6b7280',
				'accent_color'  => '#ea580c',
			),
			$base
		);

		return array(
			'pin-default' => $split,
			'pin-overlay' => $full,
			'pin-light'   => $light,
		);
	}
}
