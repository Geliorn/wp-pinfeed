<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a vertical pin from a source picture (or none) + a preset.
 *
 * Built on the same GD pipeline as LAA_Image_Composer in llm-agent-articles
 * (crop by focal point, filters, tint, gradient, logo, caption with autofit,
 * plate, shadow, border), reshaped for pins:
 *  - "split" layout: the picture takes the top N% of the canvas and the
 *    caption sits on a solid panel below — the usual Pinterest look, and the
 *    only way a 3:2 cover survives on a 2:3 canvas without losing most of it;
 *  - an accent bar above the caption and a footer line (domain, stars...);
 *  - no source at all = a text-only pin on the preset background.
 *
 * GD only, for the same reason as there: present with FreeType on every host
 * of the network, one implementation, one set of bugs.
 */
class WPPF_Image_Composer {

	/** GD alpha is inverted and 0..127, not 0..255. */
	const GD_OPAQUE      = 0;
	const GD_TRANSPARENT = 127;

	/** Reference glyphs for a stable baseline across lines of different content. */
	const METRIC_SAMPLE = 'ХудЁjgy';

	public static function available() {
		return extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' );
	}

	public static function text_available() {
		return self::available() && function_exists( 'imagettftext' );
	}

	/**
	 * @param array       $preset
	 * @param string|null $source_path Absolute path to the picture, or null for a text-only pin.
	 * @param string      $caption
	 * @param string      $footer
	 * @param string      $out_path
	 * @return array{ok:bool,path:string,mime:string,error:string}
	 */
	public static function render( array $preset, $source_path, $caption, $footer, $out_path ) {
		if ( ! self::available() ) {
			return self::fail( 'Расширение GD недоступно — сборка пинов невозможна' );
		}

		$preset = wp_parse_args( $preset, WPPF_Image_Presets::schema() );
		$width  = (int) $preset['width'];
		$height = (int) $preset['height'];

		$source = null;
		if ( $source_path ) {
			if ( ! is_readable( $source_path ) ) {
				return self::fail( 'Исходная картинка не читается: ' . $source_path );
			}
			$source = @imagecreatefromstring( (string) file_get_contents( $source_path ) ); // phpcs:ignore
			if ( ! $source ) {
				return self::fail( 'Не удалось открыть картинку (неподдерживаемый формат): ' . basename( $source_path ) );
			}
		}

		$canvas = self::new_canvas( $width, $height, $preset['background'] );

		// Picture area and text area, by layout.
		$split = $source && 'split' === $preset['layout'];
		if ( $split ) {
			$pic_h = (int) round( $height * (int) $preset['split_ratio'] / 100 );
			$pic   = array( 0, 0, $width, $pic_h );
			$text  = array( 0, $pic_h, $width, $height - $pic_h );
			self::fill_rect( $canvas, $text, $preset['panel_color'] ? $preset['panel_color'] : $preset['background'] );
		} else {
			$pic  = array( 0, 0, $width, $height );
			$text = $pic;
		}

		if ( $source ) {
			$layer = self::new_canvas( $pic[2], $pic[3], $preset['background'] );
			self::draw_source( $layer, $source, $preset );
			imagedestroy( $source );
			self::apply_filters( $layer, $preset );
			self::apply_tint( $layer, $preset );
			imagealphablending( $canvas, true );
			imagecopy( $canvas, $layer, $pic[0], $pic[1], 0, 0, $pic[2], $pic[3] );
			imagedestroy( $layer );
		}

		self::apply_gradient( $canvas, $preset );
		self::apply_logo( $canvas, $preset );

		$notes = array();
		$font  = self::resolve_font( $preset );
		if ( ! self::text_available() ) {
			$notes[] = 'GD собран без FreeType — текст на пине не нанесён';
		} elseif ( '' === $font ) {
			$notes[] = 'Не найден TTF-шрифт — пин собран без текста';
		} else {
			$footer_h = 0;
			if ( $preset['footer_enabled'] && '' !== trim( (string) $footer ) ) {
				$footer_h = self::apply_footer( $canvas, $preset, $font, $footer, $text );
			}
			if ( $preset['caption_enabled'] && '' !== trim( (string) $caption ) ) {
				self::apply_caption( $canvas, $preset, $font, $caption, $text, $footer_h );
			}
		}

		self::apply_border( $canvas, $preset );

		$saved = self::save( $canvas, $preset, $out_path );
		imagedestroy( $canvas );

		if ( $saved['ok'] ) {
			$saved['error'] = implode( '; ', $notes );
		}
		return $saved;
	}

	// --------------------------------------------------------------- canvas

	private static function new_canvas( $width, $height, $background ) {
		$canvas = imagecreatetruecolor( max( 1, $width ), max( 1, $height ) );
		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );

		$rgb = WPPF_Image_Presets::hex_to_rgb( $background, array( 17, 24, 39 ) );
		$bg  = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::GD_OPAQUE );
		imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, $bg );

		imagealphablending( $canvas, true );
		return $canvas;
	}

	/** @param array $rect x, y, w, h */
	private static function fill_rect( $canvas, array $rect, $hex ) {
		$rgb   = WPPF_Image_Presets::hex_to_rgb( $hex, array( 17, 24, 39 ) );
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::GD_OPAQUE );
		imagefilledrectangle( $canvas, $rect[0], $rect[1], $rect[0] + $rect[2] - 1, $rect[1] + $rect[3] - 1, $color );
	}

	/** Scales the source to cover (and crops by the focal point) or to contain. */
	private static function draw_source( $canvas, $source, array $preset ) {
		$cw = imagesx( $canvas );
		$ch = imagesy( $canvas );
		$sw = imagesx( $source );
		$sh = imagesy( $source );

		if ( $sw < 1 || $sh < 1 ) {
			return;
		}

		if ( 'contain' === $preset['fit'] ) {
			$scale = min( $cw / $sw, $ch / $sh );
			$dw    = max( 1, (int) round( $sw * $scale ) );
			$dh    = max( 1, (int) round( $sh * $scale ) );
			list( $dx, $dy ) = self::place( $preset['focal'], $cw, $ch, $dw, $dh, 0, 0 );
			imagecopyresampled( $canvas, $source, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh );
			return;
		}

		$scale = max( $cw / $sw, $ch / $sh );
		$vis_w = min( $sw, (int) ceil( $cw / $scale ) );
		$vis_h = min( $sh, (int) ceil( $ch / $scale ) );
		list( $sx, $sy ) = self::place( $preset['focal'], $sw, $sh, $vis_w, $vis_h, 0, 0 );

		imagecopyresampled( $canvas, $source, 0, 0, $sx, $sy, $cw, $ch, $vis_w, $vis_h );
	}

	/**
	 * Top-left coordinate for a $iw×$ih box inside a $cw×$ch area, per the
	 * nine-point grid plus an offset.
	 */
	public static function place( $position, $cw, $ch, $iw, $ih, $offset_x = 0, $offset_y = 0 ) {
		$parts = explode( '-', (string) $position );
		$v     = $parts[0] ?? 'middle';
		$h     = $parts[1] ?? 'center';

		switch ( $h ) {
			case 'left':
				$x = $offset_x;
				break;
			case 'right':
				$x = $cw - $iw - $offset_x;
				break;
			default:
				$x = (int) round( ( $cw - $iw ) / 2 );
		}

		switch ( $v ) {
			case 'top':
				$y = $offset_y;
				break;
			case 'bottom':
				$y = $ch - $ih - $offset_y;
				break;
			default:
				$y = (int) round( ( $ch - $ih ) / 2 );
		}

		return array( (int) $x, (int) $y );
	}

	// -------------------------------------------------------------- filters

	private static function apply_filters( $canvas, array $preset ) {
		if ( $preset['grayscale'] ) {
			imagefilter( $canvas, IMG_FILTER_GRAYSCALE );
		}
		if ( 0 !== (int) $preset['brightness'] ) {
			imagefilter( $canvas, IMG_FILTER_BRIGHTNESS, (int) $preset['brightness'] );
		}
		if ( 0 !== (int) $preset['contrast'] ) {
			imagefilter( $canvas, IMG_FILTER_CONTRAST, (int) $preset['contrast'] );
		}
		for ( $i = 0; $i < (int) $preset['blur']; $i++ ) {
			imagefilter( $canvas, IMG_FILTER_GAUSSIAN_BLUR );
		}
	}

	private static function apply_tint( $canvas, array $preset ) {
		$alpha = (int) $preset['tint_alpha'];
		if ( $alpha < 1 || '' === $preset['tint_hex'] ) {
			return;
		}
		$rgb   = WPPF_Image_Presets::hex_to_rgb( $preset['tint_hex'] );
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::alpha_to_gd( $alpha ) );
		imagealphablending( $canvas, true );
		imagefilledrectangle( $canvas, 0, 0, imagesx( $canvas ) - 1, imagesy( $canvas ) - 1, $color );
	}

	private static function apply_gradient( $canvas, array $preset ) {
		if ( ! $preset['gradient_enabled'] ) {
			return;
		}

		$w = imagesx( $canvas );
		$h = imagesy( $canvas );

		$from     = WPPF_Image_Presets::hex_to_rgb( $preset['gradient_from_hex'] );
		$to       = WPPF_Image_Presets::hex_to_rgb( $preset['gradient_to_hex'] );
		$from_a   = (int) $preset['gradient_from_alpha'];
		$to_a     = (int) $preset['gradient_to_alpha'];
		$vertical = in_array( $preset['gradient_direction'], array( 'to-bottom', 'to-top' ), true );
		$reverse  = in_array( $preset['gradient_direction'], array( 'to-top', 'to-left' ), true );

		$steps = $vertical ? $h : $w;
		if ( $steps < 2 ) {
			return;
		}

		imagealphablending( $canvas, true );

		for ( $i = 0; $i < $steps; $i++ ) {
			$t = $i / ( $steps - 1 );
			if ( $reverse ) {
				$t = 1 - $t;
			}

			$alpha = $from_a + ( $to_a - $from_a ) * $t;
			if ( $alpha < 0.5 ) {
				continue;
			}

			$color = imagecolorallocatealpha(
				$canvas,
				(int) round( $from[0] + ( $to[0] - $from[0] ) * $t ),
				(int) round( $from[1] + ( $to[1] - $from[1] ) * $t ),
				(int) round( $from[2] + ( $to[2] - $from[2] ) * $t ),
				self::alpha_to_gd( $alpha )
			);
			if ( $vertical ) {
				imageline( $canvas, 0, $i, $w - 1, $i, $color );
			} else {
				imageline( $canvas, $i, 0, $i, $h - 1, $color );
			}
		}
	}

	private static function apply_logo( $canvas, array $preset ) {
		$attachment_id = (int) $preset['logo_id'];
		if ( $attachment_id < 1 ) {
			return;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return;
		}

		$logo = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
		if ( ! $logo ) {
			return;
		}

		$cw = imagesx( $canvas );
		$ch = imagesy( $canvas );
		$lw = imagesx( $logo );
		$lh = imagesy( $logo );

		$target_w = max( 8, (int) round( $cw * (int) $preset['logo_width'] / 100 ) );
		$target_h = max( 8, (int) round( $lh * ( $target_w / max( 1, $lw ) ) ) );

		$layer = imagecreatetruecolor( $target_w, $target_h );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $target_w - 1, $target_h - 1, imagecolorallocatealpha( $layer, 0, 0, 0, self::GD_TRANSPARENT ) );
		imagecopyresampled( $layer, $logo, 0, 0, 0, 0, $target_w, $target_h, $lw, $lh );
		imagedestroy( $logo );

		$alpha = (int) $preset['logo_alpha'];
		if ( $alpha < 100 ) {
			self::scale_layer_alpha( $layer, $alpha / 100 );
		}

		list( $x, $y ) = self::place( $preset['logo_position'], $cw, $ch, $target_w, $target_h, (int) $preset['logo_offset_x'], (int) $preset['logo_offset_y'] );

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $layer, $x, $y, 0, 0, $target_w, $target_h );
		imagedestroy( $layer );
	}

	/** Multiplies every pixel's opacity by $factor (0..1), keeping its own alpha shape. */
	private static function scale_layer_alpha( $layer, $factor ) {
		$w = imagesx( $layer );
		$h = imagesy( $layer );

		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );

		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgba = imagecolorat( $layer, $x, $y );
				$a    = ( $rgba >> 24 ) & 0x7F;
				if ( self::GD_TRANSPARENT === $a ) {
					continue;
				}
				$opacity = ( self::GD_TRANSPARENT - $a ) * $factor;
				$color   = imagecolorallocatealpha( $layer, ( $rgba >> 16 ) & 0xFF, ( $rgba >> 8 ) & 0xFF, $rgba & 0xFF, (int) round( self::GD_TRANSPARENT - $opacity ) );
				imagesetpixel( $layer, $x, $y, $color );
			}
		}

		imagealphablending( $layer, true );
	}

	// ----------------------------------------------------------------- text

	/**
	 * Footer: one line, shrunk (never cut) to fit the width.
	 *
	 * @return int vertical space it takes from the bottom of the text area
	 *             (height + offset), so a bottom caption can sit above it.
	 */
	private static function apply_footer( $canvas, array $preset, $font, $footer, array $area ) {
		$footer = trim( wp_strip_all_tags( (string) $footer ) );
		if ( $preset['footer_uppercase'] ) {
			$footer = mb_strtoupper( $footer );
		}

		$size      = (int) $preset['footer_size'];
		$max_width = $area[2] - 2 * (int) $preset['footer_offset_x'];
		while ( $size > 10 && self::text_width( $font, $size, $footer ) > $max_width ) {
			$size -= 1;
		}

		$metrics = imagettfbbox( $size, 0, $font, self::METRIC_SAMPLE );
		$ascent  = $metrics ? abs( $metrics[7] ) : (int) round( $size * 0.8 );
		$line_h  = (int) round( $size * 1.3 );
		$line_w  = self::text_width( $font, $size, $footer );

		list( $x, $y ) = self::place( $preset['footer_position'], $area[2], $area[3], $line_w, $line_h, (int) $preset['footer_offset_x'], (int) $preset['footer_offset_y'] );

		$rgb   = WPPF_Image_Presets::hex_to_rgb( $preset['footer_color'], array( 203, 213, 225 ) );
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::GD_OPAQUE );
		imagealphablending( $canvas, true );
		imagettftext( $canvas, $size, 0, $area[0] + $x, $area[1] + $y + $ascent, $color, $font, $footer );

		return 0 === strpos( (string) $preset['footer_position'], 'bottom' ) ? $line_h + (int) $preset['footer_offset_y'] : 0;
	}

	private static function apply_caption( $canvas, array $preset, $font, $caption, array $area, $footer_h ) {
		$caption = trim( wp_strip_all_tags( (string) $caption ) );
		if ( $preset['caption_uppercase'] ) {
			$caption = mb_strtoupper( $caption );
		}

		$cw        = imagesx( $canvas );
		$max_width = (int) round( $cw * (int) $preset['caption_max_width'] / 100 );
		$max_width = min( $max_width, $area[2] - 2 * (int) $preset['caption_offset_x'] );
		$max_lines = (int) $preset['caption_max_lines'];

		$accent_h = $preset['accent_enabled'] && (int) $preset['accent_height'] > 0 ? (int) $preset['accent_height'] : 0;
		$accent_g = $accent_h ? max( 16, $accent_h * 3 ) : 0;

		// The caption must also fit the *height* of its area: on a split pin
		// the panel is short, and a 5-line caption at 64px would run off it.
		$offset_y   = (int) $preset['caption_offset_y'];
		$avail_h    = $area[3] - 2 * $offset_y - $footer_h - $accent_h - $accent_g;
		$size       = (int) $preset['caption_size'];
		$min        = (int) $preset['caption_min_size'];
		$lh_factor  = (float) $preset['caption_line_height'];
		$lines      = self::wrap( $font, $size, $caption, $max_width );

		if ( $preset['caption_autofit'] ) {
			while ( $size > $min && ( count( $lines ) > $max_lines || count( $lines ) * $size * $lh_factor > $avail_h ) ) {
				$size -= 2;
				$lines = self::wrap( $font, $size, $caption, $max_width );
			}
		}

		$fit_lines = max( 1, min( $max_lines, (int) floor( $avail_h / max( 1, $size * $lh_factor ) ) ) );
		if ( count( $lines ) > $fit_lines ) {
			$lines                   = array_slice( $lines, 0, $fit_lines );
			$lines[ $fit_lines - 1 ] = rtrim( $lines[ $fit_lines - 1 ], " \t.,;:-" ) . '…';
		}

		$line_height = max( 1, (int) round( $size * $lh_factor ) );
		$block_w     = 0;
		foreach ( $lines as $line ) {
			$block_w = max( $block_w, self::text_width( $font, $size, $line ) );
		}
		$text_h  = $line_height * count( $lines );
		$block_h = $text_h + $accent_h + $accent_g;

		$metrics = imagettfbbox( $size, 0, $font, self::METRIC_SAMPLE );
		$ascent  = $metrics ? abs( $metrics[7] ) : (int) round( $size * 0.8 );

		$is_bottom = 0 === strpos( (string) $preset['caption_position'], 'bottom' );
		list( $bx, $by ) = self::place(
			$preset['caption_position'],
			$area[2],
			$area[3] - ( $is_bottom ? $footer_h : 0 ),
			$block_w,
			$block_h,
			(int) $preset['caption_offset_x'],
			$offset_y
		);
		$bx += $area[0];
		$by += $area[1];

		imagealphablending( $canvas, true );

		if ( $preset['plate_enabled'] ) {
			$pad = (int) $preset['plate_padding'];
			self::rounded_rect( $canvas, $bx - $pad, $by - $pad, $bx + $block_w + $pad, $by + $block_h + $pad, (int) $preset['plate_radius'], WPPF_Image_Presets::hex_to_rgb( $preset['plate_color'] ), (int) $preset['plate_alpha'] );
		}

		if ( $accent_h ) {
			$aw = (int) $preset['accent_width'];
			switch ( $preset['caption_align'] ) {
				case 'center':
					$ax = $bx + (int) round( ( $block_w - $aw ) / 2 );
					break;
				case 'right':
					$ax = $bx + $block_w - $aw;
					break;
				default:
					$ax = $bx;
			}
			$rgb = WPPF_Image_Presets::hex_to_rgb( $preset['accent_color'], array( 230, 0, 35 ) );
			imagefilledrectangle( $canvas, $ax, $by, $ax + $aw - 1, $by + $accent_h - 1, imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::GD_OPAQUE ) );
		}

		$text_rgb   = WPPF_Image_Presets::hex_to_rgb( $preset['caption_color'], array( 255, 255, 255 ) );
		$text_color = imagecolorallocatealpha( $canvas, $text_rgb[0], $text_rgb[1], $text_rgb[2], self::GD_OPAQUE );

		$shadow_color = null;
		if ( $preset['shadow_enabled'] && (int) $preset['shadow_alpha'] > 0 ) {
			$shadow_rgb   = WPPF_Image_Presets::hex_to_rgb( $preset['shadow_color'] );
			$shadow_color = imagecolorallocatealpha( $canvas, $shadow_rgb[0], $shadow_rgb[1], $shadow_rgb[2], self::alpha_to_gd( (int) $preset['shadow_alpha'] ) );
		}

		$text_top = $by + $accent_h + $accent_g;
		foreach ( $lines as $i => $line ) {
			$line_w = self::text_width( $font, $size, $line );
			switch ( $preset['caption_align'] ) {
				case 'center':
					$x = $bx + (int) round( ( $block_w - $line_w ) / 2 );
					break;
				case 'right':
					$x = $bx + ( $block_w - $line_w );
					break;
				default:
					$x = $bx;
			}
			$y = $text_top + $ascent + $i * $line_height;

			if ( $shadow_color ) {
				imagettftext( $canvas, $size, 0, $x + (int) $preset['shadow_offset_x'], $y + (int) $preset['shadow_offset_y'], $shadow_color, $font, $line );
			}
			imagettftext( $canvas, $size, 0, $x, $y, $text_color, $font, $line );
		}
	}

	public static function text_width( $font, $size, $text ) {
		if ( '' === $text ) {
			return 0;
		}
		$bbox = imagettfbbox( $size, 0, $font, $text );
		return $bbox ? (int) abs( $bbox[2] - $bbox[0] ) : 0;
	}

	/** Greedy word wrap; a single word wider than the box is split at punctuation, then by characters. */
	public static function wrap( $font, $size, $text, $max_width ) {
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$lines = array();
		$line  = '';

		foreach ( (array) $words as $word ) {
			$candidate = '' === $line ? $word : $line . ' ' . $word;
			if ( self::text_width( $font, $size, $candidate ) <= $max_width ) {
				$line = $candidate;
				continue;
			}

			if ( '' !== $line ) {
				$lines[] = $line;
				$line    = '';
			}

			if ( self::text_width( $font, $size, $word ) <= $max_width ) {
				$line = $word;
				continue;
			}

			// Too wide for a line: break after "/", "-", "_" or "." first
			// ("owner/ repo"), and only split by characters what still won't fit.
			$chunk = '';
			foreach ( (array) preg_split( '/(?<=[\/\-_.])/u', $word, -1, PREG_SPLIT_NO_EMPTY ) as $piece ) {
				if ( '' !== $chunk && self::text_width( $font, $size, $chunk . $piece ) > $max_width ) {
					$lines[] = $chunk;
					$chunk   = '';
				}
				if ( self::text_width( $font, $size, $piece ) <= $max_width ) {
					$chunk .= $piece;
					continue;
				}
				foreach ( (array) preg_split( '//u', $piece, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
					if ( '' !== $chunk && self::text_width( $font, $size, $chunk . $char ) > $max_width ) {
						$lines[] = $chunk;
						$chunk   = '';
					}
					$chunk .= $char;
				}
			}
			$line = $chunk;
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return empty( $lines ) ? array( '' ) : $lines;
	}

	/**
	 * Font lookup, most specific first: the preset, the global setting, the
	 * font folders of this plugin and of llm-agent-articles (one upload
	 * serves both), then the usual system faces. Theme fonts are woff2 and
	 * GD can't read those.
	 */
	public static function resolve_font( array $preset = array() ) {
		$candidates = array();

		if ( ! empty( $preset['caption_font'] ) ) {
			$candidates[] = $preset['caption_font'];
		}
		$configured = (string) WPPF_Settings::get( 'caption_font_path', '' );
		if ( '' !== $configured ) {
			$candidates[] = $configured;
		}

		foreach ( self::uploaded_fonts() as $font ) {
			$candidates[] = $font['path'];
		}

		$candidates = array_merge(
			$candidates,
			array(
				'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
				'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
				'/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
				'/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
				'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
				'/usr/share/fonts/truetype/droid/DroidSans-Bold.ttf',
				'/System/Library/Fonts/Supplemental/Arial Bold.ttf',
			)
		);

		foreach ( $candidates as $path ) {
			$path = self::normalise_font_path( $path );
			if ( '' !== $path && is_readable( $path ) ) {
				return $path;
			}
		}

		foreach ( (array) glob( '/usr/share/fonts/truetype/*/*.ttf' ) as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	private static function normalise_font_path( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return '';
		}
		if ( 0 === strpos( $path, '/' ) ) {
			return $path;
		}
		return trailingslashit( self::fonts_dir() ) . basename( $path );
	}

	public static function fonts_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'wppf-fonts';
	}

	/** @return array<int,array{name:string,path:string}> */
	public static function uploaded_fonts() {
		$upload = wp_upload_dir();
		$dirs   = array( self::fonts_dir(), trailingslashit( $upload['basedir'] ) . 'laa-fonts' );
		$out    = array();
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( trailingslashit( $dir ) . '*.{ttf,otf,TTF,OTF}', GLOB_BRACE ) as $path ) {
				if ( $path ) {
					$out[] = array(
						'name' => basename( $path ),
						'path' => $path,
					);
				}
			}
		}
		return $out;
	}

	// ------------------------------------------------------- frame & output

	private static function apply_border( $canvas, array $preset ) {
		$width = (int) $preset['border_width'];
		if ( $width < 1 ) {
			return;
		}

		$inset = (int) $preset['border_inset'];
		$rgb   = WPPF_Image_Presets::hex_to_rgb( $preset['border_color'], array( 255, 255, 255 ) );
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::GD_OPAQUE );

		$x1 = $inset;
		$y1 = $inset;
		$x2 = imagesx( $canvas ) - 1 - $inset;
		$y2 = imagesy( $canvas ) - 1 - $inset;
		if ( $x2 <= $x1 || $y2 <= $y1 ) {
			return;
		}

		imagealphablending( $canvas, true );
		for ( $i = 0; $i < $width; $i++ ) {
			imagerectangle( $canvas, $x1 + $i, $y1 + $i, $x2 - $i, $y2 - $i, $color );
		}
	}

	/** Filled rectangle with rounded corners, on its own layer so the arcs don't double-blend. */
	private static function rounded_rect( $canvas, $x1, $y1, $x2, $y2, $radius, array $rgb, $alpha_pct ) {
		$x1 = max( 0, (int) $x1 );
		$y1 = max( 0, (int) $y1 );
		$x2 = min( imagesx( $canvas ) - 1, (int) $x2 );
		$y2 = min( imagesy( $canvas ) - 1, (int) $y2 );

		$w = $x2 - $x1 + 1;
		$h = $y2 - $y1 + 1;
		if ( $w < 2 || $h < 2 ) {
			return;
		}

		$radius = max( 0, min( (int) $radius, (int) floor( min( $w, $h ) / 2 ) ) );

		$layer = imagecreatetruecolor( $w, $h );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $layer, 0, 0, 0, self::GD_TRANSPARENT ) );

		$color = imagecolorallocatealpha( $layer, $rgb[0], $rgb[1], $rgb[2], self::alpha_to_gd( $alpha_pct ) );

		if ( $radius < 1 ) {
			imagefilledrectangle( $layer, 0, 0, $w - 1, $h - 1, $color );
		} else {
			imagefilledrectangle( $layer, $radius, 0, $w - 1 - $radius, $h - 1, $color );
			imagefilledrectangle( $layer, 0, $radius, $w - 1, $h - 1 - $radius, $color );
			$d = $radius * 2;
			imagefilledellipse( $layer, $radius, $radius, $d, $d, $color );
			imagefilledellipse( $layer, $w - 1 - $radius, $radius, $d, $d, $color );
			imagefilledellipse( $layer, $radius, $h - 1 - $radius, $d, $d, $color );
			imagefilledellipse( $layer, $w - 1 - $radius, $h - 1 - $radius, $d, $d, $color );
		}

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $layer, $x1, $y1, 0, 0, $w, $h );
		imagedestroy( $layer );
	}

	private static function save( $canvas, array $preset, $out_path ) {
		$dir = dirname( $out_path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::fail( 'Не удалось создать каталог для картинки: ' . $dir );
		}

		switch ( $preset['format'] ) {
			case 'png':
				imagesavealpha( $canvas, true );
				$ok   = imagepng( $canvas, $out_path, (int) round( 9 - ( (int) $preset['quality'] / 100 ) * 9 ) );
				$mime = 'image/png';
				break;
			case 'webp':
				if ( ! function_exists( 'imagewebp' ) ) {
					return self::fail( 'GD собран без поддержки WebP — выберите другой формат' );
				}
				$ok   = imagewebp( $canvas, $out_path, (int) $preset['quality'] );
				$mime = 'image/webp';
				break;
			default:
				$ok   = imagejpeg( $canvas, $out_path, (int) $preset['quality'] );
				$mime = 'image/jpeg';
		}

		if ( ! $ok ) {
			return self::fail( 'Не удалось записать файл картинки: ' . $out_path );
		}

		return array(
			'ok'    => true,
			'path'  => $out_path,
			'mime'  => $mime,
			'error' => '',
		);
	}

	public static function extension_for( $format ) {
		return in_array( $format, array( 'png', 'webp' ), true ) ? $format : 'jpg';
	}

	/** Percent opacity (0..100) -> GD alpha (127..0). */
	private static function alpha_to_gd( $percent ) {
		$percent = max( 0, min( 100, (float) $percent ) );
		return (int) round( self::GD_TRANSPARENT * ( 1 - $percent / 100 ) );
	}

	private static function fail( $error ) {
		return array(
			'ok'    => false,
			'path'  => '',
			'mime'  => '',
			'error' => $error,
		);
	}
}
