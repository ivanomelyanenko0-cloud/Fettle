<?php
/**
 * OpenAI Chat Completions-shaped API, vision-capable. Shared by OpenAI,
 * xAI (Grok), and OpenRouter, all of which speak this same request/response
 * shape - same reuse this plugin's dispatch already follows IntelliDesc's
 * pattern for on the text-only side.
 *
 * Unlike the Gemini provider, this one passes a public image URL straight
 * through as `image_url.url` when the caller has one, and only falls back
 * to a `data:` URI (fetched + base64-encoded) when it doesn't - the Chat
 * Completions vision format supports both natively.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array $image See legible_ai_call_vision().
 * @return string|WP_Error The `image_url.url` value: either the direct
 *                         external URL, or a `data:` URI.
 */
function legible_openai_image_url_value( $image ) {
	if ( ! empty( $image['url'] ) && empty( $image['base64'] ) ) {
		return $image['url'];
	}

	$resolved = legible_image_to_base64( $image );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	return 'data:' . $resolved['mime'] . ';base64,' . $resolved['data'];
}

/**
 * @param string     $base_url      Chat Completions endpoint URL.
 * @param string     $provider_slug 'openai' | 'xai' | 'openrouter' - for error labelling.
 * @param string     $model         Model id.
 * @param string     $prompt        Plain-text instruction.
 * @param array|null $image         See legible_ai_call_vision().
 * @param string     $api_key       Bearer API key.
 * @param array      $options       ['timeout', 'max_tokens'].
 * @return string|WP_Error
 */
function legible_ai_call_vision_openai_compatible( $base_url, $provider_slug, $model, $prompt, $image, $api_key, $options = array() ) {
	$timeout    = $options['timeout'] ?? 60;
	$max_tokens = $options['max_tokens'] ?? 1024;

	$content = array( array( 'type' => 'text', 'text' => $prompt ) );

	if ( $image ) {
		$image_url = legible_openai_image_url_value( $image );
		if ( is_wp_error( $image_url ) ) {
			return $image_url;
		}
		$content[] = array(
			'type'      => 'image_url',
			'image_url' => array( 'url' => $image_url ),
		);
	}

	$request_body = array(
		'model'      => $model,
		'messages'   => array( array( 'role' => 'user', 'content' => $content ) ),
		'max_tokens' => $max_tokens,
	);

	$response = wp_remote_post(
		$base_url,
		array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $request_body ),
			'timeout' => $timeout,
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'legible_ai_connection', __( 'Connection failed: ', 'legible' ) . $response->get_error_message() );
	}

	$http_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $http_code ) {
		return legible_ai_error_from_response( $provider_slug, $http_code, wp_remote_retrieve_body( $response ), $model );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
		return new WP_Error(
			'legible_ai_unexpected_response',
			sprintf(
				/* translators: %s: AI provider name */
				__( 'Unexpected API response structure from %s.', 'legible' ),
				legible_ai_provider_label( $provider_slug )
			)
		);
	}

	return $data['choices'][0]['message']['content'];
}
