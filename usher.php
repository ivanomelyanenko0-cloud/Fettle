<?php
/**
 * Plugin Name:       Usher
 * Plugin URI:        https://cognitolab.net/products/usher
 * Description:       Accessibility audit and one-at-a-time AI-assisted remediation: real WCAG 2.1 AA checks, not another overlay widget.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            CognitoLab
 * Author URI:        https://cognitolab.net
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       usher
 * Domain Path:       /languages
 *
 * "Usher" is a working name and may still change before the first public
 * release - every internal identifier lives behind the USHER_/usher_
 * prefix below so a rename stays a mechanical find/replace instead of an
 * architecture change.
 *
 * This is the full Free 1.0.0 scope - the rule-based audit engine
 * (contrast, heading order, form labels, link text, theme landmarks) plus
 * the one-at-a-time AI-suggested alt-text/contrast/link-text fix flow,
 * always previewed and explicitly confirmed before anything is applied.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'USHER_VERSION', '1.0.0' );
define( 'USHER_PLUGIN_FILE', __FILE__ );
define( 'USHER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'USHER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once USHER_PLUGIN_DIR . 'includes/forbidden-phrases.php';
require_once USHER_PLUGIN_DIR . 'includes/dismissals.php';
require_once USHER_PLUGIN_DIR . 'includes/ai/settings.php';
require_once USHER_PLUGIN_DIR . 'includes/ai/models.php';
require_once USHER_PLUGIN_DIR . 'includes/ai/image-helpers.php';
require_once USHER_PLUGIN_DIR . 'includes/ai/dispatch.php';
require_once USHER_PLUGIN_DIR . 'includes/ai/alt-text-fix.php';
require_once USHER_PLUGIN_DIR . 'includes/ai/contrast-fix.php';
require_once USHER_PLUGIN_DIR . 'includes/ai/link-text-fix.php';
require_once USHER_PLUGIN_DIR . 'includes/site-health.php';
require_once USHER_PLUGIN_DIR . 'includes/checks/contrast.php';
require_once USHER_PLUGIN_DIR . 'includes/checks/heading-order.php';
require_once USHER_PLUGIN_DIR . 'includes/checks/form-labels.php';
require_once USHER_PLUGIN_DIR . 'includes/checks/link-text.php';
require_once USHER_PLUGIN_DIR . 'includes/checks/alt-text.php';
require_once USHER_PLUGIN_DIR . 'includes/checks/aria-landmarks.php';
require_once USHER_PLUGIN_DIR . 'includes/scan-engine.php';
require_once USHER_PLUGIN_DIR . 'includes/admin-page.php';
require_once USHER_PLUGIN_DIR . 'includes/settings-page.php';
require_once USHER_PLUGIN_DIR . 'includes/cli.php';

/**
 * No load_plugin_textdomain() call: discouraged since WP 4.6 for plugins
 * hosted on wordpress.org - core auto-loads translations for wp.org-hosted
 * plugins using the plugin slug, no manual loading needed.
 */
