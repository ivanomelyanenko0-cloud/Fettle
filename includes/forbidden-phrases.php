<?php
/**
 * The list of phrases Fettle never uses in any user-facing text - UI,
 * readme, a generated report, email (shared between Free and Pro). This is
 * the exact class of claim the FTC's 2023 order against accessiBe forbids
 * that company from making for 20 years ($1M fine) - "fully accessible",
 * "WCAG compliant", "guaranteed" all promise a legal conclusion this
 * plugin (or any automated scanner) cannot actually deliver.
 *
 * This file is the list itself, plus a small helper to check a string
 * against it. The enforcement tool that runs this against every
 * translatable string in the plugin before a release lives in
 * includes/cli.php ("wp fettle check-phrases").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return string[] Lowercase phrases. Filterable so a future Pro-only
 *                   surface (a VPAT-style report, an email alert) can add
 *                   its own additional bans without touching this file.
 */
function fettle_forbidden_phrases() {
	$phrases = array(
		'fully accessible',
		'wcag compliant',
		'ada compliant',
		'guaranteed',
		'guarantee compliance',
		'100% accessible',
		'accessible to all',
		'certified accessible',
		'certified compliant',
	);

	return apply_filters( 'fettle_forbidden_phrases', $phrases );
}

/**
 * @param string $text Text to check (any case).
 * @return string[] The forbidden phrases found in $text, if any.
 */
function fettle_find_forbidden_phrases( $text ) {
	$text  = strtolower( (string) $text );
	$found = array();

	foreach ( fettle_forbidden_phrases() as $phrase ) {
		if ( false !== strpos( $text, $phrase ) ) {
			$found[] = $phrase;
		}
	}

	return $found;
}
