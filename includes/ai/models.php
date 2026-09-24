<?php
/**
 * Fetches and caches each provider's available model list, so Settings can
 * offer a dropdown instead of a free-text model-id field - the same
 * pattern IntelliDesc uses (includes/admin-settings.php there:
 * ildesc_get_{provider}_models()), reused rather than reinvented for this
 * supporting piece as much as the completion calls themselves.
 *
 * Usher-specific difference from that source: every model offered here
 * must actually support image input, since every AI-fix in this plugin
 * sends one (alt-text) or could in a future check - text-only models are
 * filtered out wherever the provider's own API exposes enough to tell,
 * not just the "exclude obviously non-chat endpoints" blacklist
 * IntelliDesc uses for its text-only needs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'USHER_MODELS_CACHE_TTL', DAY_IN_SECONDS );

/**
 * @param string $provider
 * @return string
 */
function usher_models_transient_key( $provider ) {
	return 'usher_' . $provider . '_models_list';
}

/**
 * Clears a provider's cached model list if this request explicitly asked
 * to refresh it (the "Refresh model list" link on the settings page).
 *
 * @param string $provider
 */
function usher_maybe_clear_models_cache( $provider ) {
	$requested_provider = isset( $_GET['usher_refresh_models'] ) ? sanitize_key( wp_unslash( $_GET['usher_refresh_models'] ) ) : '';
	if ( $provider === $requested_provider
		&& current_user_can( 'manage_options' )
		&& isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'usher_refresh_models' )
	) {
		delete_transient( usher_models_transient_key( $provider ) );
	}
}

/**
 * @return array<int, string> Substrings that mark a model id as not a
 *                             general vision-capable chat model, shared
 *                             across providers whose id naming overlaps
 *                             (embeddings, TTS, image-generation-only, etc).
 */
function usher_model_blacklist_words() {
	return array( 'embedding', 'tts', 'whisper', 'audio', 'voice', 'speech', 'transcribe', 'realtime', 'moderation', 'dall-e', 'image-generation' );
}

/**
 * @param string $id
 * @return bool True if $id contains none of usher_model_blacklist_words().
 */
function usher_model_id_allowed( $id ) {
	foreach ( usher_model_blacklist_words() as $bad ) {
		if ( false !== strpos( $id, $bad ) ) {
			return false;
		}
	}
	return true;
}

/**
 * @return array<string, string> model id => display label. Empty if no key configured or the fetch failed.
 */
function usher_get_gemini_models() {
	usher_maybe_clear_models_cache( 'gemini' );

	$api_key = usher_get_api_key_for_provider( 'gemini' );
	if ( '' === $api_key ) {
		return array();
	}

	$cached = get_transient( usher_models_transient_key( 'gemini' ) );
	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $api_key ), array( 'timeout' => 15 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['models'] ) ) {
		return array();
	}

	$models = array();
	foreach ( $body['models'] as $model ) {
		$name = (string) ( $model['name'] ?? '' );
		if ( false === strpos( $name, 'gemini' ) || ! in_array( 'generateContent', (array) ( $model['supportedGenerationMethods'] ?? array() ), true ) ) {
			continue;
		}
		$id = str_replace( 'models/', '', $name );
		if ( ! usher_model_id_allowed( $id ) || false !== strpos( $id, 'embedding' ) ) {
			continue;
		}
		$models[ $id ] = ( $model['displayName'] ?? $id ) . ( isset( $model['version'] ) ? ' (' . $model['version'] . ')' : '' );
	}

	krsort( $models );
	set_transient( usher_models_transient_key( 'gemini' ), $models, USHER_MODELS_CACHE_TTL );

	return $models;
}

/**
 * @return array<string, string>
 */
function usher_get_anthropic_models() {
	usher_maybe_clear_models_cache( 'anthropic' );

	$api_key = usher_get_api_key_for_provider( 'anthropic' );
	if ( '' === $api_key ) {
		return array();
	}

	$cached = get_transient( usher_models_transient_key( 'anthropic' ) );
	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get(
		'https://api.anthropic.com/v1/models',
		array(
			'headers' => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'timeout' => 15,
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['data'] ) ) {
		return array();
	}

	$models = array();
	foreach ( $body['data'] as $model ) {
		$id = (string) $model['id'];
		if ( ! usher_model_id_allowed( $id ) ) {
			continue;
		}
		// Every current Claude model in this endpoint (3+) is vision-capable; no further filtering needed beyond the shared blacklist.
		$models[ $id ] = $model['display_name'] ?? $id;
	}

	set_transient( usher_models_transient_key( 'anthropic' ), $models, USHER_MODELS_CACHE_TTL );

	return $models;
}

/**
 * @return array<string, string>
 */
function usher_get_openai_models() {
	usher_maybe_clear_models_cache( 'openai' );

	$api_key = usher_get_api_key_for_provider( 'openai' );
	if ( '' === $api_key ) {
		return array();
	}

	$cached = get_transient( usher_models_transient_key( 'openai' ) );
	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get(
		'https://api.openai.com/v1/models',
		array(
			'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			'timeout' => 15,
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['data'] ) ) {
		return array();
	}

	// Text-only reasoning/completions models live under these same prefixes too, hence the extra blacklist entries beyond the shared list - o1-mini and the bare "gpt-3.5" family, for example, have no vision input.
	$allowed_prefixes  = array( 'gpt-4', 'gpt-5', 'chatgpt-', 'o3', 'o4' );
	$extra_blacklist   = array( 'gpt-3.5', 'o1-mini', 'search', 'similarity', 'edit', 'insert', 'instruct', 'sora', 'video' );

	$models = array();
	foreach ( $body['data'] as $model ) {
		$id = (string) $model['id'];

		$is_allowed = false;
		foreach ( $allowed_prefixes as $prefix ) {
			if ( 0 === strpos( $id, $prefix ) ) {
				$is_allowed = true;
				break;
			}
		}
		if ( ! $is_allowed || ! usher_model_id_allowed( $id ) ) {
			continue;
		}
		foreach ( $extra_blacklist as $bad ) {
			if ( false !== strpos( $id, $bad ) ) {
				continue 2;
			}
		}

		$models[ $id ] = $id;
	}

	krsort( $models );
	set_transient( usher_models_transient_key( 'openai' ), $models, USHER_MODELS_CACHE_TTL );

	return $models;
}

/**
 * @return array<string, string>
 */
function usher_get_xai_models() {
	usher_maybe_clear_models_cache( 'xai' );

	$api_key = usher_get_api_key_for_provider( 'xai' );
	if ( '' === $api_key ) {
		return array();
	}

	$cached = get_transient( usher_models_transient_key( 'xai' ) );
	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get(
		'https://api.x.ai/v1/models',
		array(
			'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			'timeout' => 15,
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['data'] ) ) {
		return array();
	}

	$models = array();
	foreach ( $body['data'] as $model ) {
		$id = (string) $model['id'];
		if ( ! usher_model_id_allowed( $id ) ) {
			continue;
		}
		$models[ $id ] = $id;
	}

	krsort( $models );
	set_transient( usher_models_transient_key( 'xai' ), $models, USHER_MODELS_CACHE_TTL );

	return $models;
}

/**
 * @return array<string, string>
 */
function usher_get_openrouter_models() {
	usher_maybe_clear_models_cache( 'openrouter' );

	$api_key = usher_get_api_key_for_provider( 'openrouter' );
	if ( '' === $api_key ) {
		return array();
	}

	$cached = get_transient( usher_models_transient_key( 'openrouter' ) );
	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get(
		'https://openrouter.ai/api/v1/models',
		array(
			'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			'timeout' => 15,
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['data'] ) ) {
		return array();
	}

	$models = array();
	foreach ( $body['data'] as $model ) {
		$id = (string) $model['id'];
		if ( ! usher_model_id_allowed( $id ) ) {
			continue;
		}

		// OpenRouter is the one provider whose list endpoint actually says which models accept image input - the one place this filter can be precise rather than a naming heuristic.
		$input_modalities = $model['architecture']['input_modalities'] ?? null;
		if ( is_array( $input_modalities ) && ! in_array( 'image', $input_modalities, true ) ) {
			continue;
		}

		$models[ $id ] = $model['name'] ?? $id;
	}

	krsort( $models );
	set_transient( usher_models_transient_key( 'openrouter' ), $models, USHER_MODELS_CACHE_TTL );

	return $models;
}

/**
 * @param string $provider
 * @return array<string, string>
 */
function usher_get_models_for_provider( $provider ) {
	switch ( $provider ) {
		case 'anthropic':
			return usher_get_anthropic_models();
		case 'openai':
			return usher_get_openai_models();
		case 'xai':
			return usher_get_xai_models();
		case 'openrouter':
			return usher_get_openrouter_models();
		case 'gemini':
		default:
			return usher_get_gemini_models();
	}
}
