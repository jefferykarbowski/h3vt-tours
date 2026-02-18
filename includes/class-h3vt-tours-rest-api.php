<?php
/**
 * REST API endpoint for tour data.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a public REST endpoint at /wp-json/h3vt-tours/v1/tour/{id}.
 */
class H3VT_Tours_REST_API {

	/**
	 * Constructor — hooks route registration.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			'h3vt-tours/v1',
			'/tour/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_tour' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'     => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
					'format' => array(
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'json', 'html' ), true );
						},
						'default'           => 'json',
					),
				),
			)
		);
	}

	/**
	 * Handle the GET request for a single tour.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tour( WP_REST_Request $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'h3vt_tour' !== $post->post_type ) {
			return new WP_Error(
				'h3vt_tour_not_found',
				__( 'Tour not found.', 'h3vt-tours' ),
				array( 'status' => 404 )
			);
		}

		if ( 'html' === $request->get_param( 'format' ) ) {
			return new WP_REST_Response(
				array(
					'html'    => H3VT_Tours_Renderer::render( $id, 'embed' ),
					'css_url' => H3VT_TOURS_URL . 'assets/css/h3vt-tours.css',
					'js_url'  => H3VT_TOURS_URL . 'assets/js/h3vt-tours.js',
				),
				200
			);
		}

		return new WP_REST_Response(
			H3VT_Tours_Renderer::get_tour_data( $id ),
			200
		);
	}
}
