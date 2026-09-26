<?php
/**
 * Plugin Name:       AF5 Core
 * Description:       Custom core functionality for the AF5 project, including a GeoDirectory mood-based listing search shortcode.
 * Version:           1.0.2
 * Requires PHP:      7.4
 * Author:            BuddyDevelopers
 * Text Domain:       af5-core
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'AF5_CORE_VERSION', '1.0.2' );
define( 'AF5_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'AF5_CORE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstrap the plugin once all plugins are loaded so we can safely check
 * for GeoDirectory before wiring up anything that depends on it.
 */
function af5_core_init() {
	load_plugin_textdomain( 'af5-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// GeoDirectory is a hard dependency for the mood search feature.
	if ( ! function_exists( 'geodir_get_option' ) ) {
		add_action( 'admin_notices', 'af5_core_missing_geodirectory_notice' );
		return;
	}

	require_once AF5_CORE_PATH . 'includes/class-af5-core-mood-search.php';

	AF5_Core_Mood_Search::instance();
}
add_action( 'plugins_loaded', 'af5_core_init' );

/**
 * Admin notice shown when GeoDirectory is not active.
 */
function af5_core_missing_geodirectory_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'AF5 Core requires GeoDirectory to be installed and active.', 'af5-core' )
	);
}
