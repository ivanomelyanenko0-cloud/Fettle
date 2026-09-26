<?php
/**
 * AI provider configuration: BYO API key, per provider, same multi-provider
 * shape as IntelliDesc's ildesc_get_*_for_provider() functions (reused
 * deliberately, not reinvented). Every option is autoload=false: API keys
 * should not sit in the autoloaded options blob that loads on every
 * request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FETTLE_AI_PROVIDER_OPTION', 'fettle_ai_provider' );

/**
 * Pseudo-provider slug for the WordPress AI Client (core, 7.0+): the site
 * owner picks and configures the actual provider once, under Settings ->
 * Connectors, and Fettle holds no key or model of its own for it.
 */
define( 'FETTLE_WP_AI_PROVIDER', 'wp_ai' );

/**
 * @return string[] The bring-your-own-key providers this plugin calls directly.
 */
function fettle_ai_providers() {
	return array( 'gemini', 'anthropic', 'openai', 'xai', 'openrouter' );
}

/**
 * @return bool Whether this WordPress ships the AI Client and allows AI on this request.
 */
function fettle_wp_ai_available() {
	return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && call_user_func( 'wp_supports_ai' );
}

/**
 * Starts a WordPress AI Client prompt. Only ever reached behind
 * fettle_wp_ai_available(); the core functions are called by name because
 * this plugin still supports WordPress below 7.0, where they don't exist.
 *
 * @param string $prompt Plain-text prompt.
 * @return WP_AI_Client_Prompt_Builder
 */
function fettle_wp_ai_prompt( $prompt ) {
	return call_user_func( 'wp_ai_client_prompt', $prompt );
}

/**
 * Whether a Connectors-configured provider can actually serve a text
 * prompt. Cached per request - it walks the provider registry, and the
 * admin page asks once per finding row.
 *
 * @return bool
 */
function fettle_wp_ai_is_ready() {
	static $ready = null;

	if ( null === $ready ) {
		$ready = fettle_wp_ai_available() && true === fettle_wp_ai_prompt( 'ok' )->is_supported_for_text_generation();
	}

	return $ready;
}

/**
 * @return string[] Every provider slug selectable right now, WordPress AI first when available.
 */
function fettle_selectable_providers() {
	$providers = fettle_ai_providers();
	if ( fettle_wp_ai_available() ) {
		array_unshift( $providers, FETTLE_WP_AI_PROVIDER );
	}
	return $providers;
}

/**
 * Defaults to the WordPress AI Client when core has one, so a site that
 * already configured a connector works with no Fettle-specific setup.
 *
 * @return string Currently configured provider slug.
 */
function fettle_get_current_provider() {
	$fallback = fettle_wp_ai_available() ? FETTLE_WP_AI_PROVIDER : 'gemini';
	$provider = get_option( FETTLE_AI_PROVIDER_OPTION, $fallback );
	return in_array( $provider, fettle_selectable_providers(), true ) ? $provider : $fallback;
}

/**
 * @param string $provider
 * @return string Option name storing that provider's API key.
 */
function fettle_api_key_option_name( $provider ) {
	return 'fettle_' . $provider . '_api_key';
}

/**
 * @param string $provider
 * @return string Option name storing that provider's selected model id.
 */
function fettle_model_option_name( $provider ) {
	return 'fettle_' . $provider . '_model';
}

/**
 * @param string $provider
 * @return string Raw API key, or '' if not configured.
 */
function fettle_get_api_key_for_provider( $provider ) {
	return (string) get_option( fettle_api_key_option_name( $provider ), '' );
}

/**
 * Vision-capable default model per provider (all of these support image
 * input as of this writing - text-only models are deliberately not offered
 * as defaults here, since every current AI-fix in this plugin needs vision).
 *
 * @param string $provider
 * @return string
 */
function fettle_default_model_for_provider( $provider ) {
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
function fettle_get_model_for_provider( $provider ) {
	if ( FETTLE_WP_AI_PROVIDER === $provider ) {
		return '';
	}

	$model = get_option( fettle_model_option_name( $provider ), '' );
	return '' !== $model ? $model : fettle_default_model_for_provider( $provider );
}

/**
 * @param string $provider
 * @return string Human-readable label for admin UI / error messages.
 */
function fettle_ai_provider_label( $provider ) {
	$labels = array(
		'wp_ai'      => __( 'WordPress AI (Settings > Connectors)', 'fettle' ),
		'gemini'     => 'Gemini',
		'anthropic'  => 'Claude',
		'openai'     => 'OpenAI',
		'xai'        => 'Grok',
		'openrouter' => 'OpenRouter',
	);

	return $labels[ $provider ] ?? ucfirst( $provider );
}

/**
 * @return bool Whether the active provider can take a request: a connector for WordPress AI, an API key otherwise.
 */
function fettle_ai_is_configured() {
	$provider = fettle_get_current_provider();

	if ( FETTLE_WP_AI_PROVIDER === $provider ) {
		return fettle_wp_ai_is_ready();
	}

	return '' !== fettle_get_api_key_for_provider( $provider );
}
