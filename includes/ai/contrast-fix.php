<?php
/**
 * AI-fix flow for low contrast: AI suggests a colour, a separate
 * deterministic function verifies it actually passes AA rather than
 * trusting the model's own arithmetic. Text-only prompt - no image needed,
 * unlike the alt-text fix.
 *
 * Simplification, deliberate: only the *text* colour is ever adjusted,
 * never the background. Changing a background risks a much bigger visual
 * change (and, on some blocks, would need touching more than one element's
 * style); adjusting text colour against a fixed background is the smaller,
 * safer edit and is what most real low-contrast cases actually need.
 *
 * Because usher_check_contrast() already deduplicates identical (tag,
 * class, style) combinations into one finding, applying a fix here updates
 * *every* element in the post that shares that exact combination, not just
 * one - the same set of elements the one finding represents.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $bg_hex     Current background colour, e.g. '#ffffff'.
 * @param string $text_hex   Current (failing) text colour.
 * @param float  $threshold  Required contrast ratio.
 * @return string
 */
function usher_build_contrast_prompt( $bg_hex, $text_hex, $threshold ) {
	return sprintf(
		"A text colour needs to be changed so it has at least a %s:1 contrast ratio against a fixed background colour, per WCAG 2.1. "
			. "Background colour (do not change this): %s. Current text colour (fails the contrast requirement): %s. "
			. 'Suggest a replacement text colour that passes the requirement and stays reasonably close in hue to the original where possible. '
			. 'Reply with only a single 6-digit hex colour code, nothing else, e.g. "#1a1a1a".',
		$threshold,
		$bg_hex,
		$text_hex
	);
}

/**
 * @param string $text Raw model response.
 * @return string|null '#rrggbb', or null if no hex colour could be parsed out.
 */
function usher_extract_hex_from_text( $text ) {
	if ( preg_match( '/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $text, $m ) ) {
		$rgb = usher_hex_to_rgb( $m[0] );
		return $rgb ? usher_rgb_to_hex( $rgb ) : null;
	}
	return null;
}

/**
 * Deterministic fallback when the AI's own suggestion does not actually
 * pass (or could not be parsed at all): pick whichever of pure black or
 * pure white contrasts better against the fixed background. For any
 * background colour, the better of the two always reaches at least
 * ~4.58:1 - a mathematical property of the WCAG luminance formula, not a
 * guess - so as a last resort it clears the "large text" 3:1 threshold
 * unconditionally and the "normal text" 4.5:1 threshold in all but the
 * most marginal case.
 *
 * @param array $bg_rgb
 * @return string '#rrggbb'.
 */
function usher_fallback_contrast_color( $bg_rgb ) {
	$black_ratio = usher_contrast_ratio( array( 0, 0, 0 ), $bg_rgb );
	$white_ratio = usher_contrast_ratio( array( 255, 255, 255 ), $bg_rgb );

	return $black_ratio >= $white_ratio ? '#000000' : '#ffffff';
}

/**
 * @param int   $post_id
 * @param array $finding A finding from usher_check_contrast().
 * @return array{color: string, used_fallback: bool}|WP_Error
 */
function usher_generate_contrast_suggestion( $post_id, $finding ) {
	if ( ! usher_ai_is_configured() ) {
		return new WP_Error( 'usher_ai_not_configured', __( 'No AI provider is configured yet. Add an API key in Usher settings.', 'usher' ) );
	}

	$bg_rgb = usher_hex_to_rgb( $finding['bg_hex'] ?? '' );
	if ( ! $bg_rgb ) {
		return new WP_Error( 'usher_invalid_finding', __( 'This finding is missing colour data - try scanning again.', 'usher' ) );
	}

	$provider = usher_get_current_provider();
	$prompt   = usher_build_contrast_prompt( $finding['bg_hex'], $finding['text_hex'], $finding['threshold'] );

	$response = usher_ai_call_vision( $provider, usher_get_model_for_provider( $provider ), $prompt, null, usher_get_api_key_for_provider( $provider ), array( 'max_tokens' => 20 ) );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$proposed = usher_extract_hex_from_text( $response );
	$rgb      = $proposed ? usher_hex_to_rgb( $proposed ) : null;

	// The whole point of spec's "own deterministic function checks it" instruction: never trust the model's arithmetic, verify with the same usher_contrast_ratio() the check itself uses.
	if ( $rgb && usher_contrast_ratio( $rgb, $bg_rgb ) + 0.005 >= $finding['threshold'] ) {
		return array( 'color' => $proposed, 'used_fallback' => false );
	}

	return array( 'color' => usher_fallback_contrast_color( $bg_rgb ), 'used_fallback' => true );
}

/**
 * @param string $style CSS declarations, e.g. 'color:#fff;background-color:#000'.
 * @param string $property
 * @param string $value
 * @return string Updated style string, existing declaration order preserved, others untouched.
 */
function usher_merge_style_declaration( $style, $property, $value ) {
	$declarations = array();
	foreach ( explode( ';', (string) $style ) as $part ) {
		$part = trim( $part );
		if ( '' === $part || false === strpos( $part, ':' ) ) {
			continue;
		}
		list( $name, $val ) = array_map( 'trim', explode( ':', $part, 2 ) );
		$declarations[ strtolower( $name ) ] = array( $name, $val );
	}

	$declarations[ strtolower( $property ) ] = array( $property, $value );

	$parts = array();
	foreach ( $declarations as $pair ) {
		$parts[] = $pair[0] . ':' . $pair[1];
	}

	return implode( ';', $parts );
}

/**
 * Applies a validated colour to every element in the post matching this
 * finding's exact (tag, class, style) combination.
 *
 * @param int    $post_id
 * @param string $instance_key
 * @param string $new_color '#rrggbb'.
 * @return true|WP_Error
 */
function usher_apply_contrast_fix( $post_id, $instance_key, $new_color ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'usher_post_not_found', __( 'No post exists with that ID.', 'usher' ) );
	}
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return new WP_Error( 'usher_no_tag_processor', __( 'This WordPress version does not support the required HTML processor.', 'usher' ) );
	}

	$processor = new WP_HTML_Tag_Processor( $post->post_content );
	$applied   = 0;

	while ( $processor->next_tag() ) {
		$tag = strtolower( (string) $processor->get_tag() );
		if ( ! in_array( $tag, USHER_CONTRAST_TAGS, true ) ) {
			continue;
		}

		$class = (string) $processor->get_attribute( 'class' );
		$style = (string) $processor->get_attribute( 'style' );

		// Match by resolved colour, not the raw class/style string - see usher_contrast_instance_key() for why (rendering can add classes not present in raw post_content).
		$pair = usher_extract_color_pair( $class, $style );
		if ( ! $pair ) {
			continue;
		}
		list( $text_rgb, $bg_rgb ) = $pair;
		$key = usher_contrast_instance_key( $tag, usher_rgb_to_hex( $text_rgb ), usher_rgb_to_hex( $bg_rgb ) );

		if ( $key !== $instance_key ) {
			continue;
		}

		$processor->set_attribute( 'style', usher_merge_style_declaration( $style, 'color', $new_color ) );
		++$applied;
	}

	if ( 0 === $applied ) {
		return new WP_Error( 'usher_finding_not_found', __( 'This element could not be found in the current content - it may have already changed since the last scan.', 'usher' ) );
	}

	return wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $processor->get_updated_html(),
		),
		true
	);
}
