<?php
/**
 * Link-text quality check: flags uninformative link text ("click here",
 * "here", "read more", a bare URL as the visible text) with a plain text
 * heuristic - no AI needed to detect this, only to optionally suggest
 * better wording as an AI-fix later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lowercase, whitespace/punctuation-normalised phrases that carry no
 * information about the link's destination on their own. Deliberately a
 * short, high-confidence list rather than an exhaustive one - a false
 * positive here erodes trust faster than a missed one.
 */
function fettle_uninformative_link_phrases() {
	return array(
		'click here',
		'here',
		'here.',
		'read more',
		'more',
		'learn more',
		'link',
		'this link',
		'this page',
		'this',
		'click',
		'go',
	);
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings: each { type, severity, message, instance_key }.
 */
function fettle_check_link_text( $html ) {
	if ( '' === trim( (string) $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return array();
	}

	$findings    = array();
	$phrases     = fettle_uninformative_link_phrases();
	$processor   = new WP_HTML_Tag_Processor( $html );
	$html_lower  = strtolower( $html );
	$text_cursor = 0;
	$position    = 0;

	while ( $processor->next_tag( 'a' ) ) {
		$href = (string) $processor->get_attribute( 'href' );

		// A link deliberately hidden from assistive tech, or one that is really a button (role="button" with its own accessible name elsewhere), is not this check's business.
		if ( 'true' === $processor->get_attribute( 'aria-hidden' ) ) {
			continue;
		}

		$aria_label = $processor->get_attribute( 'aria-label' );
		if ( is_string( $aria_label ) && '' !== trim( $aria_label ) ) {
			continue; // Has its own accessible name regardless of visible text.
		}

		$open_at = strpos( $html_lower, '<a', $text_cursor );
		$text    = '';
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

		if ( '' === $text ) {
			continue; // No text at all (e.g. an icon-only link) is a different, image/aria concern, not this check's.
		}

		$normalised = strtolower( trim( $text, " \t\n\r\0\x0B." ) );
		$is_bare_url = (bool) preg_match( '#^https?://#i', $text );

		if ( ! $is_bare_url && ! in_array( $normalised, $phrases, true ) ) {
			continue;
		}

		$findings[] = array(
			'type'         => 'link-text',
			'severity'     => 'warning',
			'message'      => $is_bare_url
				? __( 'Link text is a bare URL, which is hard to understand out of context when read aloud.', 'fettle' )
				: sprintf(
					/* translators: %s: the link's actual visible text */
					__( 'Link text "%s" does not describe where the link goes.', 'fettle' ),
					$text
				),
			'link_text'    => $text,
			'href'         => $href,
			'instance_key' => md5( 'a|' . $position . '|' . $text . '|' . $href ),
		);
	}

	return $findings;
}
