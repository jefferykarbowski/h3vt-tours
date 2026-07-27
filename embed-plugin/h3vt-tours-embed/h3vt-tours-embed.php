<?php
/**
 * Plugin Name: H3VT Tours Embed
 * Plugin URI:  https://h3vt.com
 * Description: Embed virtual tours from an H3VT Tours host site using shortcodes.
 * Version:     1.6.9
 * Author:      H3VT
 * Author URI:  https://h3vt.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: h3vt-tours-embed
 * Requires PHP: 7.2
 *
 * @package H3VT_Tours_Embed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
	add_action( 'admin_notices', function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'H3VT Tours Embed requires PHP 7.2 or higher.', 'h3vt-tours-embed' )
		);
	} );
	return;
}

define( 'H3VT_EMBED_VERSION', '1.6.9' );
define( 'H3VT_EMBED_PATH', plugin_dir_path( __FILE__ ) );

require_once H3VT_EMBED_PATH . 'includes/class-h3vt-embed-settings.php';
require_once H3VT_EMBED_PATH . 'includes/class-h3vt-embed-shortcode.php';

add_action( 'plugins_loaded', function () {
	if ( is_admin() ) {
		new H3VT_Embed_Settings();
	}
	new H3VT_Embed_Shortcode();
} );

/**
 * Plugin Update Checker — uses GitHub releases with .zip assets.
 */
require H3VT_EMBED_PATH . 'plugin-update-checker/plugin-update-checker.php';

$h3vtEmbedUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/jefferykarbowski/h3vt-tours/',
	__FILE__,
	'h3vt-tours-embed'
);

// Use GitHub releases for update detection (requires tagged releases with .zip assets).
$h3vtEmbedUpdateChecker->getVcsApi()->enableReleaseAssets( '/^h3vt-tours-embed\.zip$/' );
