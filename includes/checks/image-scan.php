<?php
/**
 * One shared walk over a post's <img> elements for every image check, so
 * they all number images exactly the way fettle_apply_alt_text_fix() does
 * (every <img>, in document order, none skipped) and agree on which
 * instance_key a given image has.
 *
 * WP_HTML_Tag_Processor reads attributes but not text, so the text some
 * checks need - a link's own visible text, a figure's caption - comes from
 * an in-order regex pass over the same HTML. If the two passes disagree on
 * how many links or captions there are (markup unusual enough to confuse
 * the regex), that text is reported as unknown (null) and the checks that
 * depend on it stay silent rather than guess.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] One entry per <img>, in document order: {
 *     position: int, src: string, alt: string|null, role: string,
 *     aria_hidden: bool, attachment_id: int, is_cover_background: bool,
 *     context_text: string, caption: string|null,
 *     link: array{href: string, named: bool, hidden: bool, text: string|null,
 *                 all_images_empty_alt: bool, first_image_position: int}|null,
 * }
 */
function fettle_collect_images( $html ) {
	// Every image check runs on the same HTML back to back during one post's scan - walk it once.
	static $cache_key = '';
	static $cache     = array();

	$html = (string) $html;
	if ( '' === trim( $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return array();
	}

	$key = md5( $html );
	if ( $key === $cache_key ) {
		return $cache;
	}

	$images        = array();
	$links         = array();
	$figure_of     = array(); // figure index => caption index, or -1 for no caption.
	$figure_stack  = array();
	$open_link     = null;
	$link_count    = 0;
	$figure_count  = 0;
	$caption_count = 0;
	$position      = 0;
	$html_lower    = strtolower( $html );
	$text_cursor   = 0;

	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		$tag    = $processor->get_tag();
		$closer = $processor->is_tag_closer();

		if ( 'A' === $tag ) {
			if ( $closer ) {
				$open_link = null;
				continue;
			}
			$open_link             = $link_count++;
			$links[ $open_link ]   = array(
				'href'   => (string) $processor->get_attribute( 'href' ),
				'named'  => fettle_has_nonempty_attribute( $processor, array( 'aria-label', 'aria-labelledby', 'title' ) ),
				'hidden' => 'true' === $processor->get_attribute( 'aria-hidden' ),
				'images' => array(),
			);
			continue;
		}

		if ( 'FIGURE' === $tag ) {
			if ( $closer ) {
				array_pop( $figure_stack );
				continue;
			}
			$figure_of[ $figure_count ] = -1;
			$figure_stack[]             = $figure_count++;
			continue;
		}

		if ( 'FIGCAPTION' === $tag ) {
			if ( ! $closer ) {
				if ( ! empty( $figure_stack ) ) {
					$figure_of[ end( $figure_stack ) ] = $caption_count;
				}
				++$caption_count;
			}
			continue;
		}

		if ( 'IMG' !== $tag || $closer ) {
			continue;
		}

		++$position;
		$src   = (string) $processor->get_attribute( 'src' );
		$class = (string) $processor->get_attribute( 'class' );
		$alt   = $processor->get_attribute( 'alt' );

		$attachment_id = 0;
		if ( preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) ) {
			$attachment_id = (int) $m[1];
		}

		// The text of the nearest following paragraph, as AI-fix context (the image plus its neighbouring paragraph, not the whole page). A bounded forward scan from this <img>'s position, not a full DOM walk.
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

		$images[] = array(
			'position'            => $position,
			'src'                 => $src,
			'alt'                 => is_string( $alt ) ? $alt : null, // null = no alt attribute at all, '' = present but empty.
			'role'                => strtolower( trim( (string) $processor->get_attribute( 'role' ) ) ),
			'aria_hidden'         => 'true' === $processor->get_attribute( 'aria-hidden' ),
			'attachment_id'       => $attachment_id,
			// A Cover block's background <img> keeps its alt in the block's own JSON attributes, not in this HTML - rewriting the HTML would break the block in the editor, and it is a background anyway.
			'is_cover_background' => (bool) preg_match( '/\bwp-block-cover__image-background\b/', $class ),
			'context_text'        => mb_substr( $context_text, 0, 300 ),
			'link_index'          => $open_link,
			'figure_index'        => empty( $figure_stack ) ? null : end( $figure_stack ),
		);

		if ( null !== $open_link ) {
			$links[ $open_link ]['images'][] = count( $images ) - 1;
		}
	}

	$link_texts    = fettle_collect_element_texts( $html, 'a', $link_count );
	$caption_texts = fettle_collect_element_texts( $html, 'figcaption', $caption_count );

	foreach ( $images as $i => $image ) {
		$link = null;
		if ( null !== $image['link_index'] ) {
			$data      = $links[ $image['link_index'] ];
			$all_empty = true;
			foreach ( $data['images'] as $image_index ) {
				$link_alt = $images[ $image_index ]['alt'];
				if ( null === $link_alt || '' !== trim( $link_alt ) ) {
					$all_empty = false;
				}
			}
			$link = array(
				'href'                 => $data['href'],
				'named'                => $data['named'],
				'hidden'               => $data['hidden'],
				'text'                 => null === $link_texts ? null : $link_texts[ $image['link_index'] ],
				'all_images_empty_alt' => $all_empty,
				'first_image_position' => $images[ $data['images'][0] ]['position'],
			);
		}

		$caption = '';
		if ( null !== $image['figure_index'] && $figure_of[ $image['figure_index'] ] >= 0 ) {
			$caption = null === $caption_texts ? null : $caption_texts[ $figure_of[ $image['figure_index'] ] ];
		}

		unset( $images[ $i ]['link_index'], $images[ $i ]['figure_index'] );
		$images[ $i ]['link']    = $link;
		$images[ $i ]['caption'] = $caption;
	}

	$cache_key = $key;
	$cache     = $images;

	return $images;
}

/**
 * @param WP_HTML_Tag_Processor $processor
 * @param string[]              $names
 * @return bool True if any of the attributes is present with non-blank text.
 */
function fettle_has_nonempty_attribute( $processor, $names ) {
	foreach ( $names as $name ) {
		$value = $processor->get_attribute( $name );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The visible text of every <$tag>...</$tag> in order, or null when the
 * regex finds a different number of them than the tag processor did - the
 * signal that this markup is too unusual to trust a regex reading of.
 *
 * @param string $html
 * @param string $tag            Lowercase tag name.
 * @param int    $expected_count How many the tag processor counted.
 * @return string[]|null
 */
function fettle_collect_element_texts( $html, $tag, $expected_count ) {
	if ( 0 === $expected_count ) {
		return array();
	}

	$pattern = '#<' . preg_quote( $tag, '#' ) . '\b[^>]*>(.*?)</' . preg_quote( $tag, '#' ) . '\s*>#is';
	if ( ! preg_match_all( $pattern, $html, $matches ) || count( $matches[1] ) !== $expected_count ) {
		return null;
	}

	return array_map( 'fettle_visible_text', $matches[1] );
}

/**
 * @param string $html_fragment
 * @return string Tag-free, entity-decoded text with whitespace collapsed.
 */
function fettle_visible_text( $html_fragment ) {
	$text = html_entity_decode( wp_strip_all_tags( (string) $html_fragment ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Normalises text for an "are these two strings saying the same thing"
 * comparison: case, whitespace, and surrounding punctuation don't count.
 *
 * @param string $text
 * @return string
 */
function fettle_normalise_for_comparison( $text ) {
	$text = mb_strtolower( fettle_visible_text( $text ) );
	return trim( $text, " \t\n\r\0\x0B.,:;!?-\"'()[]" );
}
