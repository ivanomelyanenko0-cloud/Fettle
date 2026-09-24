<?php
/**
 * Shared image-fetching helper for the vision providers. Callers building
 * an AI-fix image reference decide base64-vs-URL once (base64 for
 * drafts/unpublished content so a private URL is never sent to the
 * provider; a direct URL for published public content, cheaper/faster
 * since the provider fetches it itself). A provider that cannot reliably
 * accept an arbitrary external URL falls
 * back to fetching and base64-encoding it here instead of guessing at an
 * unsupported request shape.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array $image array{ base64?: string, url?: string, mime: string }.
 * @return array{ data: string, mime: string }|WP_Error base64 data + mime type.
 */
function legible_image_to_base64( $image ) {
	if ( ! empty( $image['base64'] ) ) {
		return array(
			'data' => $image['base64'],
			'mime' => $image['mime'] ?? 'image/jpeg',
		);
	}

	if ( empty( $image['url'] ) ) {
		return new WP_Error( 'legible_ai_no_image', __( 'No image data or URL was provided.', 'legible' ) );
	}

	$response = wp_remote_get(
		$image['url'],
		array(
			'timeout' => 20,
			// A max byte size guard is applied by the caller before this point (see the AI-fix flow) - this is a plain fetch, not a re-validation.
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'legible_ai_image_fetch_failed', __( 'Could not fetch the image to send to the AI provider: ', 'legible' ) . $response->get_error_message() );
	}

	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'legible_ai_image_fetch_failed', __( 'Could not fetch the image to send to the AI provider (unexpected HTTP status).', 'legible' ) );
	}

	$body = wp_remote_retrieve_body( $response );
	if ( '' === $body ) {
		return new WP_Error( 'legible_ai_image_fetch_failed', __( 'The fetched image was empty.', 'legible' ) );
	}

	$mime = wp_remote_retrieve_header( $response, 'content-type' );
	if ( ! is_string( $mime ) || '' === $mime ) {
		$mime = $image['mime'] ?? 'image/jpeg';
	}

	return array(
		'data' => base64_encode( $body ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for a vision API request, not obfuscation.
		'mime' => $mime,
	);
}
