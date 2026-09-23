<?php
/**
 * AI-fix flow for uninformative link text (USHER_V1_SPEC.md §2.5: "AI не
 * потрібен для детекції, лише опційно для пропозиції кращого тексту").
 * Text-only prompt - no image needed.
 *
 * Applying this one is different from the other two AI-fixes: an anchor's
 * visible text is a text *node*, not an attribute, and
 * WP_HTML_Tag_Processor only reads/writes attributes - it cannot replace
 * inner text. This locates the exact <a>...</a> occurrence with the same
 * position-tracked substring scan usher_check_link_text() itself uses to
 * read that text in the first place, then splices the replacement in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $current_text Current (unhelpful) link text.
 * @param string $href         The link's destination URL.
 * @return string
 */
function usher_build_link_text_prompt( $current_text, $href ) {
	return sprintf(
		'A hyperlink\'s visible text does not describe where it goes, which is an accessibility problem (screen reader users often navigate by a list of link text alone). '
			. "Current link text: \"%s\". The link's destination URL: %s. "
			. 'Suggest a short, specific replacement (3-6 words) that describes the destination, inferring what it likely is from the URL. '
			. 'Reply with only the replacement text itself, nothing else, no quotes.',
		$current_text,
		$href
	);
}

/**
 * @param int   $post_id
 * @param array $finding A finding from usher_check_link_text().
 * @return string|WP_Error
 */
function usher_generate_link_text_suggestion( $post_id, $finding ) {
	if ( ! usher_ai_is_configured() ) {
		return new WP_Error( 'usher_ai_not_configured', __( 'No AI provider is configured yet. Add an API key in Usher settings.', 'usher' ) );
	}

	$provider = usher_get_current_provider();
	$prompt   = usher_build_link_text_prompt( $finding['link_text'] ?? '', $finding['href'] ?? '' );

	$response = usher_ai_call_vision( $provider, usher_get_model_for_provider( $provider ), $prompt, null, usher_get_api_key_for_provider( $provider ), array( 'max_tokens' => 40 ) );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$text = trim( $response, " \t\n\r\0\x0B\"'" );
	if ( '' === $text ) {
		return new WP_Error( 'usher_ai_empty_response', __( 'The AI provider returned an empty suggestion.', 'usher' ) );
	}

	return $text;
}

/**
 * Re-locates the exact <a> occurrence a link-text finding refers to (same
 * algorithm as usher_check_link_text(), stopping at the matching
 * instance_key instead of collecting all of them) and replaces its inner
 * text.
 *
 * @param int    $post_id
 * @param string $instance_key
 * @param string $new_text
 * @return true|WP_Error
 */
function usher_apply_link_text_fix( $post_id, $instance_key, $new_text ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'usher_post_not_found', __( 'No post exists with that ID.', 'usher' ) );
	}
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return new WP_Error( 'usher_no_tag_processor', __( 'This WordPress version does not support the required HTML processor.', 'usher' ) );
	}

	$html        = $post->post_content;
	$html_lower  = strtolower( $html );
	$processor   = new WP_HTML_Tag_Processor( $html );
	$text_cursor = 0;
	$position    = 0;

	while ( $processor->next_tag( 'a' ) ) {
		$href    = (string) $processor->get_attribute( 'href' );
		$open_at = strpos( $html_lower, '<a', $text_cursor );
		$text    = '';
		$open_end = false;
		$close_at = false;

		if ( false !== $open_at ) {
			$open_end = strpos( $html, '>', $open_at );
			$close_at = false !== $open_end ? stripos( $html, '</a', $open_end ) : false;
			if ( false !== $open_end && false !== $close_at ) {
				$inner       = substr( $html, $open_end + 1, $close_at - $open_end - 1 );
				$text        = trim( wp_strip_all_tags( $inner ) );
				$text_cursor = $close_at + 3;
			} else {
				$text_cursor = $open_at + 2;
			}
		}
		++$position;

		$key = md5( 'a|' . $position . '|' . $text . '|' . $href );
		if ( $key !== $instance_key || false === $open_end || false === $close_at ) {
			continue;
		}

		$updated_html = substr( $html, 0, $open_end + 1 )
			. esc_html( $new_text )
			. substr( $html, $close_at );

		return wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $updated_html,
			),
			true
		);
	}

	return new WP_Error( 'usher_finding_not_found', __( 'This link could not be found in the current content - it may have already changed since the last scan.', 'usher' ) );
}
