<?php
/**
 * Plugin Name: H3VT Tours
 * Plugin URI:  https://h3vt.com
 * Description: Virtual tour builder for senior living facilities.
 * Version:     1.12.0
 * Author:      H3VT
 * Author URI:  https://h3vt.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: h3vt-tours
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHP version check.
 */
if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
	add_action( 'admin_notices', function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'H3VT Tours requires PHP 7.2 or higher. Please upgrade your PHP version.', 'h3vt-tours' )
		);
	} );
	return;
}

/**
 * Plugin constants.
 */
define( 'H3VT_TOURS_VERSION', '1.12.0' );
define( 'H3VT_TOURS_PATH', plugin_dir_path( __FILE__ ) );
define( 'H3VT_TOURS_URL', plugin_dir_url( __FILE__ ) );
define( 'H3VT_TOURS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * ACF dependency check.
 */
add_action( 'plugins_loaded', function () {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		add_action( 'admin_notices', function () {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'H3VT Tours requires Advanced Custom Fields Pro to be installed and activated.', 'h3vt-tours' )
			);
		} );
	}
}, 10 );

/**
 * No "new user" notification emails, to the admin or to the new user.
 *
 * h3vt.com has always run with this pluggable stubbed out: it lived in the
 * theme's functions.php, then in the h3-tour-management plugin. It is carried
 * here so that retiring that plugin does not quietly switch the emails back on.
 * Whichever plugin loads first defines it; both versions do nothing.
 */
if ( ! function_exists( 'wp_new_user_notification' ) ) {
	function wp_new_user_notification( $user_id, $deprecated = null, $notify = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- pluggable.
	}
}

/**
 * Load the main plugin class.
 */
require_once H3VT_TOURS_PATH . 'includes/class-h3vt-tours.php';

/**
 * Initialize the plugin after ACF check.
 */
add_action( 'plugins_loaded', function () {
	H3VT_Tours::get_instance();
}, 20 );

/**
 * Activation hook — flush rewrite rules so the tour CPT permalink works.
 */
register_activation_hook( __FILE__, function () {
	require_once H3VT_TOURS_PATH . 'includes/class-h3vt-tours-cpt.php';
	$cpt = new H3VT_Tours_CPT();
	$cpt->register();
	$cpt->register_template_cpt();
	flush_rewrite_rules();
} );

/**
 * Deactivation hook — flush rewrite rules to clean up.
 */
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'h3vt_tours_client_report_cron' );
	flush_rewrite_rules();
} );

/**
 * Plugin Update Checker — uses GitHub releases with .zip assets.
 */
require H3VT_TOURS_PATH . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$h3vtUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/jefferykarbowski/h3vt-tours/',
	__FILE__,
	'h3vt-tours'
);

// Use GitHub releases for update detection (requires tagged releases with .zip assets).
$h3vtUpdateChecker->getVcsApi()->enableReleaseAssets( '/^h3vt-tours\.zip$/' );
