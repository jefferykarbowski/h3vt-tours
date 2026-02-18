<?php
/**
 * Shortcode for embedding a tour inside any page or post.
 *
 * Usage: [h3vt_tour id="123" height="100vh"]
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the [h3vt_tour] shortcode.
 */
class H3VT_Tours_Shortcode {

	/**
	 * Constructor — hooks shortcode registration to init.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'h3vt_tour', array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode output.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'     => 0,
				'height' => '100vh',
			),
			$atts,
			'h3vt_tour'
		);

		$id = absint( $atts['id'] );

		if ( ! $id || ! get_post( $id ) ) {
			return '';
		}

		if ( get_post_type( $id ) !== 'h3vt_tour' ) {
			return '';
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

		$height = esc_attr( $atts['height'] );

		return sprintf(
			'<div class="h3vt-tour-shortcode" style="height:%s;position:relative;overflow:hidden">%s</div>',
			$height,
			H3VT_Tours_Renderer::render( $id, 'shortcode' )
		);
	}
}
