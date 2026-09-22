<?php
/**
 * Anthropic Messages API, vision-capable. Supports both an image URL
 * source and a base64 source natively; passes a public URL straight
 * through when the caller has one, base64-encodes (via
 * usher_image_to_base64()) otherwise - same shape as the OpenAI-compatible
 * provider, different field names.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array $image See usher_ai_call_vision().
 * @return array|WP_Error A Messages API image content block's `source` object.
 */
function usher_anthropic_image_source( $image ) {
	if ( ! empty( $image['url'] ) && empty( $image['base64'] ) ) {
		return array(
			'type' => 'url',
			'url'  => $image['url'],
		);
	}

	$resolved = usher_image_to_base64( $image );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	return array(
		'type'       => 'base64',
		'media_type' => $resolved['mime'],
		'data'       => $resolved['data'],
	);
}

/**
 * @param string     $model   Claude model id.
 * @param string     $prompt  Plain-text instruction.
 * @param array|null $image   See usher_ai_call_vision().
 * @param string     $api_key Anthropic API key.
 * @param array      $options ['timeout', 'max_tokens'].
 * @return string|WP_Error
 */
function usher_ai_call_vision_anthropic( $model, $prompt, $image, $api_key, $options = array() ) {
	$timeout    = $options['timeout'] ?? 60;
	$max_tokens = $options['max_tokens'] ?? 1024;

	$content = array();
	if ( $image ) {
		$source = usher_anthropic_image_source( $image );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$content[] = array(
			'type'   => 'image',
			'source' => $source,
		);
	}
	$content[] = array( 'type' => 'text', 'text' => $prompt );

	$request_body = array(
		'model'      => $model,
		'max_tokens' => $max_tokens,
		'messages'   => array( array( 'role' => 'user', 'content' => $content ) ),
	);

	$response = wp_remote_post(
		'https://api.anthropic.com/v1/messages',
		array(
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( $request_body ),
			'timeout' => $timeout,
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'usher_ai_connection', __( 'Connection failed: ', 'usher' ) . $response->get_error_message() );
	}

	$http_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $http_code ) {
		return usher_ai_error_from_response( 'anthropic', $http_code, wp_remote_retrieve_body( $response ), $model );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	$text = '';
	if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
		foreach ( $data['content'] as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) && ! empty( $block['text'] ) ) {
				$text .= $block['text'];
			}
		}
	}

	if ( '' === $text ) {
		return new WP_Error( 'usher_ai_unexpected_response', __( 'Unexpected API response structure from Claude.', 'usher' ) );
	}

	return $text;
}
