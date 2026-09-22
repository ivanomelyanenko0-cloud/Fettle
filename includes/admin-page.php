<?php
/**
 * Admin page: a plain-language digest first, then per-post findings with a
 * "mark as false positive" action on each instance (USHER_V1_SPEC.md §4 -
 * "digest at the top, not a raw list").
 *
 * Synchronous scan-on-request for this first pass, not the AJAX-batched
 * pattern from IntelliDesc's duplicate scanner (spec §4) - fine at the post
 * counts this runs against today; batching is a follow-up once real
 * catalogue sizes are being tested, not a 1.0.0 blocker.
 *
 * Accessibility of this page is not optional for an accessibility plugin
 * (spec §4): no colour-only severity signalling (text labels alongside),
 * no motion.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function usher_register_admin_page() {
	add_menu_page(
		__( 'Usher', 'usher' ),
		__( 'Usher', 'usher' ),
		'edit_others_posts',
		'usher',
		'usher_render_admin_page',
		'dashicons-universal-access',
		58
	);
}
add_action( 'admin_menu', 'usher_register_admin_page' );

/**
 * @param array<int, array> $results post_id => scan result.
 * @return array{total: int, critical: int, warning: int, posts_with_findings: int}
 */
function usher_summarise_results( $results ) {
	$summary = array(
		'total'                => 0,
		'critical'             => 0,
		'warning'              => 0,
		'posts_with_findings'  => 0,
	);

	foreach ( $results as $result ) {
		if ( empty( $result['findings'] ) ) {
			continue;
		}
		++$summary['posts_with_findings'];
		foreach ( $result['findings'] as $finding ) {
			++$summary['total'];
			if ( 'critical' === ( $finding['severity'] ?? '' ) ) {
				++$summary['critical'];
			} else {
				++$summary['warning'];
			}
		}
	}

	return $summary;
}

function usher_render_admin_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$notice = '';

	if ( isset( $_POST['usher_scan'] ) && check_admin_referer( 'usher_scan' ) ) {
		usher_scan_site( ! empty( $_POST['usher_force'] ) );
		$notice = __( 'Scan complete.', 'usher' );
	}

	if ( isset( $_POST['usher_dismiss'] ) && check_admin_referer( 'usher_dismiss' ) ) {
		$dismiss_post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key    = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		if ( $dismiss_post_id && $instance_key ) {
			usher_dismiss_finding( $dismiss_post_id, $instance_key );
			$notice = __( 'Marked as a false positive.', 'usher' );
		}
	}

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
		$result = usher_scan_post( $post_id );
		if ( ! is_wp_error( $result ) && ! empty( $result['findings'] ) ) {
			$results[ $post_id ] = $result;
		}
	}
	$summary = usher_summarise_results( $results );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Usher', 'usher' ); ?></h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p>
			<?php if ( 0 === $summary['total'] ) : ?>
				<?php esc_html_e( 'No accessibility issues found in the last scan of your published posts and pages.', 'usher' ); ?>
			<?php else : ?>
				<?php
				printf(
					/* translators: 1: number of issues, 2: number of critical issues, 3: number of posts/pages affected */
					esc_html__( '%1$d issues found (%2$d critical) across %3$d posts and pages.', 'usher' ),
					(int) $summary['total'],
					(int) $summary['critical'],
					(int) $summary['posts_with_findings']
				);
				?>
			<?php endif; ?>
		</p>

		<form method="post" style="margin-bottom: 1.5em;">
			<?php wp_nonce_field( 'usher_scan' ); ?>
			<label style="margin-right: 1em;">
				<input type="checkbox" name="usher_force" value="1" />
				<?php esc_html_e( 'Rescan everything, ignoring the cache', 'usher' ); ?>
			</label>
			<button type="submit" name="usher_scan" value="1" class="button button-primary">
				<?php esc_html_e( 'Scan now', 'usher' ); ?>
			</button>
		</form>

		<?php if ( ! empty( $results ) ) : ?>
			<?php foreach ( $results as $post_id => $result ) : ?>
				<?php $post = get_post( $post_id ); ?>
				<h2>
					<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
				</h2>
				<table class="widefat striped" style="margin-bottom: 1.5em;">
					<thead>
						<tr>
							<th style="width: 8em;"><?php esc_html_e( 'Severity', 'usher' ); ?></th>
							<th style="width: 10em;"><?php esc_html_e( 'Check', 'usher' ); ?></th>
							<th><?php esc_html_e( 'Issue', 'usher' ); ?></th>
							<th style="width: 10em;"><?php esc_html_e( 'Action', 'usher' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['findings'] as $finding ) : ?>
							<tr>
								<td><strong><?php echo esc_html( 'critical' === ( $finding['severity'] ?? '' ) ? __( 'Critical', 'usher' ) : __( 'Warning', 'usher' ) ); ?></strong></td>
								<td><code><?php echo esc_html( $finding['type'] ?? '' ); ?></code></td>
								<td><?php echo esc_html( $finding['message'] ?? '' ); ?></td>
								<td>
									<?php if ( ! empty( $finding['instance_key'] ) ) : ?>
										<form method="post">
											<?php wp_nonce_field( 'usher_dismiss' ); ?>
											<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
											<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
											<button type="submit" name="usher_dismiss" value="1" class="button button-small">
												<?php esc_html_e( 'Not an issue', 'usher' ); ?>
											</button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}
