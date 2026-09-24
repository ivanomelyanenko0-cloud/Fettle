<?php
/**
 * Google Gemini generateContent API, vision-capable.
 *
 * Always sends the image as inline base64 (`inline_data`), even when the
 * caller only has a URL - Gemini's `file_data.file_uri` is documented for
 * Files-API-uploaded resources, not reliably for an arbitrary external
 * image URL, so guessing at that shape here would risk a confusing failure
 * mode at AI-fix time. fettle_image_to_base64() does the URL fetch when
 * needed. This is a deliberate, documented trade against the "send a URL,
 * let the provider fetch it" efficiency used for published content
 * elsewhere - correctness over that optimisation, for this one provider.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string     $model   Gemini model id.
 * @param string     $prompt  Plain-text instruction.
 * @param array|null $image   See fettle_ai_call_vision().
 * @param string     $api_key Gemini API key.
 * @param array      $options ['timeout'].
 * @return string|WP_Error
 */
function fettle_ai_call_vision_gemini( $model, $prompt, $image, $api_key, $options = array() ) {
	$timeout = $options['timeout'] ?? 60;

	$parts = array( array( 'text' => $prompt ) );

	if ( $image ) {
		$resolved = fettle_image_to_base64( $image );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$parts[] = array(
			'inline_data' => array(
				'mime_type' => $resolved['mime'],
				'data'      => $resolved['data'],
			),
		);
	}

	$request_json = wp_json_encode(
		array( 'contents' => array( array( 'role' => 'user', 'parts' => $parts ) ) )
	);

	$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . rawurlencode( $api_key );

	$response = wp_remote_post(
		$url,
		array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $request_json,
			'timeout' => $timeout,
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'fettle_ai_connection', __( 'Connection failed: ', 'fettle' ) . $response->get_error_message() );
	}

	$http_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $http_code ) {
		return fettle_ai_error_from_response( 'gemini', $http_code, wp_remote_retrieve_body( $response ), $model );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
		$finish_reason = $data['candidates'][0]['finishReason'] ?? '';
		if ( 'SAFETY' === $finish_reason ) {
			return new WP_Error( 'fettle_ai_safety', __( 'Gemini blocked the response due to safety filters.', 'fettle' ) );
		}
		return new WP_Error( 'fettle_ai_unexpected_response', __( 'Unexpected API response structure from Gemini.', 'fettle' ) );
	}

	return $data['candidates'][0]['content']['parts'][0]['text'];
}
