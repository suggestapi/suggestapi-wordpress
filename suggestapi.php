<?php
/**
 * Plugin Name: SuggestAPI
 * Description: WordPress + WooCommerce connector for SuggestAPI. Search, background catalog sync, and agent discovery.
 * Version: 0.2.1
 * Requires PHP: 8.2.35
 * Requires at least: 6.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: suggestapi
 */

/**
 * True when $version includes the php2shell fixes (CVE-2026-109618, CVE-2026-109619).
 * WordPress "Requires PHP" is a single minimum, so 8.2.35 still lets vulnerable
 * 8.3.0–8.3.35, 8.4.0–8.4.26, and 8.5.0–8.5.11 through. This is the real gate.
 *
 * ponytail: 8.6+ is treated as patched because those releases are cut after the
 * fix. If a later minor ships without it, add a floor to $floors.
 */
function suggestapi_php_is_patched( $version = null ) {
	$version = null === $version ? PHP_VERSION : (string) $version;
	$floors  = array(
		'8.2' => '8.2.35',
		'8.3' => '8.3.36',
		'8.4' => '8.4.27',
		'8.5' => '8.5.12',
	);
	foreach ( $floors as $minor => $floor ) {
		$parts = explode( '.', $minor );
		$next  = $parts[0] . '.' . ( (int) $parts[1] + 1 ) . '.0';
		if ( version_compare( $version, $minor . '.0', '>=' ) && version_compare( $version, $next, '<' ) ) {
			return version_compare( $version, $floor, '>=' );
		}
	}
	return version_compare( $version, '8.6.0', '>=' );
}

function suggestapi_php_runtime_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>SuggestAPI</strong> is stopped. PHP '
		. esc_html( PHP_VERSION )
		. ' is missing the php2shell fixes (CVE-2026-109618, CVE-2026-109619). Upgrade to PHP 8.2.35, 8.3.36, 8.4.27, or 8.5.12, or any later release in that line.</p></div>';
}

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! suggestapi_php_is_patched() ) {
	add_action( 'admin_notices', 'suggestapi_php_runtime_notice' );
	return;
}

define( 'SUGGESTAPI_DIR', plugin_dir_path( __FILE__ ) );
define( 'SUGGESTAPI_VERSION', '0.2.1' );
require_once SUGGESTAPI_DIR . 'includes/class-suggestapi.php';
require_once SUGGESTAPI_DIR . 'includes/class-sync.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SUGGESTAPI_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'suggestapi', 'SuggestAPI_CLI' );
}
add_action( 'plugins_loaded', array( 'SuggestAPI_Connector', 'init' ) );
register_activation_hook( __FILE__, array( 'SuggestAPI_Connector', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SuggestAPI_Connector', 'deactivate' ) );
