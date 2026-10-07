<?php
/**
 * Missing alt-text check - the check this plugin's entire AI-fix flow is
 * built around (alt text is generated from just the image plus its
 * neighbouring paragraph). Added here so the AI-fix flow has an actual
 * check producing alt-text findings to fix, not just a generation path
 * with nothing feeding it.
 *
 * WCAG-correct on purpose: `alt=""` (empty, but present) is a deliberate,
 * valid way to mark an image decorative - not a violation here. Only a
 * genuinely *missing* alt attribute is flagged; whether an empty alt was
 * the right call for a particular image is image-alt.php's question.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings: each { type, severity, message, instance_key,
 *                 src, attachment_id, context_text, link_href }.
 */
function fettle_check_alt_text( $html ) {
	$findings = array();

	foreach ( fettle_collect_images( $html ) as $image ) {
		if ( $image['aria_hidden'] || in_array( $image['role'], array( 'presentation', 'none' ), true ) ) {
			continue;
		}
		if ( null !== $image['alt'] ) {
			continue;
		}

		$findings[] = array(
			'type'          => 'alt-text',
			'severity'      => 'critical',
			'message'       => __( 'Image has no alt attribute - screen reader users get no description of it at all.', 'fettle' ),
			'instance_key'  => fettle_image_instance_key( $image ),
			'src'           => $image['src'],
			'attachment_id' => $image['attachment_id'],
			'context_text'  => $image['context_text'],
			'link_href'     => fettle_image_link_needs_alt_as_name( $image ) ? $image['link']['href'] : '',
		);
	}

	return $findings;
}

/**
 * The instance_key every image finding uses - the same formula
 * fettle_apply_alt_text_fix() recomputes to find the image again.
 *
 * @param array $image One entry from fettle_collect_images().
 * @return string
 */
function fettle_image_instance_key( $image ) {
	return md5( 'img|' . $image['position'] . '|' . $image['src'] );
}

/**
 * Whether this image's alt text is (or would be) the only name its link
 * has: the image sits in a link with no visible text and no aria-label /
 * aria-labelledby / title of its own. Alt text for such an image has to say
 * where the link goes, and "decorative" is never a valid answer for it.
 *
 * @param array $image One entry from fettle_collect_images().
 * @return bool
 */
function fettle_image_link_needs_alt_as_name( $image ) {
	$link = $image['link'];
	return null !== $link && ! $link['hidden'] && ! $link['named'] && '' === $link['text'];
}
