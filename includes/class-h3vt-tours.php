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
		require_once $dir . 'class-h3vt-tours-shortcode.php';
		require_once $dir . 'class-h3vt-tours-rest-api.php';
		require_once $dir . 'class-h3vt-tours-embed.php';
		require_once $dir . 'class-h3vt-tours-hotspot-editor.php';
		require_once $dir . 'class-h3vt-tours-embed-metabox.php';
		require_once $dir . 'class-h3vt-tours-s3.php';
		require_once $dir . 'class-h3vt-tours-s3-field-filter.php';
		require_once $dir . 'class-h3vt-tours-settings.php';
		require_once $dir . 'class-h3vt-tours-s3-ajax.php';
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
		new H3VT_Tours_S3_Field_Filter();

		if ( is_admin() ) {
			new H3VT_Tours_Hotspot_Editor();
			new H3VT_Tours_Embed_Metabox();
			new H3VT_Tours_Settings();
			new H3VT_Tours_S3_Ajax();
		}
	}
}
