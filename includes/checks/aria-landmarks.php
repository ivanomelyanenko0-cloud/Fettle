<?php
/**
 * ARIA-landmark check: not a per-post check like the others in this
 * directory - it runs once against the active theme via a real render,
 * not per post. Landmarks (<header>, <nav>, <main>, <footer>, or their
 * ARIA role equivalents) come from the theme's own template structure,
 * not from post content, so this fetches one real rendered page (the
 * front page) instead of walking post_content.
 *
 * Known, documented simplification: a <header> is only actually the page
 * banner landmark when it is not nested inside <article>/<aside>/<main>/
 * <nav>/<section> (the HTML5 spec's own rule) - distinguishing that
 * precisely needs ancestor-tracking WP_HTML_Tag_Processor does not expose
 * at the WP 6.2 baseline this plugin targets. This check treats the mere
 * presence of a <header> tag or role="banner" anywhere as satisfying the
 * "has a banner" signal - a real but rare over-count on a page whose only
 * <header> sits inside e.g. an <article>, not a false negative.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'USHER_LANDMARKS_CACHE_OPTION', 'usher_landmarks_cache' );

/**
 * @param string $html Rendered page HTML.
 * @return array[] Findings: each { type, severity, message }.
 */
function usher_parse_landmarks( $html ) {
	if ( '' === trim( (string) $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return array();
	}

	$landmarks = array(
		'banner'      => array( 'tag' => 'header', 'role' => 'banner' ),
		'navigation'  => array( 'tag' => 'nav', 'role' => 'navigation' ),
		'main'        => array( 'tag' => 'main', 'role' => 'main' ),
		'contentinfo' => array( 'tag' => 'footer', 'role' => 'contentinfo' ),
	);
	$counts = array_fill_keys( array_keys( $landmarks ), 0 );

	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag() ) {
		$tag  = strtolower( (string) $processor->get_tag() );
		$role = strtolower( (string) $processor->get_attribute( 'role' ) );

		foreach ( $landmarks as $name => $signature ) {
			if ( $tag === $signature['tag'] || $role === $signature['role'] ) {
				++$counts[ $name ];
			}
		}
	}

	$findings = array();

	if ( 0 === $counts['main'] ) {
		$findings[] = array(
			'type'     => 'aria-landmarks',
			'severity' => 'critical',
			'message'  => __( 'No <main> landmark found on the front page - screen reader users have no quick way to skip to the main content.', 'usher' ),
		);
	} elseif ( $counts['main'] > 1 ) {
		$findings[] = array(
			'type'     => 'aria-landmarks',
			'severity' => 'critical',
			'message'  => sprintf(
				/* translators: %d: number of <main> elements found */
				__( '%d <main> landmarks found on the front page - there should be exactly one.', 'usher' ),
				$counts['main']
			),
		);
	}

	if ( 0 === $counts['navigation'] ) {
		$findings[] = array(
			'type'     => 'aria-landmarks',
			'severity' => 'warning',
			'message'  => __( 'No navigation landmark (<nav> or role="navigation") found on the front page.', 'usher' ),
		);
	}

	if ( 0 === $counts['contentinfo'] ) {
		$findings[] = array(
			'type'     => 'aria-landmarks',
			'severity' => 'warning',
			'message'  => __( 'No footer landmark (<footer> or role="contentinfo") found on the front page.', 'usher' ),
		);
	}

	if ( 0 === $counts['banner'] ) {
		$findings[] = array(
			'type'     => 'aria-landmarks',
			'severity' => 'warning',
			'message'  => __( 'No banner landmark (<header> or role="banner") found on the front page.', 'usher' ),
		);
	}

	return $findings;
}

/**
 * Fetches the site's own front page and runs the landmark check against
 * it. A loopback request from inside WordPress to its own site URL - the
 * same class of request Site Health's own core "loopback requests" check
 * exists to diagnose, and some hosts (and this plugin's own wp-env dev
 * setup, as it happens) block or cannot complete. Fails gracefully with a
 * clear reason rather than a confusing generic error, same principle as
 * WatermarkGuru's delivery self-test.
 *
 * @return array{
 *     theme: string,
 *     checked_at: int,
 *     findings: array[],
 *     error: string,
 * }
 */
function usher_run_landmark_check() {
	$result = array(
		'theme'      => get_stylesheet(),
		'checked_at' => time(),
		'findings'   => array(),
		'error'      => '',
	);

	$response = wp_remote_get(
		home_url( '/' ),
		array(
			'timeout'   => 15,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		)
	);

	if ( is_wp_error( $response ) ) {
		$result['error'] = $response->get_error_message();
		return $result;
	}

	$http_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $http_code ) {
		/* translators: %d: HTTP status code */
		$result['error'] = sprintf( __( 'Unexpected response fetching the front page (HTTP %d).', 'usher' ), $http_code );
		return $result;
	}

	$result['findings'] = usher_parse_landmarks( wp_remote_retrieve_body( $response ) );

	update_option( USHER_LANDMARKS_CACHE_OPTION, $result, false );

	return $result;
}

/**
 * @return array|null Cached result for the *current* active theme, or null
 *                     if never checked (or the theme changed since).
 */
function usher_get_cached_landmark_result() {
	$cached = get_option( USHER_LANDMARKS_CACHE_OPTION, null );
	if ( ! is_array( $cached ) || ( $cached['theme'] ?? '' ) !== get_stylesheet() ) {
		return null;
	}
	return $cached;
}

/**
 * Cache is theme-scoped (spec: "раз на активну тему") - a theme switch
 * invalidates it outright rather than leaving a stale result attributed
 * to a theme that is no longer active.
 */
add_action( 'switch_theme', 'usher_clear_landmark_cache' );
function usher_clear_landmark_cache() {
	delete_option( USHER_LANDMARKS_CACHE_OPTION );
}
