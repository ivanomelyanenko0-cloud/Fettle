<?php
/**
 * Admin page: a plain-language digest first, then per-post findings with a
 * "mark as false positive" action on each instance (USHER_V1_SPEC.md §4 -
 * "digest at the top, not a raw list"), plus a one-issue-at-a-time AI-fix
 * flow for alt-text findings (generate -> preview -> explicit Apply/Discard,
 * spec §0/§1.3: never auto-applied).
 *
 * Synchronous scan-on-request and synchronous AI-fix generation for this
 * first pass, not the AJAX-batched pattern from IntelliDesc's duplicate
 * scanner (spec §4) - fine at the post counts and one-at-a-time AI calls
 * this runs today; batching is a follow-up once real catalogue sizes are
 * being tested, not a 1.0.0 blocker.
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
		'total'               => 0,
		'critical'            => 0,
		'warning'             => 0,
		'posts_with_findings' => 0,
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

/**
 * Finds one finding by instance_key across a scan result, needed because
 * the AI-fix POST actions only carry post_id + instance_key, not the full
 * finding data (src, attachment_id, context_text) generate needs.
 *
 * @param array  $result Scan result from usher_scan_post().
 * @param string $instance_key
 * @return array|null
 */
function usher_find_finding_by_key( $result, $instance_key ) {
	foreach ( $result['findings'] as $finding ) {
		if ( ( $finding['instance_key'] ?? '' ) === $instance_key ) {
			return $finding;
		}
	}
	return null;
}

function usher_render_admin_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$notice       = '';
	$notice_type  = 'success';

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

	if ( isset( $_POST['usher_generate_fix'] ) && check_admin_referer( 'usher_generate_fix' ) ) {
		$fix_post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		$scan         = $fix_post_id ? usher_scan_post( $fix_post_id ) : null;
		$finding      = $scan && ! is_wp_error( $scan ) ? usher_find_finding_by_key( $scan, $instance_key ) : null;

		if ( ! $finding ) {
			$notice      = __( 'Could not find that issue - try scanning again.', 'usher' );
			$notice_type = 'error';
		} else {
			$suggestion = usher_generate_alt_text_suggestion( $fix_post_id, $finding );
			if ( is_wp_error( $suggestion ) ) {
				$notice      = $suggestion->get_error_message();
				$notice_type = 'error';
			} else {
				usher_store_pending_fix( $fix_post_id, $instance_key, $suggestion );
				$notice = __( 'Suggestion generated - review it below before applying.', 'usher' );
			}
		}
	}

	if ( isset( $_POST['usher_apply_fix'] ) && check_admin_referer( 'usher_apply_fix' ) ) {
		$fix_post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		$suggestion   = $fix_post_id ? usher_get_pending_fix( $fix_post_id, $instance_key ) : false;

		if ( false === $suggestion ) {
			$notice      = __( 'That suggestion expired - generate it again.', 'usher' );
			$notice_type = 'error';
		} else {
			$result = usher_apply_alt_text_fix( $fix_post_id, $instance_key, $suggestion );
			if ( is_wp_error( $result ) ) {
				$notice      = $result->get_error_message();
				$notice_type = 'error';
			} else {
				usher_clear_pending_fix( $fix_post_id, $instance_key );
				$notice = __( 'Alt text applied.', 'usher' );
			}
		}
	}

	if ( isset( $_POST['usher_discard_fix'] ) && check_admin_referer( 'usher_discard_fix' ) ) {
		$fix_post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		if ( $fix_post_id && $instance_key ) {
			usher_clear_pending_fix( $fix_post_id, $instance_key );
			$notice = __( 'Suggestion discarded.', 'usher' );
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
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?>"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! usher_ai_is_configured() ) : ?>
			<div class="notice notice-info">
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to the Usher AI settings page */
							__( 'No AI provider is configured yet, so AI-suggested fixes aren\'t available. <a href="%s">Add an API key</a> to enable them - the rule-based checks below work either way.', 'usher' ),
							array( 'a' => array( 'href' => true ) )
						),
						esc_url( admin_url( 'admin.php?page=usher-settings' ) )
					);
					?>
				</p>
			</div>
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
				<h2>
					<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
				</h2>
				<table class="widefat striped" style="margin-bottom: 1.5em;">
					<thead>
						<tr>
							<th style="width: 8em;"><?php esc_html_e( 'Severity', 'usher' ); ?></th>
							<th style="width: 10em;"><?php esc_html_e( 'Check', 'usher' ); ?></th>
							<th><?php esc_html_e( 'Issue', 'usher' ); ?></th>
							<th style="width: 16em;"><?php esc_html_e( 'Action', 'usher' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['findings'] as $finding ) : ?>
							<?php $pending = 'alt-text' === ( $finding['type'] ?? '' ) && ! empty( $finding['instance_key'] ) ? usher_get_pending_fix( $post_id, $finding['instance_key'] ) : false; ?>
							<tr>
								<td><strong><?php echo esc_html( 'critical' === ( $finding['severity'] ?? '' ) ? __( 'Critical', 'usher' ) : __( 'Warning', 'usher' ) ); ?></strong></td>
								<td><code><?php echo esc_html( $finding['type'] ?? '' ); ?></code></td>
								<td>
									<?php echo esc_html( $finding['message'] ?? '' ); ?>
									<?php if ( 'alt-text' === ( $finding['type'] ?? '' ) && ! empty( $finding['src'] ) ) : ?>
										<br /><img src="<?php echo esc_url( $finding['src'] ); ?>" alt="" style="max-width: 80px; max-height: 60px; margin-top: 0.5em;" />
									<?php endif; ?>
									<?php if ( false !== $pending ) : ?>
										<p style="margin-top: 0.5em;">
											<strong><?php esc_html_e( 'Suggested alt text:', 'usher' ); ?></strong>
											"<?php echo esc_html( $pending ); ?>"
										</p>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( 'alt-text' === ( $finding['type'] ?? '' ) && ! empty( $finding['instance_key'] ) ) : ?>
										<?php if ( false !== $pending ) : ?>
											<form method="post" style="display: inline-block; margin-right: 0.3em;">
												<?php wp_nonce_field( 'usher_apply_fix' ); ?>
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
												<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
												<button type="submit" name="usher_apply_fix" value="1" class="button button-primary button-small">
													<?php esc_html_e( 'Apply', 'usher' ); ?>
												</button>
											</form>
											<form method="post" style="display: inline-block; margin-right: 0.3em;">
												<?php wp_nonce_field( 'usher_discard_fix' ); ?>
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
												<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
												<button type="submit" name="usher_discard_fix" value="1" class="button button-small">
													<?php esc_html_e( 'Discard', 'usher' ); ?>
												</button>
											</form>
										<?php elseif ( usher_ai_is_configured() ) : ?>
											<form method="post" style="display: inline-block; margin-right: 0.3em;">
												<?php wp_nonce_field( 'usher_generate_fix' ); ?>
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
												<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
												<button type="submit" name="usher_generate_fix" value="1" class="button button-small">
													<?php esc_html_e( 'Generate AI fix', 'usher' ); ?>
												</button>
											</form>
										<?php endif; ?>
									<?php endif; ?>
									<?php if ( ! empty( $finding['instance_key'] ) ) : ?>
										<form method="post" style="display: inline-block;">
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
