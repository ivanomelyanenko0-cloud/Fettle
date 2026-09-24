<?php
/**
 * Per-instance "mark as false positive" - not a global rule toggle. Cheap to
 * do even in Free, and critical for trust: generic scanners are notorious
 * for false-positive fatigue. Stored as postmeta keyed by the finding's
 * instance_key, scoped to that one post.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FETTLE_DISMISSALS_META_KEY', '_fettle_dismissed' );

/**
 * @param int $post_id
 * @return array<string, true> Set of dismissed instance_keys for this post.
 */
function fettle_get_dismissals( $post_id ) {
	$dismissed = get_post_meta( $post_id, FETTLE_DISMISSALS_META_KEY, true );
	return is_array( $dismissed ) ? $dismissed : array();
}

/**
 * @param int    $post_id
 * @param string $instance_key
 */
function fettle_dismiss_finding( $post_id, $instance_key ) {
	$dismissed                  = fettle_get_dismissals( $post_id );
	$dismissed[ $instance_key ] = true;
	update_post_meta( $post_id, FETTLE_DISMISSALS_META_KEY, $dismissed );
}

/**
 * @param int    $post_id
 * @param string $instance_key
 */
function fettle_undismiss_finding( $post_id, $instance_key ) {
	$dismissed = fettle_get_dismissals( $post_id );
	unset( $dismissed[ $instance_key ] );
	if ( empty( $dismissed ) ) {
		delete_post_meta( $post_id, FETTLE_DISMISSALS_META_KEY );
	} else {
		update_post_meta( $post_id, FETTLE_DISMISSALS_META_KEY, $dismissed );
	}
}

/**
 * @param array[] $findings   Raw findings, each with an 'instance_key'.
 * @param int     $post_id
 * @return array[] Findings with dismissed instances removed.
 */
function fettle_filter_dismissed( $findings, $post_id ) {
	$dismissed = fettle_get_dismissals( $post_id );
	if ( empty( $dismissed ) ) {
		return $findings;
	}

	return array_values(
		array_filter(
			$findings,
			function ( $finding ) use ( $dismissed ) {
				return empty( $finding['instance_key'] ) || ! isset( $dismissed[ $finding['instance_key'] ] );
			}
		)
	);
}
