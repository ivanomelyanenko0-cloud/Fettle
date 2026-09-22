<?php
/**
 * AI provider settings: pick an active provider, paste its API key (BYO,
 * per USHER_STRATEGY.md - Usher never ships or proxies its own key), pick
 * a model. A capability check gate here, not just on the main page: API
 * keys are more sensitive than read-only findings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function usher_register_settings_page() {
	add_submenu_page(
		'usher',
		__( 'Usher Settings', 'usher' ),
		__( 'Settings', 'usher' ),
		'manage_options',
		'usher-settings',
		'usher_render_settings_page'
	);
}
add_action( 'admin_menu', 'usher_register_settings_page' );

function usher_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice      = '';
	$notice_type = 'success';

	if ( isset( $_POST['usher_save_settings'] ) && check_admin_referer( 'usher_save_settings' ) ) {
		$provider = isset( $_POST['usher_active_provider'] ) ? sanitize_key( wp_unslash( $_POST['usher_active_provider'] ) ) : 'gemini';
		if ( in_array( $provider, usher_ai_providers(), true ) ) {
			update_option( USHER_AI_PROVIDER_OPTION, $provider, false );
		}

		foreach ( usher_ai_providers() as $provider_slug ) {
			$key_field = 'usher_api_key_' . $provider_slug;
			if ( isset( $_POST[ $key_field ] ) ) {
				$submitted = trim( sanitize_text_field( wp_unslash( $_POST[ $key_field ] ) ) );
				// An unchanged masked value means "leave as-is" - never overwrite a real key with the mask string.
				if ( '' === $submitted ) {
					delete_option( usher_api_key_option_name( $provider_slug ) );
				} elseif ( ! usher_is_masked_key_placeholder( $submitted ) ) {
					update_option( usher_api_key_option_name( $provider_slug ), $submitted, false );
				}
			}

			$model_field = 'usher_model_' . $provider_slug;
			if ( isset( $_POST[ $model_field ] ) ) {
				$model = trim( sanitize_text_field( wp_unslash( $_POST[ $model_field ] ) ) );
				if ( '' === $model || $model === usher_default_model_for_provider( $provider_slug ) ) {
					delete_option( usher_model_option_name( $provider_slug ) );
				} else {
					update_option( usher_model_option_name( $provider_slug ), $model, false );
				}
			}
		}

		$notice = __( 'Settings saved.', 'usher' );
	}

	if ( isset( $_POST['usher_test_connection'] ) && check_admin_referer( 'usher_test_connection' ) ) {
		$provider = usher_get_current_provider();
		$response = usher_ai_call_vision(
			$provider,
			usher_get_model_for_provider( $provider ),
			'Reply with exactly one word: "ok".',
			null,
			usher_get_api_key_for_provider( $provider ),
			array( 'max_tokens' => 10 )
		);
		if ( is_wp_error( $response ) ) {
			$notice      = $response->get_error_message();
			$notice_type = 'error';
		} else {
			$notice = sprintf(
				/* translators: %s: AI provider name */
				__( 'Connection to %s succeeded.', 'usher' ),
				usher_ai_provider_label( $provider )
			);
		}
	}

	$active_provider = usher_get_current_provider();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Usher Settings', 'usher' ); ?></h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?>"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Usher uses your own API key with an AI provider to suggest alt text for images. No key, no external call is ever made - the rule-based checks on the main Usher page work regardless.', 'usher' ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'usher_save_settings' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="usher_active_provider"><?php esc_html_e( 'Active provider', 'usher' ); ?></label></th>
					<td>
						<select name="usher_active_provider" id="usher_active_provider">
							<?php foreach ( usher_ai_providers() as $provider_slug ) : ?>
								<option value="<?php echo esc_attr( $provider_slug ); ?>" <?php selected( $active_provider, $provider_slug ); ?>>
									<?php echo esc_html( usher_ai_provider_label( $provider_slug ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<?php foreach ( usher_ai_providers() as $provider_slug ) : ?>
				<h2><?php echo esc_html( usher_ai_provider_label( $provider_slug ) ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="usher_api_key_<?php echo esc_attr( $provider_slug ); ?>"><?php esc_html_e( 'API key', 'usher' ); ?></label></th>
						<td>
							<input
								type="password"
								autocomplete="off"
								class="regular-text"
								id="usher_api_key_<?php echo esc_attr( $provider_slug ); ?>"
								name="usher_api_key_<?php echo esc_attr( $provider_slug ); ?>"
								value="<?php echo esc_attr( usher_masked_key_for_display( $provider_slug ) ); ?>"
							/>
							<p class="description">
								<?php echo usher_get_api_key_for_provider( $provider_slug ) ? esc_html__( 'A key is configured. Leave unchanged to keep it, or clear the field and save to remove it.', 'usher' ) : esc_html__( 'Not configured.', 'usher' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="usher_model_<?php echo esc_attr( $provider_slug ); ?>"><?php esc_html_e( 'Model', 'usher' ); ?></label></th>
						<td>
							<input
								type="text"
								class="regular-text"
								id="usher_model_<?php echo esc_attr( $provider_slug ); ?>"
								name="usher_model_<?php echo esc_attr( $provider_slug ); ?>"
								value="<?php echo esc_attr( usher_get_model_for_provider( $provider_slug ) ); ?>"
							/>
						</td>
					</tr>
				</table>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save settings', 'usher' ), 'primary', 'usher_save_settings' ); ?>
		</form>

		<?php if ( usher_ai_is_configured() ) : ?>
			<h2><?php esc_html_e( 'Test connection', 'usher' ); ?></h2>
			<p><?php esc_html_e( 'Sends one minimal, text-only request to the active provider to confirm the key and model work. This is the only thing on this page that makes a live API call.', 'usher' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'usher_test_connection' ); ?>
				<button type="submit" name="usher_test_connection" value="1" class="button">
					<?php esc_html_e( 'Test connection now', 'usher' ); ?>
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
define( 'USHER_MASKED_KEY_PLACEHOLDER', '••••••••••••••••' );

/**
 * @param string $provider
 * @return string
 */
function usher_masked_key_for_display( $provider ) {
	return usher_get_api_key_for_provider( $provider ) ? USHER_MASKED_KEY_PLACEHOLDER : '';
}

/**
 * @param string $value
 * @return bool True if $value is the unchanged mask placeholder, not a real key.
 */
function usher_is_masked_key_placeholder( $value ) {
	return USHER_MASKED_KEY_PLACEHOLDER === $value;
}
