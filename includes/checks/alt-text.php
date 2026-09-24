<?php
/**
 * Missing alt-text check - the check this plugin's entire AI-fix flow is
 * built around (alt text is generated from just the image plus its
 * neighbouring paragraph). Added here so the AI-fix flow has an actual
 * check producing alt-text findings to fix, not just a generation path
 * with nothing feeding it.
 *
 * WCAG-correct on purpose: `alt=""` (empty, but present) is a deliberate,
 * valid way to mark an image decorative - not a violation. Only a
 * genuinely *missing* alt attribute is flagged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings: each { type, severity, message, instance_key,
 *                 src, attachment_id, context_text }.
 */
function fettle_check_alt_text( $html ) {
	if ( '' === trim( (string) $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return array();
	}

	$findings  = array();
	$processor = new WP_HTML_Tag_Processor( $html );
	$html_lower = strtolower( $html );
	$text_cursor = 0;
	$position  = 0;

	while ( $processor->next_tag( 'img' ) ) {
		++$position;

		if ( 'true' === $processor->get_attribute( 'aria-hidden' ) || 'presentation' === strtolower( (string) $processor->get_attribute( 'role' ) ) ) {
			continue;
		}

		// get_attribute() returns null when the attribute is absent at all, vs '' when it is present but empty - that distinction is the whole point of this check (alt="" is valid, no alt attribute is not).
		if ( null !== $processor->get_attribute( 'alt' ) ) {
			continue;
		}

		$src   = (string) $processor->get_attribute( 'src' );
		$class = (string) $processor->get_attribute( 'class' );

		$attachment_id = 0;
		if ( preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) ) {
			$attachment_id = (int) $m[1];
		}

		// Pull the text of the nearest following paragraph as AI-fix context (the image plus its neighbouring paragraph, not the whole page). A bounded forward scan from this <img>'s position, not a full DOM walk.
		$context_text = '';
		$img_at       = strpos( $html_lower, '<img', $text_cursor );
		if ( false !== $img_at ) {
			$p_at = stripos( $html, '<p', $img_at );
			if ( false !== $p_at ) {
				$p_open_end = strpos( $html, '>', $p_at );
				$p_close_at = false !== $p_open_end ? stripos( $html, '</p', $p_open_end ) : false;
				if ( false !== $p_open_end && false !== $p_close_at ) {
					$context_text = trim( wp_strip_all_tags( substr( $html, $p_open_end + 1, $p_close_at - $p_open_end - 1 ) ) );
				}
			}
			$text_cursor = $img_at + 4;
		}

		$findings[] = array(
			'type'          => 'alt-text',
			'severity'      => 'critical',
			'message'       => __( 'Image has no alt attribute - screen reader users get no description of it at all.', 'fettle' ),
			'instance_key'  => md5( 'img|' . $position . '|' . $src ),
			'src'           => $src,
			'attachment_id' => $attachment_id,
			'context_text'  => mb_substr( $context_text, 0, 300 ),
		);
	}

	return $findings;
}
