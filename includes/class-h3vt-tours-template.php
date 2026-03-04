<?php
/**
 * Template loader and asset enqueue.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Overrides the single template for the h3vt_tour post type and enqueues assets.
 */
class H3VT_Tours_Template {

	/**
	 * Constructor — hooks template override and asset enqueue.
	 */
	public function __construct() {
		add_filter( 'template_include', array( $this, 'template_include' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Replace the theme template with the plugin's custom full-screen template.
	 *
	 * @param string $template Path to the current template.
	 * @return string
	 */
	public function template_include( $template ) {
		if ( is_singular( 'h3vt_tour' ) ) {
			return H3VT_TOURS_PATH . 'templates/single-h3vt_tour.php';
		}
		return $template;
	}

	/**
	 * Enqueue plugin CSS and JS on single tour pages.
	 */
	public function enqueue_assets() {
		if ( ! is_singular( 'h3vt_tour' ) ) {
			return;
		}

		wp_enqueue_style(
			'h3vt-tours',
			H3VT_TOURS_URL . 'assets/css/h3vt-tours.css',
			array(),
			H3VT_TOURS_VERSION
		);

		wp_enqueue_script(
			'h3vt-tours',
			H3VT_TOURS_URL . 'assets/js/h3vt-tours.js',
			array(),
			H3VT_TOURS_VERSION,
			true
		);

		$post_id     = get_the_ID();
		$template_id = get_field( 'tour_template', $post_id );
		$theme       = 'classic';
		if ( $template_id ) {
			$theme = get_field( 'theme', absint( $template_id ) ) ?: 'classic';
		}
		H3VT_Tours_Theme_Loader::enqueue_theme_assets( $theme );
	}
}
