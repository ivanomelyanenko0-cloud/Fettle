<?php
/**
 * The plain findings list as rows, for the CSV download on the Fettle page
 * and for `wp fettle scan`. One row per finding, nothing aggregated - a
 * report organised by WCAG criterion (with conformance levels, history and
 * the criteria no automated check can test) is Fettle Pro's.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WCAG 2.1 success criterion each check tests most directly. A check
 * can touch more than one criterion; this is the one a finding is filed
 * under (same mapping as Fettle Pro's report).
 *
 * @return array<string, string> check type => criterion number.
 */
function fettle_check_wcag_criteria() {
	$criteria = array(
		'contrast'            => '1.4.3',
		'heading-order'       => '1.3.1',
		'form-labels'         => '3.3.2',
		'link-text'           => '2.4.4',
		'alt-text'            => '1.1.1',
		'image-empty-alt'     => '1.1.1',
		'image-redundant-alt' => '1.1.1',
		'image-alt-quality'   => '1.1.1',
		'table-headers'       => '1.3.1',
	);

	/**
	 * Lets an added check (see the fettle_checks filter) name its criterion.
	 *
	 * @param array<string, string> $criteria
	 */
	return apply_filters( 'fettle_check_wcag_criteria', $criteria );
}

/**
 * Every finding in published posts and pages, from the scan cache (posts
 * changed since their last scan are rescanned, as on the Fettle page).
 * Findings marked "Not an issue" are left out.
 *
 * @param array $args {
 *     @type int[]    $post_ids Only these posts. Default: every published post and page.
 *     @type string[] $types    Only these check types. Default: all.
 *     @type bool     $force    Rescan, ignoring the cache. Default false.
 * }
 * @return array[] Rows with post_id, title, url, check, check_label, wcag,
 *                 severity, message, hint.
 */
function fettle_collect_finding_rows( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'post_ids' => array(),
			'types'    => array(),
			'force'    => false,
		)
	);

	$post_ids = $args['post_ids'];
	if ( empty( $post_ids ) ) {
		$post_ids = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
	}

	$criteria = fettle_check_wcag_criteria();
	$rows     = array();

	foreach ( $post_ids as $post_id ) {
		$result = fettle_scan_post( (int) $post_id, (bool) $args['force'] );
		if ( is_wp_error( $result ) ) {
			continue;
		}
		foreach ( $result['findings'] as $finding ) {
			$type = $finding['type'] ?? '';
			if ( $args['types'] && ! in_array( $type, $args['types'], true ) ) {
				continue;
			}
			$rows[] = array(
				'post_id'     => (int) $post_id,
				// Plain text for a CSV / terminal, not the texturized HTML entities.
				'title'       => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'         => get_permalink( $post_id ),
				'check'       => $type,
				'check_label' => fettle_finding_type_label( $type ),
				'wcag'        => $criteria[ $type ] ?? '',
				'severity'    => 'critical' === ( $finding['severity'] ?? '' ) ? 'critical' : 'warning',
				'message'     => (string) ( $finding['message'] ?? '' ),
				'hint'        => (string) ( $finding['hint'] ?? '' ),
			);
		}
	}

	return $rows;
}

/**
 * One CSV field. Quoted when needed, and a leading = + - @ (or tab / CR)
 * is defused with an apostrophe: messages quote text from the page, and a
 * spreadsheet would otherwise run it as a formula.
 *
 * @param mixed $value
 * @return string
 */
function fettle_csv_field( $value ) {
	$value = (string) $value;
	if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
		$value = "'" . $value;
	}
	if ( preg_match( '/[",\r\n]/', $value ) ) {
		$value = '"' . str_replace( '"', '""', $value ) . '"';
	}
	return $value;
}

/**
 * @param array[] $rows From fettle_collect_finding_rows().
 * @return string CSV text, header row first, UTF-8 with a byte order mark so
 *                Excel doesn't garble non-English text.
 */
function fettle_finding_rows_to_csv( $rows ) {
	$columns = array(
		'post_id'     => __( 'Post ID', 'fettle' ),
		'title'       => __( 'Title', 'fettle' ),
		'url'         => __( 'URL', 'fettle' ),
		'check_label' => __( 'Check', 'fettle' ),
		'check'       => __( 'Check ID', 'fettle' ),
		'wcag'        => __( 'WCAG criterion', 'fettle' ),
		'severity'    => __( 'Severity', 'fettle' ),
		'message'     => __( 'Issue', 'fettle' ),
		'hint'        => __( 'How to fix', 'fettle' ),
	);

	$lines = array( implode( ',', array_map( 'fettle_csv_field', $columns ) ) );
	foreach ( $rows as $row ) {
		$cells = array();
		foreach ( array_keys( $columns ) as $key ) {
			$cells[] = fettle_csv_field( $row[ $key ] ?? '' );
		}
		$lines[] = implode( ',', $cells );
	}

	return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
}

/**
 * The "Download CSV" link on the Fettle page posts here.
 */
function fettle_handle_csv_export() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to export this report.', 'fettle' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'fettle_export_csv' );

	$filename = 'fettle-findings-' . gmdate( 'Y-m-d' ) . '.csv';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	echo fettle_finding_rows_to_csv( fettle_collect_finding_rows() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV download, not HTML; every field goes through fettle_csv_field().
	exit;
}
add_action( 'admin_post_fettle_export_csv', 'fettle_handle_csv_export' );
