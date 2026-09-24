<?php
/**
 * Admin page: a plain-language digest first, then per-post findings with a
 * "mark as false positive" action on each instance, plus a
 * one-issue-at-a-time AI-fix flow for alt-text, contrast, and link-text
 * findings (generate -> preview -> explicit Apply/Discard, never
 * auto-applied).
 *
 * Synchronous scan-on-request and synchronous AI-fix generation for this
 * first pass, not the AJAX-batched pattern from IntelliDesc's duplicate
 * scanner - fine at the post counts and one-at-a-time AI calls this runs
 * today; batching is a follow-up once real catalogue sizes are being
 * tested, not a 1.0.0 blocker.
 *
 * Accessibility of this page is not optional for an accessibility plugin:
 * no colour-only severity signalling (text labels alongside), no motion.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function fettle_register_admin_page() {
	add_menu_page(
		__( 'Fettle', 'fettle' ),
		__( 'Fettle', 'fettle' ),
		'edit_others_posts',
		'fettle',
		'fettle_render_admin_page',
		'dashicons-universal-access',
		58
	);
}
add_action( 'admin_menu', 'fettle_register_admin_page' );

/**
 * The three finding types with an AI-fix, and the pair of functions each
 * one plugs into the generate -> preview -> confirm flow below. Every
 * generate callback takes ( $post_id, $finding ) and returns a suggestion
 * (string, or array{color, used_fallback} for contrast) or WP_Error; every
 * apply callback takes ( $post_id, $instance_key, $value ) and returns
 * true-ish or WP_Error.
 *
 * @return array<string, array{generate: callable, apply: callable}>
 */
function fettle_ai_fix_registry() {
	return array(
		'alt-text'  => array(
			'generate' => 'fettle_generate_alt_text_suggestion',
			'apply'    => 'fettle_apply_alt_text_fix',
		),
		'contrast'  => array(
			'generate' => 'fettle_generate_contrast_suggestion',
			'apply'    => 'fettle_apply_contrast_fix',
		),
		'link-text' => array(
			'generate' => 'fettle_generate_link_text_suggestion',
			'apply'    => 'fettle_apply_link_text_fix',
		),
	);
}

/**
 * @param array<int, array> $results post_id => scan result.
 * @return array{total: int, critical: int, warning: int, posts_with_findings: int}
 */
function fettle_summarise_results( $results ) {
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
 * finding data (src, attachment_id, colours, etc.) generate needs.
 *
 * @param array  $result Scan result from fettle_scan_post().
 * @param string $instance_key
 * @return array|null
 */
function fettle_find_finding_by_key( $result, $instance_key ) {
	foreach ( $result['findings'] as $finding ) {
		if ( ( $finding['instance_key'] ?? '' ) === $instance_key ) {
			return $finding;
		}
	}
	return null;
}

/**
 * Normalises a generate callback's return value into { value, display } -
 * "value" is what the apply callback expects, "display" is what the admin
 * sees in the preview. Only contrast's generator returns the richer
 * array{color, used_fallback} shape today.
 *
 * @param string|array $suggestion
 * @return array{value: string, display: string}
 */
function fettle_normalise_ai_suggestion( $suggestion ) {
	if ( is_array( $suggestion ) && isset( $suggestion['color'] ) ) {
		$display = $suggestion['color'];
		if ( ! empty( $suggestion['used_fallback'] ) ) {
			$display .= ' ' . __( '(the AI\'s own suggestion did not actually pass verification, so this is a black/white fallback that reliably passes instead)', 'fettle' );
		}
		return array( 'value' => $suggestion['color'], 'display' => $display );
	}

	return array( 'value' => (string) $suggestion, 'display' => (string) $suggestion );
}

function fettle_render_admin_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$notice      = '';
	$notice_type = 'success';
	$registry    = fettle_ai_fix_registry();

	if ( isset( $_POST['fettle_scan'] ) && check_admin_referer( 'fettle_scan' ) ) {
		fettle_scan_site( ! empty( $_POST['fettle_force'] ) );
		$notice = __( 'Scan complete.', 'fettle' );
	}

	if ( isset( $_POST['fettle_dismiss'] ) && check_admin_referer( 'fettle_dismiss' ) ) {
		$dismiss_post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key    = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		if ( $dismiss_post_id && ! current_user_can( 'edit_post', $dismiss_post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this post.', 'fettle' ) );
		}
		if ( $dismiss_post_id && $instance_key ) {
			fettle_dismiss_finding( $dismiss_post_id, $instance_key );
			$notice = __( 'Marked as a false positive.', 'fettle' );
		}
	}

	if ( isset( $_POST['fettle_generate_fix'] ) && check_admin_referer( 'fettle_generate_fix' ) ) {
		$fix_post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		if ( $fix_post_id && ! current_user_can( 'edit_post', $fix_post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this post.', 'fettle' ) );
		}
		$scan         = $fix_post_id ? fettle_scan_post( $fix_post_id ) : null;
		$finding      = $scan && ! is_wp_error( $scan ) ? fettle_find_finding_by_key( $scan, $instance_key ) : null;
		$fix_type     = $finding['type'] ?? '';

		if ( ! $finding || empty( $registry[ $fix_type ] ) ) {
			$notice      = __( 'Could not find that issue - try scanning again.', 'fettle' );
			$notice_type = 'error';
		} else {
			$suggestion = call_user_func( $registry[ $fix_type ]['generate'], $fix_post_id, $finding );
			if ( is_wp_error( $suggestion ) ) {
				$notice      = $suggestion->get_error_message();
				$notice_type = 'error';
			} else {
				$normalised = fettle_normalise_ai_suggestion( $suggestion );
				fettle_store_pending_fix( $fix_post_id, $instance_key, wp_json_encode( array_merge( $normalised, array( 'type' => $fix_type ) ) ) );
				$notice = __( 'Suggestion generated - review it below before applying.', 'fettle' );
			}
		}
	}

	if ( isset( $_POST['fettle_apply_fix'] ) && check_admin_referer( 'fettle_apply_fix' ) ) {
		$fix_post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		if ( $fix_post_id && ! current_user_can( 'edit_post', $fix_post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this post.', 'fettle' ) );
		}
		$stored       = $fix_post_id ? fettle_get_pending_fix( $fix_post_id, $instance_key ) : false;
		$decoded      = false !== $stored ? json_decode( $stored, true ) : null;

		if ( ! is_array( $decoded ) || empty( $registry[ $decoded['type'] ?? '' ] ) ) {
			$notice      = __( 'That suggestion expired - generate it again.', 'fettle' );
			$notice_type = 'error';
		} else {
			$result = call_user_func( $registry[ $decoded['type'] ]['apply'], $fix_post_id, $instance_key, $decoded['value'] );
			if ( is_wp_error( $result ) ) {
				$notice      = $result->get_error_message();
				$notice_type = 'error';
			} else {
				fettle_clear_pending_fix( $fix_post_id, $instance_key );
				$notice = __( 'Fix applied.', 'fettle' );
			}
		}
	}

	if ( isset( $_POST['fettle_discard_fix'] ) && check_admin_referer( 'fettle_discard_fix' ) ) {
		$fix_post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		if ( $fix_post_id && ! current_user_can( 'edit_post', $fix_post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this post.', 'fettle' ) );
		}
		if ( $fix_post_id && $instance_key ) {
			fettle_clear_pending_fix( $fix_post_id, $instance_key );
			$notice = __( 'Suggestion discarded.', 'fettle' );
		}
	}

	if ( isset( $_POST['fettle_check_landmarks'] ) && check_admin_referer( 'fettle_check_landmarks' ) ) {
		$landmark_result = fettle_run_landmark_check();
		if ( $landmark_result['error'] ) {
			$notice      = $landmark_result['error'];
			$notice_type = 'error';
		} else {
			$notice = __( 'Theme landmark check complete.', 'fettle' );
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
		$result = fettle_scan_post( $post_id );
		if ( ! is_wp_error( $result ) && ! empty( $result['findings'] ) ) {
			$results[ $post_id ] = $result;
		}
	}
	$summary = fettle_summarise_results( $results );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Fettle', 'fettle' ); ?></h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?>"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! fettle_ai_is_configured() ) : ?>
			<div class="notice notice-info">
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to the Fettle AI settings page */
							__( 'No AI provider is configured yet, so AI-suggested fixes aren\'t available. <a href="%s">Add an API key</a> to enable them - the rule-based checks below work either way.', 'fettle' ),
							array( 'a' => array( 'href' => true ) )
						),
						esc_url( admin_url( 'admin.php?page=fettle-settings' ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<p>
			<?php if ( 0 === $summary['total'] ) : ?>
				<?php esc_html_e( 'No accessibility issues found in the last scan of your published posts and pages.', 'fettle' ); ?>
			<?php else : ?>
				<?php
				printf(
					/* translators: 1: number of issues, 2: number of critical issues, 3: number of posts/pages affected */
					esc_html__( '%1$d issues found (%2$d critical) across %3$d posts and pages.', 'fettle' ),
					(int) $summary['total'],
					(int) $summary['critical'],
					(int) $summary['posts_with_findings']
				);
				?>
			<?php endif; ?>
		</p>

		<form method="post" style="margin-bottom: 1.5em;">
			<?php wp_nonce_field( 'fettle_scan' ); ?>
			<label style="margin-right: 1em;">
				<input type="checkbox" name="fettle_force" value="1" />
				<?php esc_html_e( 'Rescan everything, ignoring the cache', 'fettle' ); ?>
			</label>
			<button type="submit" name="fettle_scan" value="1" class="button button-primary">
				<?php esc_html_e( 'Scan now', 'fettle' ); ?>
			</button>
		</form>

		<h2><?php esc_html_e( 'Theme landmarks', 'fettle' ); ?></h2>
		<p><?php esc_html_e( 'Checked once per active theme (not per post) - landmark regions like the main content area come from your theme\'s templates, not your post content.', 'fettle' ); ?></p>
		<?php $landmark_cache = fettle_get_cached_landmark_result(); ?>
		<?php if ( $landmark_cache ) : ?>
			<?php if ( empty( $landmark_cache['findings'] ) ) : ?>
				<p><?php esc_html_e( 'No landmark issues found on the front page.', 'fettle' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $landmark_cache['findings'] as $finding ) : ?>
						<li><strong><?php echo esc_html( 'critical' === $finding['severity'] ? __( 'Critical:', 'fettle' ) : __( 'Warning:', 'fettle' ) ); ?></strong> <?php echo esc_html( $finding['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: %s: human-readable time, e.g. "5 minutes ago" */
					esc_html__( 'Last checked %s ago.', 'fettle' ),
					esc_html( human_time_diff( $landmark_cache['checked_at'] ) )
				);
				?>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'Not checked yet for the current theme.', 'fettle' ); ?></p>
		<?php endif; ?>
		<form method="post" style="margin-bottom: 1.5em;">
			<?php wp_nonce_field( 'fettle_check_landmarks' ); ?>
			<button type="submit" name="fettle_check_landmarks" value="1" class="button">
				<?php esc_html_e( 'Check theme landmarks now', 'fettle' ); ?>
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
							<th style="width: 8em;"><?php esc_html_e( 'Severity', 'fettle' ); ?></th>
							<th style="width: 10em;"><?php esc_html_e( 'Check', 'fettle' ); ?></th>
							<th><?php esc_html_e( 'Issue', 'fettle' ); ?></th>
							<th style="width: 16em;"><?php esc_html_e( 'Action', 'fettle' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['findings'] as $finding ) : ?>
							<?php
							$fix_type = $finding['type'] ?? '';
							$fixable  = isset( $registry[ $fix_type ] ) && ! empty( $finding['instance_key'] );
							$pending  = $fixable ? fettle_get_pending_fix( $post_id, $finding['instance_key'] ) : false;
							$decoded  = false !== $pending ? json_decode( $pending, true ) : null;
							?>
							<tr>
								<td><strong><?php echo esc_html( 'critical' === ( $finding['severity'] ?? '' ) ? __( 'Critical', 'fettle' ) : __( 'Warning', 'fettle' ) ); ?></strong></td>
								<td><code><?php echo esc_html( $fix_type ); ?></code></td>
								<td>
									<?php echo esc_html( $finding['message'] ?? '' ); ?>

									<?php if ( 'alt-text' === $fix_type && ! empty( $finding['src'] ) ) : ?>
										<br /><img src="<?php echo esc_url( $finding['src'] ); ?>" alt="" style="max-width: 80px; max-height: 60px; margin-top: 0.5em;" />
									<?php elseif ( 'contrast' === $fix_type && ! empty( $finding['bg_hex'] ) ) : ?>
										<br />
										<span style="display: inline-block; padding: 0.3em 0.6em; margin-top: 0.5em; background: <?php echo esc_attr( $finding['bg_hex'] ); ?>; color: <?php echo esc_attr( $finding['text_hex'] ); ?>;">
											<?php esc_html_e( 'Sample text (current)', 'fettle' ); ?>
										</span>
									<?php elseif ( 'link-text' === $fix_type && ! empty( $finding['href'] ) ) : ?>
										<br /><code style="font-size: 0.9em;"><?php echo esc_html( $finding['href'] ); ?></code>
									<?php endif; ?>

									<?php if ( is_array( $decoded ) ) : ?>
										<p style="margin-top: 0.5em;">
											<strong><?php esc_html_e( 'Suggestion:', 'fettle' ); ?></strong>
											"<?php echo esc_html( $decoded['display'] ?? '' ); ?>"
											<?php if ( 'contrast' === $fix_type && ! empty( $finding['bg_hex'] ) && ! empty( $decoded['value'] ) ) : ?>
												<br />
												<span style="display: inline-block; padding: 0.3em 0.6em; margin-top: 0.3em; background: <?php echo esc_attr( $finding['bg_hex'] ); ?>; color: <?php echo esc_attr( $decoded['value'] ); ?>;">
													<?php esc_html_e( 'Sample text (proposed)', 'fettle' ); ?>
												</span>
											<?php endif; ?>
										</p>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $fixable ) : ?>
										<?php if ( is_array( $decoded ) ) : ?>
											<form method="post" style="display: inline-block; margin-right: 0.3em;">
												<?php wp_nonce_field( 'fettle_apply_fix' ); ?>
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
												<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
												<button type="submit" name="fettle_apply_fix" value="1" class="button button-primary button-small">
													<?php esc_html_e( 'Apply', 'fettle' ); ?>
												</button>
											</form>
											<form method="post" style="display: inline-block; margin-right: 0.3em;">
												<?php wp_nonce_field( 'fettle_discard_fix' ); ?>
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
												<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
												<button type="submit" name="fettle_discard_fix" value="1" class="button button-small">
													<?php esc_html_e( 'Discard', 'fettle' ); ?>
												</button>
											</form>
										<?php elseif ( fettle_ai_is_configured() ) : ?>
											<form method="post" style="display: inline-block; margin-right: 0.3em;">
												<?php wp_nonce_field( 'fettle_generate_fix' ); ?>
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
												<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
												<button type="submit" name="fettle_generate_fix" value="1" class="button button-small">
													<?php esc_html_e( 'Generate AI fix', 'fettle' ); ?>
												</button>
											</form>
										<?php endif; ?>
									<?php endif; ?>
									<?php if ( ! empty( $finding['instance_key'] ) ) : ?>
										<form method="post" style="display: inline-block;">
											<?php wp_nonce_field( 'fettle_dismiss' ); ?>
											<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
											<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
											<button type="submit" name="fettle_dismiss" value="1" class="button button-small">
												<?php esc_html_e( 'Not an issue', 'fettle' ); ?>
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
