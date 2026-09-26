<?php
/**
 * WordPress AI Client (core, 7.0+) vision call. The provider, credentials
 * and model all come from the site's Settings > Connectors configuration -
 * this file never sees an API key or a provider endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string     $prompt  Plain-text instruction.
 * @param array|null $image   See fettle_ai_call_vision().
 * @param array      $options ['timeout' => 60].
 * @return string|WP_Error
 */
function fettle_ai_call_vision_wp_ai( $prompt, $image, $options = array() ) {
	if ( ! fettle_wp_ai_available() ) {
		return new WP_Error( 'fettle_ai_wp_ai_unavailable', __( 'The WordPress AI Client is not available on this site.', 'fettle' ) );
	}

	$builder = fettle_wp_ai_prompt( $prompt );

	if ( $image ) {
		// Always inline: not every connector's provider accepts an arbitrary remote image URL.
		$resolved = fettle_image_to_base64( $image );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$builder->with_file( 'data:' . $resolved['mime'] . ';base64,' . $resolved['data'], $resolved['mime'] );
	}

	// $options['max_tokens'] is deliberately not forwarded: on reasoning models (e.g. current Gemini) the cap also
	// covers hidden thinking tokens, so the small per-fix caps return an empty or truncated answer.

	$request_options_class = 'WordPress\AiClient\Providers\Http\DTO\RequestOptions';
	if ( class_exists( $request_options_class ) ) {
		$builder->using_request_options( $request_options_class::fromArray( array( 'timeout' => (float) ( $options['timeout'] ?? 60 ) ) ) );
	}

	if ( true !== $builder->is_supported_for_text_generation() ) {
		return new WP_Error(
			'fettle_ai_wp_ai_unsupported',
			$image
				? __( 'No AI connector configured under Settings > Connectors supports image input. Configure one that does, or pick a provider with your own API key in Fettle settings.', 'fettle' )
				: __( 'No AI connector is configured under Settings > Connectors.', 'fettle' )
		);
	}

	$text = $builder->generate_text();

	if ( is_wp_error( $text ) ) {
		// Re-coded to the same fettle_ai_http_{status} shape the direct providers use, so callers (e.g. rate-limit backoff on 429) handle both paths alike.
		$data   = $text->get_error_data();
		$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 500;
		return new WP_Error(
			'fettle_ai_http_' . $status,
			sprintf(
				/* translators: %s: error message from the WordPress AI Client */
				__( 'WordPress AI request failed: %s', 'fettle' ),
				$text->get_error_message()
			)
		);
	}

	return (string) $text;
}
