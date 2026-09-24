<?php
/**
 * AI provider settings: pick an active provider, paste its API key (BYO -
 * Legible never ships or proxies its own key), pick a model. A capability
 * check gate here, not just on the main page: API keys are more sensitive
 * than read-only findings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registered at priority 20, after the default-priority (10)
 * legible_register_admin_page() in includes/admin-page.php: a submenu's
 * add_submenu_page( 'legible', ... ) call needs the top-level 'legible' page's
 * own add_menu_page() to have already run in this same admin_menu pass, or
 * WordPress's internal $admin_page_hooks lookup for the parent slug comes
 * up empty and computes the wrong hook name for this page's callback - the
 * submenu link would render without the "admin.php?page=" prefix it needs,
 * and the page itself would refuse direct access ("Sorry, you are not
 * allowed to access this page.") even for a user who has the capability.
 * Explicit priority here is the defensive fix (not just require() order in
 * legible.php, which is where this was first caught but is a fragile,
 * implicit way to guarantee it).
 */
function legible_register_settings_page() {
	add_submenu_page(
		'legible',
		__( 'Legible Settings', 'legible' ),
		__( 'Settings', 'legible' ),
		'manage_options',
		'legible-settings',
		'legible_render_settings_page'
	);
}
add_action( 'admin_menu', 'legible_register_settings_page', 20 );

function legible_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice      = '';
	$notice_type = 'success';

	if ( isset( $_POST['legible_save_settings'] ) && check_admin_referer( 'legible_save_settings' ) ) {
		$provider = isset( $_POST['legible_active_provider'] ) ? sanitize_key( wp_unslash( $_POST['legible_active_provider'] ) ) : 'gemini';
		if ( in_array( $provider, legible_ai_providers(), true ) ) {
			update_option( LEGIBLE_AI_PROVIDER_OPTION, $provider, false );
		}

		foreach ( legible_ai_providers() as $provider_slug ) {
			$key_field = 'legible_api_key_' . $provider_slug;
			if ( isset( $_POST[ $key_field ] ) ) {
				$submitted = trim( sanitize_text_field( wp_unslash( $_POST[ $key_field ] ) ) );
				// An unchanged masked value means "leave as-is" - never overwrite a real key with the mask string.
				if ( '' === $submitted ) {
					delete_option( legible_api_key_option_name( $provider_slug ) );
				} elseif ( ! legible_is_masked_key_placeholder( $submitted ) ) {
					update_option( legible_api_key_option_name( $provider_slug ), $submitted, false );
				}
			}

			$model_field = 'legible_model_' . $provider_slug;
			if ( isset( $_POST[ $model_field ] ) ) {
				$model = trim( sanitize_text_field( wp_unslash( $_POST[ $model_field ] ) ) );
				if ( '' === $model || $model === legible_default_model_for_provider( $provider_slug ) ) {
					delete_option( legible_model_option_name( $provider_slug ) );
				} else {
					update_option( legible_model_option_name( $provider_slug ), $model, false );
				}
			}
		}

		$notice = __( 'Settings saved.', 'legible' );
	}

	if ( isset( $_POST['legible_test_connection'] ) && check_admin_referer( 'legible_test_connection' ) ) {
		$provider = legible_get_current_provider();
		$response = legible_ai_call_vision(
			$provider,
			legible_get_model_for_provider( $provider ),
			'Reply with exactly one word: "ok".',
			null,
			legible_get_api_key_for_provider( $provider ),
			array( 'max_tokens' => 10 )
		);
		if ( is_wp_error( $response ) ) {
			$notice      = $response->get_error_message();
			$notice_type = 'error';
		} else {
			$notice = sprintf(
				/* translators: %s: AI provider name */
				__( 'Connection to %s succeeded.', 'legible' ),
				legible_ai_provider_label( $provider )
			);
		}
	}

	$active_provider = legible_get_current_provider();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Legible Settings', 'legible' ); ?></h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?>"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Legible uses your own API key with an AI provider to suggest alt text for images. No key, no external call is ever made - the rule-based checks on the main Legible page work regardless.', 'legible' ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'legible_save_settings' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="legible_active_provider"><?php esc_html_e( 'Active provider', 'legible' ); ?></label></th>
					<td>
						<select name="legible_active_provider" id="legible-provider-select">
							<?php foreach ( legible_ai_providers() as $provider_slug ) : ?>
								<option value="<?php echo esc_attr( $provider_slug ); ?>" <?php selected( $active_provider, $provider_slug ); ?>>
									<?php echo esc_html( legible_ai_provider_label( $provider_slug ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Only the selected provider is used - its fields below are the ones that matter.', 'legible' ); ?></p>
					</td>
				</tr>

				<?php foreach ( legible_ai_providers() as $provider_slug ) : ?>
					<tr class="legible-provider-row legible-provider-row-<?php echo esc_attr( $provider_slug ); ?>" style="display:none;">
						<th scope="row"><label for="legible_api_key_<?php echo esc_attr( $provider_slug ); ?>"><?php echo esc_html( legible_ai_provider_label( $provider_slug ) ); ?> <?php esc_html_e( 'API key', 'legible' ); ?></label></th>
						<td>
							<input
								type="password"
								autocomplete="off"
								class="regular-text"
								id="legible_api_key_<?php echo esc_attr( $provider_slug ); ?>"
								name="legible_api_key_<?php echo esc_attr( $provider_slug ); ?>"
								value="<?php echo esc_attr( legible_masked_key_for_display( $provider_slug ) ); ?>"
							/>
							<p class="description">
								<?php echo legible_get_api_key_for_provider( $provider_slug ) ? esc_html__( 'A key is configured. Leave unchanged to keep it, or clear the field and save to remove it.', 'legible' ) : esc_html__( 'Not configured.', 'legible' ); ?>
							</p>
						</td>
					</tr>
					<tr class="legible-provider-row legible-provider-row-<?php echo esc_attr( $provider_slug ); ?>" style="display:none;">
						<th scope="row"><label for="legible_model_<?php echo esc_attr( $provider_slug ); ?>"><?php esc_html_e( 'Model', 'legible' ); ?></label></th>
						<td>
							<?php
							$models        = legible_get_models_for_provider( $provider_slug );
							$current_model = legible_get_model_for_provider( $provider_slug );
							?>
							<?php if ( empty( $models ) ) : ?>
								<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'Save a valid API key first to fetch the available models.', 'legible' ); ?></p>
								<input
									type="text"
									class="regular-text"
									id="legible_model_<?php echo esc_attr( $provider_slug ); ?>"
									name="legible_model_<?php echo esc_attr( $provider_slug ); ?>"
									value="<?php echo esc_attr( $current_model ); ?>"
									placeholder="<?php echo esc_attr( legible_default_model_for_provider( $provider_slug ) ); ?>"
								/>
							<?php else : ?>
								<select id="legible_model_<?php echo esc_attr( $provider_slug ); ?>" name="legible_model_<?php echo esc_attr( $provider_slug ); ?>">
									<?php foreach ( $models as $model_id => $label ) : ?>
										<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $current_model, $model_id ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<a href="<?php echo esc_url( add_query_arg( array( 'legible_refresh_models' => $provider_slug, '_wpnonce' => wp_create_nonce( 'legible_refresh_models' ) ) ) ); ?>">
										<?php esc_html_e( 'Refresh model list', 'legible' ); ?>
									</a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<script>
			( function ( $ ) {
				function legibleToggleProviderRows() {
					var provider = $( '#legible-provider-select' ).val();
					$( '.legible-provider-row' ).hide();
					$( '.legible-provider-row-' + provider ).show();
				}
				$( document ).on( 'change', '#legible-provider-select', legibleToggleProviderRows );
				$( legibleToggleProviderRows );
			} )( jQuery );
			</script>

			<?php submit_button( __( 'Save settings', 'legible' ), 'primary', 'legible_save_settings' ); ?>
		</form>

		<?php if ( legible_ai_is_configured() ) : ?>
			<h2><?php esc_html_e( 'Test connection', 'legible' ); ?></h2>
			<p><?php esc_html_e( 'Sends one minimal, text-only request to the active provider to confirm the key and model work. This is the only thing on this page that makes a live API call.', 'legible' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'legible_test_connection' ); ?>
				<button type="submit" name="legible_test_connection" value="1" class="button">
					<?php esc_html_e( 'Test connection now', 'legible' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The placeholder shown in an already-configured key's field instead of
 * the real value - never echo a real API key back into page HTML.
 */
define( 'LEGIBLE_MASKED_KEY_PLACEHOLDER', '••••••••••••••••' );

/**
 * @param string $provider
 * @return string
 */
function legible_masked_key_for_display( $provider ) {
	return legible_get_api_key_for_provider( $provider ) ? LEGIBLE_MASKED_KEY_PLACEHOLDER : '';
}

/**
 * @param string $value
 * @return bool True if $value is the unchanged mask placeholder, not a real key.
 */
function legible_is_masked_key_placeholder( $value ) {
	return LEGIBLE_MASKED_KEY_PLACEHOLDER === $value;
}
