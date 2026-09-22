<?php
/**
 * Form-label check: every <input>/<select>/<textarea> needs a <label for>
 * with a matching id, or aria-label/aria-labelledby (USHER_V1_SPEC.md §2.3).
 *
 * Scope, deliberately: this is about raw HTML in post content/blocks, not
 * WooCommerce/CF7/WPForms output - those are already labelled correctly in
 * the overwhelming majority of cases, and re-verifying every form plugin's
 * own markup is not where 1.0.0's effort belongs (spec §2.3). The real risk
 * is hand-written HTML in a Custom HTML block or a theme's page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const USHER_LABELABLE_TAGS = array( 'input', 'select', 'textarea' );

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings: each { type, severity, message, instance_key }.
 */
function usher_check_form_labels( $html ) {
	if ( '' === trim( (string) $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return array();
	}

	// Pass 1: collect every id a <label for="..."> in this content points to.
	$labelled_ids = array();
	$label_scan   = new WP_HTML_Tag_Processor( $html );
	while ( $label_scan->next_tag( 'label' ) ) {
		$for = $label_scan->get_attribute( 'for' );
		if ( is_string( $for ) && '' !== $for ) {
			$labelled_ids[ $for ] = true;
		}
	}

	$findings  = array();
	$processor = new WP_HTML_Tag_Processor( $html );
	$position  = 0;

	while ( $processor->next_tag() ) {
		$tag = strtolower( (string) $processor->get_tag() );
		if ( ! in_array( $tag, USHER_LABELABLE_TAGS, true ) ) {
			continue;
		}

		$type = strtolower( (string) $processor->get_attribute( 'type' ) );
		// Hidden/submit/button/image inputs are not something a user reads a label for.
		if ( 'input' === $tag && in_array( $type, array( 'hidden', 'submit', 'button', 'image', 'reset' ), true ) ) {
			continue;
		}

		// Respect aria-hidden / role="presentation" - a field deliberately hidden from assistive tech is not a labelling gap (USHER_V1_SPEC.md §4).
		$aria_hidden = $processor->get_attribute( 'aria-hidden' );
		$role        = strtolower( (string) $processor->get_attribute( 'role' ) );
		if ( 'true' === $aria_hidden || 'presentation' === $role || 'none' === $role ) {
			continue;
		}

		++$position;

		$id                = $processor->get_attribute( 'id' );
		$aria_label        = $processor->get_attribute( 'aria-label' );
		$aria_labelledby   = $processor->get_attribute( 'aria-labelledby' );
		$has_explicit_label = ( is_string( $id ) && isset( $labelled_ids[ $id ] ) )
			|| ( is_string( $aria_label ) && '' !== trim( $aria_label ) )
			|| ( is_string( $aria_labelledby ) && '' !== trim( $aria_labelledby ) );

		if ( $has_explicit_label ) {
			continue;
		}

		$findings[] = array(
			'type'         => 'form-labels',
			'severity'     => 'critical',
			'message'      => sprintf(
				/* translators: %s: element tag, e.g. "input" */
				__( 'A <%s> field has no associated label, aria-label, or aria-labelledby - screen reader users cannot tell what it is for.', 'usher' ),
				$tag
			),
			'instance_key' => md5( $tag . '|' . $position . '|' . (string) $id . '|' . (string) $type ),
		);
	}

	return $findings;
}
