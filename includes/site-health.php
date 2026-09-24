<?php
/**
 * Site Health check for the AI provider. Deliberately passive - checks
 * that a provider/key/model are configured, does not make a live
 * API call. A live call costs the user real money on their own BYO key;
 * Site Health runs on every visit to that admin screen and on a
 * recurring WP-Cron schedule, so making one automatically here would spend
 * the user's API budget without an explicit action from them. The
 * "Test connection" button on the Fettle settings page is the explicit,
 * one-shot equivalent (see includes/settings-page.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function fettle_register_site_health_tests( $tests ) {
	$tests['direct']['fettle_ai_provider'] = array(
		'label' => __( 'Fettle AI provider', 'fettle' ),
		'test'  => 'fettle_test_ai_provider',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'fettle_register_site_health_tests' );

/**
 * @param string $label
 * @param string $status 'good' | 'recommended' | 'critical'.
 * @param string $description
 * @return array
 */
function fettle_health_result( $label, $status, $description ) {
	$badge_color = 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'blue' );

	return array(
		'label'       => $label,
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Fettle', 'fettle' ),
			'color' => $badge_color,
		),
		'description' => '<p>' . esc_html( $description ) . '</p>',
		'actions'     => sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=fettle-settings' ) ),
			esc_html__( 'Go to Fettle settings', 'fettle' )
		),
		'test'        => 'fettle_ai_provider',
	);
}

function fettle_test_ai_provider() {
	if ( ! fettle_ai_is_configured() ) {
		return fettle_health_result(
			__( 'Fettle has no AI provider configured', 'fettle' ),
			'recommended',
			__( 'The rule-based accessibility checks work without this, but AI-suggested fixes (like alt text) need an API key for one provider.', 'fettle' )
		);
	}

	$provider = fettle_get_current_provider();
	$model    = fettle_get_model_for_provider( $provider );

	return fettle_health_result(
		sprintf(
			/* translators: %s: AI provider name, e.g. "Gemini" */
			__( 'Fettle is configured to use %s', 'fettle' ),
			fettle_ai_provider_label( $provider )
		),
		'good',
		sprintf(
			/* translators: 1: AI provider name, 2: model id */
			__( 'Provider: %1$s, model: %2$s. This does not confirm the key actually works - use "Test connection" on the Fettle settings page for that.', 'fettle' ),
			fettle_ai_provider_label( $provider ),
			$model
		)
	);
}
