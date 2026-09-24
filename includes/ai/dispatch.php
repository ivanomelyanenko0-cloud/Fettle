<?php
/**
 * Multi-provider AI dispatch, vision-capable (image + text in one call) -
 * an extension of IntelliDesc's text-only ildesc_ai_call() pattern
 * (includes/ai-dispatch.php there) to support the image input every AI-fix
 * in Legible needs. Error handling/labelling mirrors that plugin's
 * ildesc_ai_error_from_response(): a battle-tested pattern, not a place to
 * improvise.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/providers/gemini.php';
require_once __DIR__ . '/providers/openai-compatible.php';
require_once __DIR__ . '/providers/anthropic.php';

/**
 * @param string $provider  Provider slug.
 * @param int    $http_code HTTP status from the provider.
 * @param string $response_body Raw response body.
 * @param string $model     Model id that was requested (for the 404 case).
 * @return WP_Error
 */
function legible_ai_error_from_response( $provider, $http_code, $response_body, $model = '' ) {
	$label      = legible_ai_provider_label( $provider );
	$error_data = json_decode( $response_body, true );

	$raw_message = $error_data['error']['message'] ?? '';
	if ( empty( $raw_message ) && is_string( $error_data['error'] ?? null ) ) {
		$raw_message = $error_data['error'];
	}
	$api_details = $raw_message ? ' — ' . $raw_message : '';

	if ( 401 !== $http_code && $raw_message && preg_match( '/api key|api_key|authentication|unauthorized/i', $raw_message ) ) {
		$http_code = 401;
	}

	$generic_messages = array(
		/* translators: %s: AI provider name (e.g. Gemini, Claude) */
		400 => sprintf( __( 'Bad request to %s. The request may be malformed or the image too large.', 'legible' ), $label ),
		/* translators: %s: AI provider name (e.g. Gemini, Claude) */
		401 => sprintf( __( 'Invalid %s API key. Check it in Legible settings.', 'legible' ), $label ),
		/* translators: %s: AI provider name (e.g. Gemini, Claude) */
		403 => sprintf( __( 'Access denied by %s. Check your account permissions/billing.', 'legible' ), $label ),
		/* translators: %s: AI provider name (e.g. Gemini, Claude) */
		404 => sprintf( __( 'Model not found on %s. It may have been renamed or removed.', 'legible' ), $label ),
		/* translators: %s: AI provider name (e.g. Gemini, Claude) */
		429 => sprintf( __( '%s rate limit exceeded. Wait a moment and try again.', 'legible' ), $label ),
		/* translators: %s: AI provider name (e.g. Gemini, Claude) */
		500 => sprintf( __( '%s internal server error. Try again in a few moments.', 'legible' ), $label ),
		/* translators: %s: AI provider name (e.g. Gemini, Claude) */
		503 => sprintf( __( '%s is temporarily unavailable (overloaded or maintenance).', 'legible' ), $label ),
	);

	$message = $generic_messages[ $http_code ]
		?? sprintf(
			/* translators: 1: AI provider name, 2: HTTP status code */
			__( '%1$s returned an unexpected error (HTTP %2$d).', 'legible' ),
			$label,
			$http_code
		);

	return new WP_Error( 'legible_ai_http_' . $http_code, $message . $api_details );
}

/**
 * Dispatches a single vision-capable completion request.
 *
 * @param string     $provider Provider slug.
 * @param string     $model    Provider-specific model id.
 * @param string     $prompt   Plain-text instruction.
 * @param array|null $image    null for text-only, or array{
 *                                  base64?: string, url?: string, mime: string
 *                              } - exactly one of base64/url should be set.
 * @param string     $api_key  Raw API key for that provider.
 * @param array      $options  ['timeout' => 60].
 * @return string|WP_Error Raw text response on success, WP_Error on failure.
 */
function legible_ai_call_vision( $provider, $model, $prompt, $image, $api_key, $options = array() ) {
	if ( '' === trim( (string) $api_key ) ) {
		return new WP_Error( 'legible_ai_no_key', __( 'No API key configured for this provider.', 'legible' ) );
	}

	switch ( $provider ) {
		case 'anthropic':
			return legible_ai_call_vision_anthropic( $model, $prompt, $image, $api_key, $options );
		case 'openai':
			return legible_ai_call_vision_openai_compatible( 'https://api.openai.com/v1/chat/completions', 'openai', $model, $prompt, $image, $api_key, $options );
		case 'xai':
			return legible_ai_call_vision_openai_compatible( 'https://api.x.ai/v1/chat/completions', 'xai', $model, $prompt, $image, $api_key, $options );
		case 'openrouter':
			return legible_ai_call_vision_openai_compatible( 'https://openrouter.ai/api/v1/chat/completions', 'openrouter', $model, $prompt, $image, $api_key, $options );
		case 'gemini':
		default:
			return legible_ai_call_vision_gemini( $model, $prompt, $image, $api_key, $options );
	}
}
