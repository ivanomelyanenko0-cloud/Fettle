<?php
/**
 * Checks for interactive and embedded elements that a screen reader can't
 * name or that behave in a way the visitor didn't ask for:
 *
 * - empty-link:      an <a href> with no text and no other accessible name.
 * - empty-button:    a <button> with no text and no other accessible name.
 * - iframe-title:    an <iframe> without a title.
 * - media-captions:  a <video> without a captions/subtitles track.
 * - media-autoplay:  <audio>, or <video> that isn't muted, set to autoplay.
 * - new-tab-link:    a link that opens a new tab or window without saying so.
 *
 * Same split as image-scan.php: WP_HTML_Tag_Processor reads attributes and
 * structure; the inner HTML of links and buttons comes from an in-order
 * regex pass and is only trusted when both passes count the same number of
 * elements. When they disagree, the checks that depend on inner content
 * stay silent rather than guess.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The inner HTML of every <$tag>...</$tag> in document order, or null when
 * the regex count differs from the tag processor's count.
 *
 * @param string $html
 * @param string $tag            Lowercase tag name.
 * @param int    $expected_count How many the tag processor counted.
 * @return string[]|null
 */
function fettle_collect_element_inner_html( $html, $tag, $expected_count ) {
	if ( 0 === $expected_count ) {
		return array();
	}

	$pattern = '#<' . preg_quote( $tag, '#' ) . '\b[^>]*>(.*?)</' . preg_quote( $tag, '#' ) . '\s*>#is';
	if ( ! preg_match_all( $pattern, $html, $matches ) || count( $matches[1] ) !== $expected_count ) {
		return null;
	}

	return $matches[1];
}

/**
 * Whether an element's inner HTML gives it an accessible name on its own:
 * visible text, or an <svg> carrying a <title> / aria-label. Images are
 * judged by the image checks, so the caller handles them separately.
 *
 * @param string $inner Inner HTML.
 * @return bool
 */
function fettle_inner_html_has_name( $inner ) {
	// Text hidden from assistive tech doesn't name anything.
	$visible = preg_replace( '#<([a-z0-9]+)\b[^>]*\baria-hidden\s*=\s*["\']?true["\']?[^>]*>.*?</\1\s*>#is', '', (string) $inner );
	if ( '' !== fettle_visible_text( preg_replace( '#<svg\b.*?</svg\s*>#is', '', $visible ) ) ) {
		return true;
	}

	if ( preg_match_all( '#<svg\b([^>]*)>(.*?)</svg\s*>#is', $visible, $svgs, PREG_SET_ORDER ) ) {
		foreach ( $svgs as $svg ) {
			if ( preg_match( '#\baria-label\s*=\s*["\'][^"\']*\S[^"\']*["\']#i', $svg[1] ) ) {
				return true;
			}
			if ( preg_match( '#<title\b[^>]*>(.*?)</title\s*>#is', $svg[2], $title ) && '' !== fettle_visible_text( $title[1] ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Phrases that tell the visitor a link opens a new tab or window, in the
 * languages Fettle ships or is most often run in. Matched as substrings of
 * the link's lowercased accessible name. Filterable for other languages.
 *
 * @return string[]
 */
function fettle_new_tab_warning_phrases() {
	$phrases = array(
		'new tab',
		'new window',
		'opens in',
		'external link',
		'нова вкладка',
		'новій вкладці',
		'нову вкладку',
		'новому вікні',
		'нове вікно',
		'новой вкладке',
		'новом окне',
		'neuer tab',
		'neuen tab',
		'neues fenster',
		'neuem fenster',
		'nouvel onglet',
		'nouvelle fenêtre',
		'nueva pestaña',
		'nueva ventana',
		'nowa karta',
		'nowej karcie',
		'nowym oknie',
		'nuova scheda',
		'nuova finestra',
		'nieuw tabblad',
		'nieuw venster',
	);

	/**
	 * @param string[] $phrases Lowercase substrings.
	 */
	return apply_filters( 'fettle_new_tab_warning_phrases', $phrases );
}

/**
 * One shared walk for all the checks in this file, cached per HTML string
 * because the engine calls each check back to back on the same content.
 *
 * @param string $html Rendered block HTML (post_content).
 * @return array{links: array[], buttons: array[], iframes: array[], media: array[]}
 */
function fettle_collect_named_elements( $html ) {
	static $cache_key = '';
	static $cache     = array();

	$empty = array(
		'links'   => array(),
		'buttons' => array(),
		'iframes' => array(),
		'media'   => array(),
	);

	$html = (string) $html;
	if ( '' === trim( $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $empty;
	}

	$key = md5( $html );
	if ( $key === $cache_key ) {
		return $cache;
	}

	$data       = $empty;
	$link_count = 0;
	$open_media = null;

	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		$tag    = $processor->get_tag();
		$closer = $processor->is_tag_closer();

		if ( 'VIDEO' === $tag || 'AUDIO' === $tag ) {
			if ( $closer ) {
				$open_media = null;
				continue;
			}
			$data['media'][] = array(
				'tag'          => strtolower( $tag ),
				'src'          => (string) $processor->get_attribute( 'src' ),
				'autoplay'     => null !== $processor->get_attribute( 'autoplay' ),
				'muted'        => null !== $processor->get_attribute( 'muted' ),
				'hidden'       => 'true' === $processor->get_attribute( 'aria-hidden' ),
				'has_captions' => false,
			);
			$open_media = count( $data['media'] ) - 1;
			continue;
		}

		if ( $closer ) {
			continue;
		}

		if ( 'TRACK' === $tag && null !== $open_media ) {
			$kind = strtolower( trim( (string) $processor->get_attribute( 'kind' ) ) );
			if ( in_array( $kind, array( 'captions', 'subtitles' ), true ) ) {
				$data['media'][ $open_media ]['has_captions'] = true;
			}
			continue;
		}

		if ( 'SOURCE' === $tag && null !== $open_media && '' === $data['media'][ $open_media ]['src'] ) {
			$data['media'][ $open_media ]['src'] = (string) $processor->get_attribute( 'src' );
			continue;
		}

		if ( 'A' === $tag ) {
			++$link_count; // Every <a>, with or without href, to stay aligned with the regex pass.
			$data['links'][] = array(
				'index'  => $link_count - 1,
				'href'   => $processor->get_attribute( 'href' ),
				'target' => strtolower( trim( (string) $processor->get_attribute( 'target' ) ) ),
				'label'  => trim( (string) $processor->get_attribute( 'aria-label' ) ),
				'named'  => fettle_has_nonempty_attribute( $processor, array( 'aria-label', 'aria-labelledby', 'title' ) ),
				'hidden' => 'true' === $processor->get_attribute( 'aria-hidden' ),
			);
			continue;
		}

		if ( 'BUTTON' === $tag ) {
			$data['buttons'][] = array(
				'named'  => fettle_has_nonempty_attribute( $processor, array( 'aria-label', 'aria-labelledby', 'title' ) ),
				'hidden' => 'true' === $processor->get_attribute( 'aria-hidden' ),
			);
			continue;
		}

		if ( 'IFRAME' === $tag ) {
			$data['iframes'][] = array(
				'src'    => (string) $processor->get_attribute( 'src' ),
				'named'  => fettle_has_nonempty_attribute( $processor, array( 'title', 'aria-label', 'aria-labelledby' ) ),
				'hidden' => 'true' === $processor->get_attribute( 'aria-hidden' ),
			);
		}
	}

	$link_inner   = fettle_collect_element_inner_html( $html, 'a', $link_count );
	$button_inner = fettle_collect_element_inner_html( $html, 'button', count( $data['buttons'] ) );

	foreach ( $data['links'] as $i => $link ) {
		$inner = null === $link_inner ? null : $link_inner[ $link['index'] ];

		$data['links'][ $i ]['has_img']  = null !== $inner && false !== stripos( $inner, '<img' );
		$data['links'][ $i ]['has_name'] = null === $inner ? null : fettle_inner_html_has_name( $inner );
		$data['links'][ $i ]['text']     = null === $inner ? null : fettle_visible_text( $inner );
		// Alt text of any image inside the link counts as part of its name for the new-tab wording check.
		$alts = '';
		if ( null !== $inner && preg_match_all( '#\balt\s*=\s*(["\'])(.*?)\1#is', $inner, $m ) ) {
			$alts = implode( ' ', $m[2] );
		}
		$data['links'][ $i ]['img_alts'] = $alts;
	}

	foreach ( $data['buttons'] as $i => $button ) {
		$inner = null === $button_inner ? null : $button_inner[ $i ];

		$data['buttons'][ $i ]['has_img']  = null !== $inner && (bool) preg_match( '#<img\b[^>]*\balt\s*=\s*(["\'])[^"\']*\S[^"\']*\1#i', $inner );
		$data['buttons'][ $i ]['has_name'] = null === $inner ? null : fettle_inner_html_has_name( $inner );
	}

	$cache_key = $key;
	$cache     = $data;

	return $data;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings.
 */
function fettle_check_empty_link( $html ) {
	$findings = array();
	$position = 0;

	foreach ( fettle_collect_named_elements( $html )['links'] as $link ) {
		if ( null === $link['href'] ) {
			continue; // An <a> without href is not a link (e.g. a named anchor).
		}
		++$position;

		// The image checks already report a link whose only content is an image without usable alt text.
		if ( $link['hidden'] || $link['named'] || $link['has_img'] || false !== $link['has_name'] ) {
			continue;
		}

		$findings[] = array(
			'type'         => 'empty-link',
			'severity'     => 'critical',
			'message'      => sprintf(
				/* translators: %s: the link's destination URL */
				__( 'A link to %s has no text, so a screen reader announces only "link" and the visitor can\'t tell where it goes.', 'fettle' ),
				'' !== $link['href'] ? $link['href'] : __( '(empty address)', 'fettle' )
			),
			'hint'         => __( 'Add visible link text. For an icon-only link, give the link an aria-label that says where it goes. An empty link left over from editing can simply be removed.', 'fettle' ),
			'href'         => (string) $link['href'],
			'instance_key' => md5( 'empty-link|' . $position . '|' . $link['href'] ),
		);
	}

	return $findings;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings.
 */
function fettle_check_empty_button( $html ) {
	$findings = array();
	$position = 0;

	foreach ( fettle_collect_named_elements( $html )['buttons'] as $button ) {
		++$position;
		if ( $button['hidden'] || $button['named'] || $button['has_img'] || false !== $button['has_name'] ) {
			continue;
		}

		$findings[] = array(
			'type'         => 'empty-button',
			'severity'     => 'critical',
			'message'      => __( 'A button has no text or label, so a screen reader announces only "button" and the visitor can\'t tell what it does.', 'fettle' ),
			'hint'         => __( 'Add text to the button, or for an icon-only button an aria-label that says what it does (for example "Search" or "Close menu").', 'fettle' ),
			'instance_key' => md5( 'empty-button|' . $position ),
		);
	}

	return $findings;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings.
 */
function fettle_check_iframe_title( $html ) {
	$findings = array();
	$position = 0;

	foreach ( fettle_collect_named_elements( $html )['iframes'] as $iframe ) {
		++$position;
		if ( $iframe['hidden'] || $iframe['named'] ) {
			continue;
		}

		$host = wp_parse_url( $iframe['src'], PHP_URL_HOST );

		$findings[] = array(
			'type'         => 'iframe-title',
			'severity'     => 'warning',
			'message'      => $host
				? sprintf(
					/* translators: %s: the embedded site's host name, e.g. www.youtube.com */
					__( 'An embedded frame from %s has no title, so a screen reader can\'t say what it contains before the visitor enters it.', 'fettle' ),
					$host
				)
				: __( 'An embedded frame has no title, so a screen reader can\'t say what it contains before the visitor enters it.', 'fettle' ),
			'hint'         => __( 'Add a short title attribute to the <iframe> that says what it is, for example title="Map of our office" or title="Video: how to assemble the shelf".', 'fettle' ),
			'instance_key' => md5( 'iframe|' . $position . '|' . $iframe['src'] ),
		);
	}

	return $findings;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings.
 */
function fettle_check_media_captions( $html ) {
	$findings = array();
	$position = 0;

	foreach ( fettle_collect_named_elements( $html )['media'] as $media ) {
		if ( 'video' !== $media['tag'] ) {
			continue;
		}
		++$position;
		// A muted autoplaying video is almost always a silent background loop, which needs no captions.
		if ( $media['hidden'] || $media['has_captions'] || ( $media['autoplay'] && $media['muted'] ) ) {
			continue;
		}

		$findings[] = array(
			'type'         => 'media-captions',
			'severity'     => 'warning',
			'message'      => __( 'A video has no captions track. If it has speech or meaningful sound, deaf and hard-of-hearing visitors miss it.', 'fettle' ),
			'hint'         => __( 'Add a captions file (.vtt) to the video: in the Video block, use "Text tracks" in the toolbar and choose the kind "Captions". If the video is silent, mark this as not an issue.', 'fettle' ),
			'instance_key' => md5( 'video-captions|' . $position . '|' . $media['src'] ),
		);
	}

	return $findings;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings.
 */
function fettle_check_media_autoplay( $html ) {
	$findings = array();
	$position = 0;

	foreach ( fettle_collect_named_elements( $html )['media'] as $media ) {
		++$position;
		if ( ! $media['autoplay'] || ( 'video' === $media['tag'] && $media['muted'] ) ) {
			continue;
		}

		$findings[] = array(
			'type'         => 'media-autoplay',
			'severity'     => 'warning',
			'message'      => 'audio' === $media['tag']
				? __( 'Audio starts playing on its own. Sound that starts without warning drowns out a screen reader and startles visitors.', 'fettle' )
				: __( 'A video with sound starts playing on its own. Sound that starts without warning drowns out a screen reader and startles visitors.', 'fettle' ),
			'hint'         => 'audio' === $media['tag']
				? __( 'Turn off Autoplay in the Audio block settings and let the visitor press play.', 'fettle' )
				: __( 'Turn off Autoplay in the Video block settings, or turn on Muted if it is a background video.', 'fettle' ),
			'instance_key' => md5( 'autoplay|' . $position . '|' . $media['tag'] . '|' . $media['src'] ),
		);
	}

	return $findings;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings.
 */
function fettle_check_new_tab_link( $html ) {
	$findings = array();
	$phrases  = fettle_new_tab_warning_phrases();
	$position = 0;

	foreach ( fettle_collect_named_elements( $html )['links'] as $link ) {
		if ( null === $link['href'] ) {
			continue;
		}
		++$position;

		if ( $link['hidden'] || null === $link['text'] || ( '_blank' !== $link['target'] && '_new' !== $link['target'] ) ) {
			continue;
		}

		$name = mb_strtolower( $link['text'] . ' ' . $link['label'] . ' ' . $link['img_alts'] );
		if ( '' === trim( $name ) ) {
			continue; // A link with no name at all is the empty-link check's finding.
		}

		$warned = false;
		foreach ( $phrases as $phrase ) {
			if ( false !== mb_strpos( $name, $phrase ) ) {
				$warned = true;
				break;
			}
		}
		if ( $warned ) {
			continue;
		}

		$findings[] = array(
			'type'         => 'new-tab-link',
			'severity'     => 'warning',
			'message'      => sprintf(
				/* translators: %s: the link's visible text */
				__( 'The link "%s" opens in a new tab without saying so. Screen reader and keyboard users can lose their place, because the Back button no longer works.', 'fettle' ),
				trim( '' !== $link['text'] ? $link['text'] : ( '' !== $link['label'] ? $link['label'] : $link['img_alts'] ) )
			),
			'hint'         => __( 'Either turn off "Open in new tab" for this link, or add a note to the link text such as "(opens in a new tab)". This is advice rather than a strict WCAG AA requirement.', 'fettle' ),
			'link_text'    => $link['text'],
			'href'         => (string) $link['href'],
			'instance_key' => md5( 'new-tab|' . $position . '|' . $link['text'] . '|' . $link['href'] ),
		);
	}

	return $findings;
}
