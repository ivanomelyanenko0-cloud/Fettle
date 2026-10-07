<?php
/**
 * Decorative-vs-informative image checks - the cases fettle_check_alt_text()
 * deliberately leaves alone because an alt attribute *is* present:
 *
 * - image-empty-alt: marked decorative (alt="") although there is real
 *   evidence it carries meaning - it is the only content of a link (so the
 *   link has no name at all), or its own Media Library entry has alt text.
 * - image-redundant-alt: has alt text that only repeats the text right
 *   next to it (its link's text, its figure's caption), or that contradicts
 *   a role="presentation" on the same image.
 * - image-alt-quality: has "alt text" that is really a file name or a
 *   placeholder word, not a description.
 *
 * Static analysis can never know whether an image with an ordinary-looking
 * alt is actually decorative - that stays a human call. These only fire on
 * high-confidence signals; a false positive here erodes trust faster than
 * a missed one. One image gets at most one finding across all three.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alt values that describe nothing, compared after
 * fettle_normalise_for_comparison(). Deliberately short and unambiguous -
 * "logo" or "banner" can be a reasonable alt in context, so they're not here.
 *
 * @return string[]
 */
function fettle_placeholder_alt_values() {
	return array(
		'image',
		'img',
		'photo',
		'picture',
		'pic',
		'graphic',
		'untitled',
		'placeholder',
		'alt',
		'alt text',
		'image description',
		'spacer',
		'blank',
		'thumbnail',
		'featured image',
	);
}

/**
 * @param string $alt Non-empty alt text.
 * @param string $src The image's src.
 * @return bool True if the alt text is a file name rather than a description.
 */
function fettle_alt_looks_like_filename( $alt, $src ) {
	$alt = strtolower( trim( $alt ) );

	if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?|heic)$/', $alt ) ) {
		return true;
	}
	// Camera and phone file names (IMG_2041, DSC00123, PXL_20240101...) and macOS screenshot names.
	if ( preg_match( '/^(img|dsc|dscn|dscf|pxl|mvimg)[-_]?\d{3,}/', $alt ) || preg_match( '/^screen ?shot[ _-]?\d{4}-\d{2}-\d{2}/', $alt ) ) {
		return true;
	}

	$path = (string) wp_parse_url( $src, PHP_URL_PATH );
	if ( '' === $path ) {
		return false;
	}
	$base = strtolower( rawurldecode( pathinfo( $path, PATHINFO_FILENAME ) ) );
	// WordPress's own derived-size suffixes: photo-1024x768, photo-scaled, photo-rotated, photo-e1700000000000 (edited).
	$base = preg_replace( '/(-(\d+x\d+|scaled|rotated|e\d{10,}))+$/', '', $base );

	// Only a base name that actually looks like a file name (has a dash, underscore or digit) - an alt of "sunset" for sunset.jpg is a word, not a file name.
	return '' !== $base && $alt === $base && (bool) preg_match( '/[-_\d]/', $base );
}

/**
 * Classifies one image into at most one of this file's finding types.
 *
 * @param array $image One entry from fettle_collect_images().
 * @return array|null A finding (without instance_key/src fields), or null.
 */
function fettle_classify_image_alt( $image ) {
	if ( $image['aria_hidden'] || $image['is_cover_background'] || null === $image['alt'] ) {
		return null; // No alt at all is fettle_check_alt_text()'s finding, not one of these.
	}

	$alt          = trim( $image['alt'] );
	$presentation = in_array( $image['role'], array( 'presentation', 'none' ), true );
	$link         = $image['link'];

	if ( '' === $alt ) {
		if ( null !== $link ) {
			// Only the first image of a link whose every image is alt="" and which has no name from anywhere else - one finding per link, not per image.
			if ( fettle_image_link_needs_alt_as_name( $image ) && $link['all_images_empty_alt'] && $link['first_image_position'] === $image['position'] ) {
				return array(
					'type'      => 'image-empty-alt',
					'severity'  => 'critical',
					'message'   => __( 'This image is the only content of a link, but its alt text is empty - so the link has no name, and screen readers announce just "link" or the raw URL.', 'fettle' ),
					'link_href' => $link['href'],
				);
			}
			return null;
		}

		if ( $presentation || ! $image['attachment_id'] ) {
			return null;
		}

		$library_alt = trim( (string) get_post_meta( $image['attachment_id'], '_wp_attachment_image_alt', true ) );
		if ( '' === $library_alt ) {
			return null;
		}

		return array(
			'type'        => 'image-empty-alt',
			'severity'    => 'warning',
			'message'     => sprintf(
				/* translators: %s: the image's alt text from the Media Library */
				__( 'This image is marked as decorative (empty alt), but its Media Library entry has alt text ("%s") - it may carry meaning here too.', 'fettle' ),
				$library_alt
			),
			'library_alt' => $library_alt,
		);
	}

	if ( $presentation ) {
		return array(
			'type'     => 'image-redundant-alt',
			'severity' => 'warning',
			'message'  => sprintf(
				/* translators: %s: the role attribute value, e.g. "presentation" */
				__( 'This image has alt text but is also marked role="%s" - the two contradict each other. If it is decorative, its alt should be empty.', 'fettle' ),
				$image['role']
			),
		);
	}

	if ( fettle_alt_looks_like_filename( $alt, $image['src'] ) ) {
		return array(
			'type'     => 'image-alt-quality',
			'severity' => 'critical',
			'message'  => sprintf(
				/* translators: %s: the image's current alt text */
				__( 'The alt text "%s" is a file name, not a description of the image.', 'fettle' ),
				$alt
			),
		);
	}

	$normalised_alt = fettle_normalise_for_comparison( $alt );

	if ( null !== $link && is_string( $link['text'] ) && '' !== $link['text'] && fettle_normalise_for_comparison( $link['text'] ) === $normalised_alt ) {
		return array(
			'type'     => 'image-redundant-alt',
			'severity' => 'warning',
			'message'  => __( 'The alt text repeats the link\'s own text, so screen readers read the same words twice.', 'fettle' ),
		);
	}

	if ( is_string( $image['caption'] ) && '' !== $image['caption'] && fettle_normalise_for_comparison( $image['caption'] ) === $normalised_alt ) {
		return array(
			'type'     => 'image-redundant-alt',
			'severity' => 'warning',
			'message'  => __( 'The alt text repeats the image\'s caption, so screen readers read the same words twice.', 'fettle' ),
		);
	}

	if ( in_array( $normalised_alt, fettle_placeholder_alt_values(), true ) ) {
		return array(
			'type'     => 'image-alt-quality',
			'severity' => 'warning',
			'message'  => sprintf(
				/* translators: %s: the image's current alt text */
				__( 'The alt text "%s" is a placeholder, not a description of the image.', 'fettle' ),
				$alt
			),
		);
	}

	return null;
}

/**
 * @param string $html Rendered block HTML.
 * @param string $type The finding type to return.
 * @return array[]
 */
function fettle_image_alt_findings_of_type( $html, $type ) {
	$findings = array();

	foreach ( fettle_collect_images( $html ) as $image ) {
		$issue = fettle_classify_image_alt( $image );
		if ( null === $issue || $type !== $issue['type'] ) {
			continue;
		}

		$findings[] = array_merge(
			array(
				'instance_key'  => fettle_image_instance_key( $image ),
				'src'           => $image['src'],
				'attachment_id' => $image['attachment_id'],
				'context_text'  => $image['context_text'],
				'current_alt'   => (string) $image['alt'],
				'link_href'     => fettle_image_link_needs_alt_as_name( $image ) ? $image['link']['href'] : '',
				'library_alt'   => '',
			),
			$issue
		);
	}

	return $findings;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[]
 */
function fettle_check_image_empty_alt( $html ) {
	return fettle_image_alt_findings_of_type( $html, 'image-empty-alt' );
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[]
 */
function fettle_check_image_redundant_alt( $html ) {
	return fettle_image_alt_findings_of_type( $html, 'image-redundant-alt' );
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[]
 */
function fettle_check_image_alt_quality( $html ) {
	return fettle_image_alt_findings_of_type( $html, 'image-alt-quality' );
}
