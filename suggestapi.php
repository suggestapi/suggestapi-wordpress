<?php
/**
 * Plugin Name: SuggestAPI
 * Description: WordPress + WooCommerce connector for SuggestAPI. Search, background catalog sync, and agent discovery.
 * Version: 0.2.0
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: suggestapi
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'SUGGESTAPI_DIR', plugin_dir_path( __FILE__ ) );
define( 'SUGGESTAPI_VERSION', '0.2.0' );
require_once SUGGESTAPI_DIR . 'includes/class-suggestapi.php';
require_once SUGGESTAPI_DIR . 'includes/class-sync.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SUGGESTAPI_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'suggestapi', 'SuggestAPI_CLI' );
}
add_action( 'plugins_loaded', array( 'SuggestAPI_Connector', 'init' ) );
register_activation_hook( __FILE__, array( 'SuggestAPI_Connector', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SuggestAPI_Connector', 'deactivate' ) );
