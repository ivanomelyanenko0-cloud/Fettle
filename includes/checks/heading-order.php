<?php
/**
 * Heading-order check: skipped levels, multiple H1s, empty headings.
 * WP_HTML_Tag_Processor (core, WP 6.2+) walks tag structure; a lightweight,
 * position-tracked substring scan pulls each heading's own text, since the
 * tag processor is attribute-focused and does not expose element text
 * content directly (USHER_V1_SPEC.md §2.2).
 *
 * Scope: this walks one post's content in isolation. It cannot see the
 * theme's own H1 (usually the post title, rendered outside post_content),
 * so "multiple H1s" here means multiple H1s *within the content itself* -
 * already suspicious on its own, regardless of what the theme adds.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings: each { type, severity, message, instance_key }.
 */
function usher_check_heading_order( $html ) {
	if ( '' === trim( (string) $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return array();
	}

	$findings    = array();
	$headings    = array(); // Sequential list of array( 'level', 'text', 'instance_key' ).
	$processor   = new WP_HTML_Tag_Processor( $html );
	$html_lower  = strtolower( $html );
	$text_cursor = 0; // Tracks how far into $html the text-extraction scan has already consumed, so repeated tags (e.g. several H2s) each get their own text rather than all matching the first occurrence.
	$h1_count    = 0;
	$position    = 0;

	while ( $processor->next_tag() ) {
		$tag = strtolower( (string) $processor->get_tag() );
		if ( ! preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			continue;
		}

		$level = (int) $m[1];
		if ( 1 === $level ) {
			++$h1_count;
		}

		$open_at  = strpos( $html_lower, '<' . $tag, $text_cursor );
		$text     = '';
		if ( false !== $open_at ) {
			$open_end = strpos( $html, '>', $open_at );
			$close_at = false !== $open_end ? stripos( $html, '</' . $tag, $open_end ) : false;
			if ( false !== $open_end && false !== $close_at ) {
				$inner       = substr( $html, $open_end + 1, $close_at - $open_end - 1 );
				$text        = trim( wp_strip_all_tags( $inner ) );
				$text_cursor = $close_at + strlen( '</' . $tag );
			} else {
				$text_cursor = $open_at + strlen( '<' . $tag );
			}
		}

		$headings[] = array(
			'level'        => $level,
			'text'         => $text,
			'instance_key' => md5( $tag . '|' . $position . '|' . substr( $text, 0, 40 ) ),
		);
		++$position;
	}

	if ( $h1_count > 1 ) {
		$findings[] = array(
			'type'         => 'heading-order',
			'severity'     => 'warning',
			'message'      => sprintf(
				/* translators: %d: number of H1 headings found */
				__( 'This content has %d H1 headings; a page should normally have one.', 'usher' ),
				$h1_count
			),
			'instance_key' => 'multiple-h1',
		);
	}

	$previous_level = null;
	foreach ( $headings as $heading ) {
		if ( '' === trim( $heading['text'] ) ) {
			$findings[] = array(
				'type'         => 'heading-order',
				'severity'     => 'warning',
				'message'      => sprintf(
					/* translators: %s: heading tag, e.g. "H3" */
					__( 'Empty %s heading - screen reader users hear nothing where a heading is announced.', 'usher' ),
					'H' . $heading['level']
				),
				'instance_key' => $heading['instance_key'],
			);
		}

		if ( null !== $previous_level && $heading['level'] > $previous_level + 1 ) {
			$findings[] = array(
				'type'         => 'heading-order',
				'severity'     => 'warning',
				'message'      => sprintf(
					/* translators: 1: previous heading level e.g. "H2", 2: skipped-to heading level e.g. "H4" */
					__( 'Heading level jumps from %1$s to %2$s, skipping a level.', 'usher' ),
					'H' . $previous_level,
					'H' . $heading['level']
				),
				'instance_key' => $heading['instance_key'],
			);
		}
		$previous_level = $heading['level'];
	}

	return $findings;
}
