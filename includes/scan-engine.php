<?php
/**
 * Runs the registered rule-based checks against a post's rendered content
 * and caches the result by content hash - rescans a post only when its
 * content actually changed since last time, not the whole site every time
 * (USHER_V1_SPEC.md §4, critical for a catalogue of hundreds of posts).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'USHER_SCAN_META_KEY', '_usher_scan' );

/**
 * The checks Usher 1.0.0 runs, as { type => callable }. Filterable so a
 * future Pro release (or this plugin's own later versions) can add checks
 * without touching the engine itself.
 *
 * @return array<string, callable>
 */
function usher_get_registered_checks() {
	$checks = array(
		'contrast'      => 'usher_check_contrast',
		'heading-order' => 'usher_check_heading_order',
		'form-labels'   => 'usher_check_form_labels',
		'link-text'     => 'usher_check_link_text',
		'alt-text'      => 'usher_check_alt_text',
	);

	return apply_filters( 'usher_checks', $checks );
}

/**
 * Renders a post's blocks into HTML the way the checks need to see it
 * (resolved dynamic blocks), without the rest of the 'the_content' filter
 * chain - no autoembed HTTP calls, no wpautop paragraph-mangling, no theme
 * wrapping. Deliberately narrower than `the_content` for a backend scan.
 *
 * @param WP_Post $post
 * @return string
 */
function usher_render_post_content( $post ) {
	return do_blocks( $post->post_content );
}

/**
 * Scans one post, using the cached result when the content has not changed
 * since the last scan.
 *
 * @param int  $post_id
 * @param bool $force Bypass the content-hash cache.
 * @return array{
 *     hash: string,
 *     scanned_at: int,
 *     findings: array[],
 * }|WP_Error
 */
function usher_scan_post( $post_id, $force = false ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'usher_post_not_found', __( 'No post exists with that ID.', 'usher' ) );
	}

	$hash  = md5( $post->post_content );
	$cache = get_post_meta( $post_id, USHER_SCAN_META_KEY, true );

	if ( ! $force && is_array( $cache ) && ( $cache['hash'] ?? '' ) === $hash ) {
		$result = $cache;
	} else {
		$html     = usher_render_post_content( $post );
		$findings = array();

		foreach ( usher_get_registered_checks() as $type => $callback ) {
			if ( ! is_callable( $callback ) ) {
				continue;
			}
			$check_findings = call_user_func( $callback, $html );
			if ( is_array( $check_findings ) ) {
				$findings = array_merge( $findings, $check_findings );
			}
		}

		$result = array(
			'hash'       => $hash,
			'scanned_at' => time(),
			'findings'   => $findings,
		);
		update_post_meta( $post_id, USHER_SCAN_META_KEY, $result );
	}

	$result['findings'] = usher_filter_dismissed( $result['findings'], $post_id );

	return $result;
}

/**
 * Scans every published post/page (the two post types most likely to hold
 * hand-authored content worth checking). Deliberately not a full-site crawl
 * of every registered post type in 1.0.0 - kept simple, extend later if the
 * Free scope needs it.
 *
 * @param bool $force Bypass the content-hash cache for every post.
 * @return array<int, array> post_id => scan result.
 */
function usher_scan_site( $force = false ) {
	$results = array();
	$posts   = get_posts(
		array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $posts as $post_id ) {
		$result = usher_scan_post( $post_id, $force );
		if ( ! is_wp_error( $result ) ) {
			$results[ $post_id ] = $result;
		}
	}

	return $results;
}
