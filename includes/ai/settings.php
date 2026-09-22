<?php
/**
 * AI provider configuration: BYO API key, per provider, same multi-provider
 * shape as IntelliDesc's ildesc_get_*_for_provider() functions (reused
 * deliberately, not reinvented - USHER_V1_SPEC-v2.md §0/§1.3 both point at
 * that engine). Every option is autoload=false: API keys should not sit in
 * the autoloaded options blob that loads on every request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'USHER_AI_PROVIDER_OPTION', 'usher_ai_provider' );

/**
 * @return string[] The providers this plugin knows how to call.
 */
function usher_ai_providers() {
	return array( 'gemini', 'anthropic', 'openai', 'xai', 'openrouter' );
}

/**
 * @return string Currently configured provider slug; 'gemini' if unset/invalid.
 */
function usher_get_current_provider() {
	$provider = get_option( USHER_AI_PROVIDER_OPTION, 'gemini' );
	return in_array( $provider, usher_ai_providers(), true ) ? $provider : 'gemini';
}

/**
 * @param string $provider
 * @return string Option name storing that provider's API key.
 */
function usher_api_key_option_name( $provider ) {
	return 'usher_' . $provider . '_api_key';
}

/**
 * @param string $provider
 * @return string Option name storing that provider's selected model id.
 */
function usher_model_option_name( $provider ) {
	return 'usher_' . $provider . '_model';
}

/**
 * @param string $provider
 * @return string Raw API key, or '' if not configured.
 */
function usher_get_api_key_for_provider( $provider ) {
	return (string) get_option( usher_api_key_option_name( $provider ), '' );
}

/**
 * Vision-capable default model per provider (all of these support image
 * input as of this writing - text-only models are deliberately not offered
 * as defaults here, since every current AI-fix in this plugin needs vision).
 *
 * @param string $provider
 * @return string
 */
function usher_default_model_for_provider( $provider ) {
	$defaults = array(
		'gemini'     => 'gemini-3.1-flash-lite',
		'anthropic'  => 'claude-sonnet-4-5-20250929',
		'openai'     => 'gpt-4.1-mini',
		'xai'        => 'grok-4-fast',
		'openrouter' => 'openai/gpt-4.1-mini',
	);

	return $defaults[ $provider ] ?? $defaults['gemini'];
}

/**
 * @param string $provider
 * @return string Configured model id, falling back to that provider's default.
 */
function usher_get_model_for_provider( $provider ) {
	$model = get_option( usher_model_option_name( $provider ), '' );
	return '' !== $model ? $model : usher_default_model_for_provider( $provider );
}

/**
 * @param string $provider
 * @return string Human-readable label for admin UI / error messages.
 */
function usher_ai_provider_label( $provider ) {
	$labels = array(
		'gemini'     => 'Gemini',
		'anthropic'  => 'Claude',
		'openai'     => 'OpenAI',
		'xai'        => 'Grok',
		'openrouter' => 'OpenRouter',
	);

	return $labels[ $provider ] ?? ucfirst( $provider );
}

/**
 * @return bool Whether the currently configured provider has an API key set.
 */
function usher_ai_is_configured() {
	return '' !== usher_get_api_key_for_provider( usher_get_current_provider() );
}
