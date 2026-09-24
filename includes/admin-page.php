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

function legible_register_admin_page() {
	add_menu_page(
		__( 'Legible', 'legible' ),
		__( 'Legible', 'legible' ),
		'edit_others_posts',
		'legible',
		'legible_render_admin_page',
		'dashicons-universal-access',
		58
	);
}
add_action( 'admin_menu', 'legible_register_admin_page' );

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
function legible_ai_fix_registry() {
	return array(
		'alt-text'  => array(
			'generate' => 'legible_generate_alt_text_suggestion',
			'apply'    => 'legible_apply_alt_text_fix',
		),
		'contrast'  => array(
			'generate' => 'legible_generate_contrast_suggestion',
			'apply'    => 'legible_apply_contrast_fix',
		),
		'link-text' => array(
			'generate' => 'legible_generate_link_text_suggestion',
			'apply'    => 'legible_apply_link_text_fix',
		),
	);
}

/**
 * @param array<int, array> $results post_id => scan result.
 * @return array{total: int, critical: int, warning: int, posts_with_findings: int}
 */
function legible_summarise_results( $results ) {
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
 * @param array  $result Scan result from legible_scan_post().
 * @param string $instance_key
 * @return array|null
 */
function legible_find_finding_by_key( $result, $instance_key ) {
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
function legible_normalise_ai_suggestion( $suggestion ) {
	if ( is_array( $suggestion ) && isset( $suggestion['color'] ) ) {
		$display = $suggestion['color'];
		if ( ! empty( $suggestion['used_fallback'] ) ) {
			$display .= ' ' . __( '(the AI\'s own suggestion did not actually pass verification, so this is a black/white fallback that reliably passes instead)', 'legible' );
		}
		return array( 'value' => $suggestion['color'], 'display' => $display );
	}

	return array( 'value' => (string) $suggestion, 'display' => (string) $suggestion );
}

function legible_render_admin_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$notice      = '';
	$notice_type = 'success';
	$registry    = legible_ai_fix_registry();

	if ( isset( $_POST['legible_scan'] ) && check_admin_referer( 'legible_scan' ) ) {
		legible_scan_site( ! empty( $_POST['legible_force'] ) );
		$notice = __( 'Scan complete.', 'legible' );
	}

	if ( isset( $_POST['legible_dismiss'] ) && check_admin_referer( 'legible_dismiss' ) ) {
		$dismiss_post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key    = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		if ( $dismiss_post_id && $instance_key ) {
			legible_dismiss_finding( $dismiss_post_id, $instance_key );
			$notice = __( 'Marked as a false positive.', 'legible' );
		}
	}

	if ( isset( $_POST['legible_generate_fix'] ) && check_admin_referer( 'legible_generate_fix' ) ) {
		$fix_post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		$scan         = $fix_post_id ? legible_scan_post( $fix_post_id ) : null;
		$finding      = $scan && ! is_wp_error( $scan ) ? legible_find_finding_by_key( $scan, $instance_key ) : null;
		$fix_type     = $finding['type'] ?? '';

		if ( ! $finding || empty( $registry[ $fix_type ] ) ) {
			$notice      = __( 'Could not find that issue - try scanning again.', 'legible' );
			$notice_type = 'error';
		} else {
			$suggestion = call_user_func( $registry[ $fix_type ]['generate'], $fix_post_id, $finding );
			if ( is_wp_error( $suggestion ) ) {
				$notice      = $suggestion->get_error_message();
				$notice_type = 'error';
			} else {
				$normalised = legible_normalise_ai_suggestion( $suggestion );
				legible_store_pending_fix( $fix_post_id, $instance_key, wp_json_encode( array_merge( $normalised, array( 'type' => $fix_type ) ) ) );
				$notice = __( 'Suggestion generated - review it below before applying.', 'legible' );
			}
		}
	}

	if ( isset( $_POST['legible_apply_fix'] ) && check_admin_referer( 'legible_apply_fix' ) ) {
		$fix_post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		$stored       = $fix_post_id ? legible_get_pending_fix( $fix_post_id, $instance_key ) : false;
		$decoded      = false !== $stored ? json_decode( $stored, true ) : null;

		if ( ! is_array( $decoded ) || empty( $registry[ $decoded['type'] ?? '' ] ) ) {
			$notice      = __( 'That suggestion expired - generate it again.', 'legible' );
			$notice_type = 'error';
		} else {
			$result = call_user_func( $registry[ $decoded['type'] ]['apply'], $fix_post_id, $instance_key, $decoded['value'] );
			if ( is_wp_error( $result ) ) {
				$notice      = $result->get_error_message();
				$notice_type = 'error';
			} else {
				legible_clear_pending_fix( $fix_post_id, $instance_key );
				$notice = __( 'Fix applied.', 'legible' );
			}
		}
	}

	if ( isset( $_POST['legible_discard_fix'] ) && check_admin_referer( 'legible_discard_fix' ) ) {
		$fix_post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instance_key = isset( $_POST['instance_key'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_key'] ) ) : '';
		if ( $fix_post_id && $instance_key ) {
			legible_clear_pending_fix( $fix_post_id, $instance_key );
			$notice = __( 'Suggestion discarded.', 'legible' );
		}
	}

	if ( isset( $_POST['legible_check_landmarks'] ) && check_admin_referer( 'legible_check_landmarks' ) ) {
		$landmark_result = legible_run_landmark_check();
		if ( $landmark_result['error'] ) {
			$notice      = $landmark_result['error'];
			$notice_type = 'error';
		} else {
			$notice = __( 'Theme landmark check complete.', 'legible' );
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
		$result = legible_scan_post( $post_id );
		if ( ! is_wp_error( $result ) && ! empty( $result['findings'] ) ) {
			$results[ $post_id ] = $result;
		}
	}
	$summary = legible_summarise_results( $results );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Legible', 'legible' ); ?></h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?>"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! legible_ai_is_configured() ) : ?>
			<div class="notice notice-info">
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to the Legible AI settings page */
							__( 'No AI provider is configured yet, so AI-suggested fixes aren\'t available. <a href="%s">Add an API key</a> to enable them - the rule-based checks below work either way.', 'legible' ),
							array( 'a' => array( 'href' => true ) )
						),
						esc_url( admin_url( 'admin.php?page=legible-settings' ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<p>
			<?php if ( 0 === $summary['total'] ) : ?>
				<?php esc_html_e( 'No accessibility issues found in the last scan of your published posts and pages.', 'legible' ); ?>
			<?php else : ?>
				<?php
				printf(
					/* translators: 1: number of issues, 2: number of critical issues, 3: number of posts/pages affected */
					esc_html__( '%1$d issues found (%2$d critical) across %3$d posts and pages.', 'legible' ),
					(int) $summary['total'],
					(int) $summary['critical'],
					(int) $summary['posts_with_findings']
				);
				?>
			<?php endif; ?>
		</p>

		<form method="post" style="margin-bottom: 1.5em;">
			<?php wp_nonce_field( 'legible_scan' ); ?>
			<label style="margin-right: 1em;">
				<input type="checkbox" name="legible_force" value="1" />
				<?php esc_html_e( 'Rescan everything, ignoring the cache', 'legible' ); ?>
			</label>
			<button type="submit" name="legible_scan" value="1" class="button button-primary">
				<?php esc_html_e( 'Scan now', 'legible' ); ?>
			</button>
		</form>

		<h2><?php esc_html_e( 'Theme landmarks', 'legible' ); ?></h2>
		<p><?php esc_html_e( 'Checked once per active theme (not per post) - landmark regions like the main content area come from your theme\'s templates, not your post content.', 'legible' ); ?></p>
		<?php $landmark_cache = legible_get_cached_landmark_result(); ?>
		<?php if ( $landmark_cache ) : ?>
			<?php if ( empty( $landmark_cache['findings'] ) ) : ?>
				<p><?php esc_html_e( 'No landmark issues found on the front page.', 'legible' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $landmark_cache['findings'] as $finding ) : ?>
						<li><strong><?php echo esc_html( 'critical' === $finding['severity'] ? __( 'Critical:', 'legible' ) : __( 'Warning:', 'legible' ) ); ?></strong> <?php echo esc_html( $finding['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: %s: human-readable time, e.g. "5 minutes ago" */
					esc_html__( 'Last checked %s ago.', 'legible' ),
					esc_html( human_time_diff( $landmark_cache['checked_at'] ) )
				);
				?>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'Not checked yet for the current theme.', 'legible' ); ?></p>
		<?php endif; ?>
		<form method="post" style="margin-bottom: 1.5em;">
			<?php wp_nonce_field( 'legible_check_landmarks' ); ?>
			<button type="submit" name="legible_check_landmarks" value="1" class="button">
				<?php esc_html_e( 'Check theme landmarks now', 'legible' ); ?>
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
							<th style="width: 8em;"><?php esc_html_e( 'Severity', 'legible' ); ?></th>
							<th style="width: 10em;"><?php esc_html_e( 'Check', 'legible' ); ?></th>
							<th><?php esc_html_e( 'Issue', 'legible' ); ?></th>
							<th style="width: 16em;"><?php esc_html_e( 'Action', 'legible' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['findings'] as $finding ) : ?>
							<?php
							$fix_type = $finding['type'] ?? '';
							$fixable  = isset( $registry[ $fix_type ] ) && ! empty( $finding['instance_key'] );
							$pending  = $fixable ? legible_get_pending_fix( $post_id, $finding['instance_key'] ) : false;
							$decoded  = false !== $pending ? json_decode( $pending, true ) : null;
							?>
							<tr>
								<td><strong><?php echo esc_html( 'critical' === ( $finding['severity'] ?? '' ) ? __( 'Critical', 'legible' ) : __( 'Warning', 'legible' ) ); ?></strong></td>
								<td><code><?php echo esc_html( $fix_type ); ?></code></td>
								<td>
									<?php echo esc_html( $finding['message'] ?? '' ); ?>

									<?php if ( 'alt-text' === $fix_type && ! empty( $finding['src'] ) ) : ?>
										<br /><img src="<?php echo esc_url( $finding['src'] ); ?>" alt="" style="max-width: 80px; max-height: 60px; margin-top: 0.5em;" />
									<?php elseif ( 'contrast' === $fix_type && ! empty( $finding['bg_hex'] ) ) : ?>
										<br />
										<span style="display: inline-block; padding: 0.3em 0.6em; margin-top: 0.5em; background: <?php echo esc_attr( $finding['bg_hex'] ); ?>; color: <?php echo esc_attr( $finding['text_hex'] ); ?>;">
											<?php esc_html_e( 'Sample text (current)', 'legible' ); ?>
										</span>
									<?php elseif ( 'link-text' === $fix_type && ! empty( $finding['href'] ) ) : ?>
										<br /><code style="font-size: 0.9em;"><?php echo esc_html( $finding['href'] ); ?></code>
									<?php endif; ?>

									<?php if ( is_array( $decoded ) ) : ?>
										<p style="margin-top: 0.5em;">
											<strong><?php esc_html_e( 'Suggestion:', 'legible' ); ?></strong>
											"<?php echo esc_html( $decoded['display'] ?? '' ); ?>"
											<?php if ( 'contrast' === $fix_type && ! empty( $finding['bg_hex'] ) && ! empty( $decoded['value'] ) ) : ?>
												<br />
												<span style="display: inline-block; padding: 0.3em 0.6em; margin-top: 0.3em; background: <?php echo esc_attr( $finding['bg_hex'] ); ?>; color: <?php echo esc_attr( $decoded['value'] ); ?>;">
													<?php esc_html_e( 'Sample text (proposed)', 'legible' ); ?>
												</span>
											<?php endif; ?>
										</p>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $fixable ) : ?>
										<?php if ( is_array( $decoded ) ) : ?>
											<form method="post" style="display: inline-block; margin-right: 0.3em;">
												<?php wp_nonce_field( 'legible_apply_fix' ); ?>
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
												<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
												<button type="submit" name="legible_apply_fix" value="1" class="button button-primary button-small">
													<?php esc_html_e( 'Apply', 'legible' ); ?>
												</button>
											</form>
											<form method="post" style="display: inline-block; margin-right: 0.3em;">
												<?php wp_nonce_field( 'legible_discard_fix' ); ?>
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
												<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
												<button type="submit" name="legible_discard_fix" value="1" class="button button-small">
													<?php esc_html_e( 'Discard', 'legible' ); ?>
												</button>
											</form>
										<?php elseif ( legible_ai_is_configured() ) : ?>
											<form method="post" style="display: inline-block; margin-right: 0.3em;">
												<?php wp_nonce_field( 'legible_generate_fix' ); ?>
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
												<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
												<button type="submit" name="legible_generate_fix" value="1" class="button button-small">
													<?php esc_html_e( 'Generate AI fix', 'legible' ); ?>
												</button>
											</form>
										<?php endif; ?>
									<?php endif; ?>
									<?php if ( ! empty( $finding['instance_key'] ) ) : ?>
										<form method="post" style="display: inline-block;">
											<?php wp_nonce_field( 'legible_dismiss' ); ?>
											<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
											<input type="hidden" name="instance_key" value="<?php echo esc_attr( $finding['instance_key'] ); ?>" />
											<button type="submit" name="legible_dismiss" value="1" class="button button-small">
												<?php esc_html_e( 'Not an issue', 'legible' ); ?>
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
