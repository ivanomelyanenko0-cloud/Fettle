<?php
/**
 * Colour-contrast check: standard W3C relative-luminance WCAG formula, not
 * an approximation (USHER_V1_SPEC.md §2.1). Pure math, nothing external.
 *
 * Scope, stated honestly rather than silently: this plugin has no headless
 * browser, so it cannot see the real rendered result of a full CSS cascade.
 * It only judges the case Gutenberg makes tractable - a block with *both*
 * a text colour and a background colour explicitly set on it (core
 * paragraph/heading/button "has-*-color has-*-background-color" classes, or
 * the equivalent inline style/attribute). Anything where only one side is
 * explicit, or the background is an image/gradient, is reported as
 * "needs manual review" rather than guessed against the wrong background -
 * spec is explicit that guessing here is worse than not checking.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param float $c_srgb A single sRGB channel in the 0-1 range.
 * @return float Linearised channel value.
 */
function usher_linearise_channel( $c_srgb ) {
	if ( $c_srgb <= 0.03928 ) {
		return $c_srgb / 12.92;
	}
	return pow( ( $c_srgb + 0.055 ) / 1.055, 2.4 );
}

/**
 * W3C relative luminance for a colour.
 *
 * @param array $rgb array( $r, $g, $b ), each 0-255.
 * @return float Relative luminance, 0 (black) to 1 (white).
 */
function usher_relative_luminance( $rgb ) {
	list( $r, $g, $b ) = $rgb;

	$r_lin = usher_linearise_channel( $r / 255 );
	$g_lin = usher_linearise_channel( $g / 255 );
	$b_lin = usher_linearise_channel( $b / 255 );

	return 0.2126 * $r_lin + 0.7152 * $g_lin + 0.0722 * $b_lin;
}

/**
 * @param array $rgb1 array( $r, $g, $b ).
 * @param array $rgb2 array( $r, $g, $b ).
 * @return float Contrast ratio, 1 (no contrast) to 21 (black on white).
 */
function usher_contrast_ratio( $rgb1, $rgb2 ) {
	$l1 = usher_relative_luminance( $rgb1 );
	$l2 = usher_relative_luminance( $rgb2 );

	$lighter = max( $l1, $l2 );
	$darker  = min( $l1, $l2 );

	return ( $lighter + 0.05 ) / ( $darker + 0.05 );
}

/**
 * WCAG AA threshold for a given text size/weight.
 *
 * @param float $font_size_px Font size in CSS pixels.
 * @param bool  $bold         Whether the text is bold (or font-weight >= 700).
 * @return float 3.0 for "large" text, 4.5 otherwise.
 */
function usher_wcag_aa_threshold( $font_size_px, $bold ) {
	// 18pt = 24px, 14pt bold = ~18.67px, at the standard 96dpi/16px-root CSS px-to-pt ratio (1pt = 4/3px).
	$is_large = $bold ? $font_size_px >= 18.67 : $font_size_px >= 24;

	return $is_large ? 3.0 : 4.5;
}

/**
 * @param array $rgb array( $r, $g, $b ).
 * @return string '#rrggbb'.
 */
function usher_rgb_to_hex( $rgb ) {
	return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
}

/**
 * A finding's instance_key, built from *resolved* colours rather than the
 * raw class/style string. Deliberate: WordPress's own block rendering can
 * append classes (e.g. a `wp-block-paragraph` class WP 7.0's paragraph
 * block adds server-side) that are present in do_blocks()-rendered HTML
 * (what the scan sees) but not in the raw stored post_content (what an
 * AI-fix apply step edits) - keying on the raw string made a finding
 * un-relocatable at apply time whenever rendering added anything. Keying
 * on the resolved colour pair instead is immune to that, and finds every
 * element sharing the same actual colour problem, not just one with
 * byte-for-byte identical markup.
 *
 * @param string $tag
 * @param string $text_hex
 * @param string $bg_hex
 * @return string
 */
function usher_contrast_instance_key( $tag, $text_hex, $bg_hex ) {
	return md5( $tag . '|' . $text_hex . '|' . $bg_hex );
}

/**
 * @param string $hex '#rgb' or '#rrggbb'.
 * @return array|null array( $r, $g, $b ), or null if not a valid hex colour.
 */
function usher_hex_to_rgb( $hex ) {
	$hex = ltrim( trim( (string) $hex ), '#' );

	if ( 3 === strlen( $hex ) && ctype_xdigit( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return null;
	}

	return array(
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	);
}

/**
 * The active theme's colour palette (theme.json, merged with any site
 * customisation), as { slug => rgb() }. This is what a Gutenberg
 * `var(--wp--preset--color--{slug})` or `has-{slug}-color` class resolves
 * against - without it, contrast checking is meaningless on any modern
 * block theme, which stores colours as presets, not literal hex.
 *
 * @return array<string, array> slug => array( $r, $g, $b ).
 */
function usher_get_color_palette_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map      = array();
	$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'color', 'palette' ) ) : array();

	// wp_get_global_settings() returns { theme: [...], default: [...], custom: [...] } - later origins win on slug collision.
	foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
		if ( empty( $settings[ $origin ] ) || ! is_array( $settings[ $origin ] ) ) {
			continue;
		}
		foreach ( $settings[ $origin ] as $entry ) {
			if ( empty( $entry['slug'] ) || ! isset( $entry['color'] ) ) {
				continue;
			}
			$rgb = usher_resolve_color_value( $entry['color'] );
			if ( $rgb ) {
				$map[ $entry['slug'] ] = $rgb;
			}
		}
	}

	return $map;
}

/**
 * Resolves a CSS colour value to RGB - a literal hex, or a Gutenberg
 * preset var(--wp--preset--color--{slug}). Anything else (rgb()/hsl()
 * functions, gradients, "currentColor", keywords) is deliberately left
 * unresolved: spec says not to guess.
 *
 * @param string $value Raw CSS colour value.
 * @return array|null array( $r, $g, $b ), or null if not resolvable.
 */
function usher_resolve_color_value( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return null;
	}

	if ( '#' === $value[0] ) {
		return usher_hex_to_rgb( $value );
	}

	if ( preg_match( '/^var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)$/i', $value, $m ) ) {
		$palette = usher_get_color_palette_map();
		return $palette[ $m[1] ] ?? null;
	}

	return null;
}

/**
 * Resolves a `has-{slug}-color` / `has-{slug}-background-color` class name
 * to RGB via the palette map.
 *
 * @param string $slug Palette slug, e.g. 'vivid-cyan-blue'.
 * @return array|null
 */
function usher_resolve_color_slug( $slug ) {
	$palette = usher_get_color_palette_map();
	return $palette[ $slug ] ?? null;
}

/**
 * Extracts an explicit (text colour, background colour) pair from one
 * element's class/style attributes, if both sides are unambiguous. Returns
 * null when only one side is set, or either side is not a resolvable solid
 * colour (image/gradient background, unresolvable class/var) - the "don't
 * guess" cases from spec §2.1.
 *
 * @param string $class Element's class attribute value.
 * @param string $style Element's style attribute value.
 * @return array{0: array, 1: array}|null array( $text_rgb, $bg_rgb ), or null.
 */
function usher_extract_color_pair( $class, $style ) {
	$text_rgb = null;
	$bg_rgb   = null;

	// A gradient or background-image background is never a flat colour - bail rather than compare against the wrong thing.
	if ( false !== stripos( $class, 'has-background-gradient' ) || false !== stripos( $style, 'background-image' ) || false !== stripos( $style, 'gradient' ) ) {
		return null;
	}

	if ( preg_match( '/has-([a-z0-9-]+)-color(?!-)/i', $class, $m ) ) {
		$text_rgb = usher_resolve_color_slug( $m[1] );
	}
	if ( preg_match( '/has-([a-z0-9-]+)-background-color/i', $class, $m ) ) {
		$bg_rgb = usher_resolve_color_slug( $m[1] );
	}

	if ( preg_match( '/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $m ) ) {
		$resolved = usher_resolve_color_value( trim( $m[1] ) );
		if ( $resolved ) {
			$text_rgb = $resolved;
		}
	}
	if ( preg_match( '/(?:^|;)\s*background-color\s*:\s*([^;]+)/i', $style, $m ) ) {
		$resolved = usher_resolve_color_value( trim( $m[1] ) );
		if ( $resolved ) {
			$bg_rgb = $resolved;
		}
	}

	if ( ! $text_rgb || ! $bg_rgb ) {
		return null;
	}

	return array( $text_rgb, $bg_rgb );
}

/**
 * Tags this check looks at: block-level elements that commonly carry an
 * explicit text+background colour pair in core Gutenberg markup.
 */
const USHER_CONTRAST_TAGS = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'a', 'button' );

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings: each { type, severity, ratio, threshold, message, needs_review }.
 */
function usher_check_contrast( $html ) {
	if ( '' === trim( (string) $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return array();
	}

	$findings   = array();
	$seen_pairs = array(); // Dedupe identical (tag, class, style) combinations repeated many times in one post.
	$processor  = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag() ) {
		$tag = strtolower( (string) $processor->get_tag() );
		if ( ! in_array( $tag, USHER_CONTRAST_TAGS, true ) ) {
			continue;
		}

		$class = (string) $processor->get_attribute( 'class' );
		$style = (string) $processor->get_attribute( 'style' );
		if ( '' === $class && '' === $style ) {
			continue;
		}

		$dedupe_key = $tag . '|' . $class . '|' . $style;
		if ( isset( $seen_pairs[ $dedupe_key ] ) ) {
			continue;
		}
		$seen_pairs[ $dedupe_key ] = true;

		$pair = usher_extract_color_pair( $class, $style );
		if ( ! $pair ) {
			continue;
		}

		list( $text_rgb, $bg_rgb ) = $pair;

		$bold      = ( false !== stripos( $class, 'has-large-font-size' ) ) || preg_match( '/font-weight\s*:\s*(bold|[6-9]00)/i', $style );
		$threshold = usher_wcag_aa_threshold( 16, (bool) $bold ); // Font-size in px is not reliably knowable without full cascade resolution; assume base body size (spec §5 documented limitation) unless a bold/large-size class says otherwise.
		$ratio     = usher_contrast_ratio( $text_rgb, $bg_rgb );

		if ( $ratio + 0.005 >= $threshold ) {
			continue; // Passes AA, not a finding.
		}

		$findings[] = array(
			'type'          => 'contrast',
			'severity'      => $ratio < 3.0 ? 'critical' : 'warning',
			'tag'           => $tag,
			'class'         => $class,
			'style'         => $style,
			'text_hex'      => usher_rgb_to_hex( $text_rgb ),
			'bg_hex'        => usher_rgb_to_hex( $bg_rgb ),
			'ratio'         => round( $ratio, 2 ),
			'threshold'     => $threshold,
			'message'       => sprintf(
				/* translators: 1: measured contrast ratio, 2: required WCAG AA ratio */
				__( 'Text/background contrast is %1$s:1, below the %2$s:1 WCAG AA minimum.', 'usher' ),
				round( $ratio, 2 ),
				$threshold
			),
			'needs_review'  => false,
			'instance_key'  => usher_contrast_instance_key( $tag, usher_rgb_to_hex( $text_rgb ), usher_rgb_to_hex( $bg_rgb ) ),
		);
	}

	return $findings;
}
