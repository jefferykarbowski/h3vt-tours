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
		add_action( 'rest_api_init', array( $this, 'add_cors_headers' ), 15 );
	}

	/**
	 * Add CORS headers for cross-origin embedding.
	 */
	public function add_cors_headers() {
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		add_filter( 'rest_pre_serve_request', function( $value ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
			return $value;
		});
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		$format_arg = array(
			'validate_callback' => function ( $param ) {
				return in_array( $param, array( 'json', 'html' ), true );
			},
			'default'           => 'json',
		);

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
					'format' => $format_arg,
				),
			)
		);

		register_rest_route(
			'h3vt-tours/v1',
			'/tour/by-slug/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_tour_by_slug' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slug'   => array(
						'sanitize_callback' => 'sanitize_title',
					),
					'format' => $format_arg,
				),
			)
		);
	}

	/**
	 * Handle the GET request for a tour looked up by slug.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tour_by_slug( WP_REST_Request $request ) {
		$slug = $request->get_param( 'slug' );
		$post = get_page_by_path( $slug, OBJECT, 'h3vt_tour' );

		if ( ! $post ) {
			return new WP_Error(
				'h3vt_tour_not_found',
				__( 'Tour not found.', 'h3vt-tours' ),
				array( 'status' => 404 )
			);
		}

		$request->set_param( 'id', $post->ID );
		return $this->get_tour( $request );
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
