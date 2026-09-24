<?php
/**
 * Uninstall handler. Removes the two postmeta keys this plugin writes
 * (scan cache, per-instance dismissals) across all posts - a direct query
 * rather than looping every post through delete_post_meta(), since a large
 * catalogue could mean thousands of rows.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * @global wpdb $wpdb
 */
function legible_uninstall_site() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstalling: no object cache concern, and a per-row delete_post_meta() loop is needless work against a potentially large catalogue.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
			'_legible_scan',
			'_legible_dismissed'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $legible_site_id ) {
		switch_to_blog( $legible_site_id );
		legible_uninstall_site();
		restore_current_blog();
	}
} else {
	legible_uninstall_site();
}
