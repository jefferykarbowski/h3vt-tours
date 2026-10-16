<?php
/**
 * Main plugin orchestrator.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton class that bootstraps every plugin component.
 */
class H3VT_Tours {

	/**
	 * Single instance.
	 *
	 * @var H3VT_Tours|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return H3VT_Tours
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — loads files and initialises components.
	 */
	private function __construct() {
		$this->includes();
		$this->init();
	}

	/**
	 * Require every class file.
	 */
	private function includes() {
		$dir = H3VT_TOURS_PATH . 'includes/';

		require_once $dir . 'class-h3vt-tours-cpt.php';
		require_once $dir . 'class-h3vt-tours-acf.php';
		require_once $dir . 'class-h3vt-tours-template.php';
		require_once $dir . 'class-h3vt-tours-renderer.php';
		require_once $dir . 'class-h3vt-tours-theme-loader.php';
		require_once $dir . 'class-h3vt-tours-shortcode.php';
		require_once $dir . 'class-h3vt-tours-rest-api.php';
		require_once $dir . 'class-h3vt-tours-embed.php';
		require_once $dir . 'class-h3vt-tours-hotspot-editor.php';
		require_once $dir . 'class-h3vt-tours-embed-metabox.php';
		require_once $dir . 'class-h3vt-tours-media-filter.php';
		require_once $dir . 'class-h3vt-tours-migration.php';
		require_once $dir . 'class-h3vt-tours-analytics.php';
		require_once $dir . 'class-h3vt-tours-ga-client.php';
		require_once $dir . 'class-h3vt-tours-stats.php';
		require_once $dir . 'class-h3vt-tours-client-analytics.php';
		require_once $dir . 'class-h3vt-tours-client-reports.php';
		require_once $dir . 'class-h3vt-tours-client-page.php';
		require_once $dir . 'class-h3vt-tours-user-fields.php';
		require_once $dir . 'class-h3vt-tours-legacy-tours.php';
		require_once $dir . 'class-h3vt-tours-3dvista-converter.php';
	}

	/**
	 * Instantiate each component — they hook themselves in their constructors.
	 */
	private function init() {
		new H3VT_Tours_CPT();
		new H3VT_Tours_ACF();
		new H3VT_Tours_Template();
		new H3VT_Tours_Shortcode();
		new H3VT_Tours_REST_API();
		new H3VT_Tours_Embed();
		new H3VT_Tours_Media_Filter();
		new H3VT_Tours_Migration();
		new H3VT_Tours_Analytics();
		new H3VT_Tours_Client_Reports();
		new H3VT_Tours_Client_Page();
		new H3VT_Tours_Legacy_Tours();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once H3VT_TOURS_PATH . 'includes/class-h3vt-tours-client-analytics-cli.php';
			WP_CLI::add_command( 'h3vt-tours client-analytics', 'H3VT_Tours_Client_Analytics_CLI' );
			require_once H3VT_TOURS_PATH . 'includes/class-h3vt-tours-legacy-tours-cli.php';
			WP_CLI::add_command( 'h3vt-tours legacy-tours', 'H3VT_Tours_Legacy_Tours_CLI' );
		}

		if ( is_admin() ) {
			new H3VT_Tours_Hotspot_Editor();
			new H3VT_Tours_Embed_Metabox();
			new H3VT_Tours_3DVista_Converter();
			new H3VT_Tours_Stats();
			new H3VT_Tours_User_Fields();
		}
	}
}
