<?php
/**
 * Site Health check for the AI provider (USHER_V1_SPEC.md §1: "Site
 * Health-перевірка з'єднання з AI-провайдером"). Deliberately passive -
 * checks that a provider/key/model are configured, does not make a live
 * API call. A live call costs the user real money on their own BYO key;
 * Site Health runs on every visit to that admin screen and on a
 * recurring WP-Cron schedule, so making one automatically here would spend
 * the user's API budget without an explicit action from them. The
 * "Test connection" button on the Usher settings page is the explicit,
 * one-shot equivalent (see includes/settings-page.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function usher_register_site_health_tests( $tests ) {
	$tests['direct']['usher_ai_provider'] = array(
		'label' => __( 'Usher AI provider', 'usher' ),
		'test'  => 'usher_test_ai_provider',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'usher_register_site_health_tests' );

/**
 * @param string $label
 * @param string $status 'good' | 'recommended' | 'critical'.
 * @param string $description
 * @return array
 */
function usher_health_result( $label, $status, $description ) {
	$badge_color = 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'blue' );

	return array(
		'label'       => $label,
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Usher', 'usher' ),
			'color' => $badge_color,
		),
		'description' => '<p>' . esc_html( $description ) . '</p>',
		'actions'     => sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=usher-settings' ) ),
			esc_html__( 'Go to Usher settings', 'usher' )
		),
		'test'        => 'usher_ai_provider',
	);
}

function usher_test_ai_provider() {
	if ( ! usher_ai_is_configured() ) {
		return usher_health_result(
			__( 'Usher has no AI provider configured', 'usher' ),
			'recommended',
			__( 'The rule-based accessibility checks work without this, but AI-suggested fixes (like alt text) need an API key for one provider.', 'usher' )
		);
	}

	$provider = usher_get_current_provider();
	$model    = usher_get_model_for_provider( $provider );

	return usher_health_result(
		sprintf(
			/* translators: %s: AI provider name, e.g. "Gemini" */
			__( 'Usher is configured to use %s', 'usher' ),
			usher_ai_provider_label( $provider )
		),
		'good',
		sprintf(
			/* translators: 1: AI provider name, 2: model id */
			__( 'Provider: %1$s, model: %2$s. This does not confirm the key actually works - use "Test connection" on the Usher settings page for that.', 'usher' ),
			usher_ai_provider_label( $provider ),
			$model
		)
	);
}
