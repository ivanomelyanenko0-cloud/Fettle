<?php
/**
 * Site Health check for the AI provider. Deliberately passive - checks
 * that a provider/key/model are configured, does not make a live
 * API call. A live call costs the user real money on their own BYO key;
 * Site Health runs on every visit to that admin screen and on a
 * recurring WP-Cron schedule, so making one automatically here would spend
 * the user's API budget without an explicit action from them. The
 * "Test connection" button on the Legible settings page is the explicit,
 * one-shot equivalent (see includes/settings-page.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function legible_register_site_health_tests( $tests ) {
	$tests['direct']['legible_ai_provider'] = array(
		'label' => __( 'Legible AI provider', 'legible' ),
		'test'  => 'legible_test_ai_provider',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'legible_register_site_health_tests' );

/**
 * @param string $label
 * @param string $status 'good' | 'recommended' | 'critical'.
 * @param string $description
 * @return array
 */
function legible_health_result( $label, $status, $description ) {
	$badge_color = 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'blue' );

	return array(
		'label'       => $label,
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Legible', 'legible' ),
			'color' => $badge_color,
		),
		'description' => '<p>' . esc_html( $description ) . '</p>',
		'actions'     => sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=legible-settings' ) ),
			esc_html__( 'Go to Legible settings', 'legible' )
		),
		'test'        => 'legible_ai_provider',
	);
}

function legible_test_ai_provider() {
	if ( ! legible_ai_is_configured() ) {
		return legible_health_result(
			__( 'Legible has no AI provider configured', 'legible' ),
			'recommended',
			__( 'The rule-based accessibility checks work without this, but AI-suggested fixes (like alt text) need an API key for one provider.', 'legible' )
		);
	}

	$provider = legible_get_current_provider();
	$model    = legible_get_model_for_provider( $provider );

	return legible_health_result(
		sprintf(
			/* translators: %s: AI provider name, e.g. "Gemini" */
			__( 'Legible is configured to use %s', 'legible' ),
			legible_ai_provider_label( $provider )
		),
		'good',
		sprintf(
			/* translators: 1: AI provider name, 2: model id */
			__( 'Provider: %1$s, model: %2$s. This does not confirm the key actually works - use "Test connection" on the Legible settings page for that.', 'legible' ),
			legible_ai_provider_label( $provider ),
			$model
		)
	);
}
