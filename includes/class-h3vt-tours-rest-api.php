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
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
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
			'/exit-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit_exit_intent_lead' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'tour_id' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
					'name'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'phone'   => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'company_website' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
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
	 * Handle an exit-intent lead submission — email it to the marketing team.
	 *
	 * The notification address comes from the tour's template
	 * (exit_intent_notify_email), falling back to the site admin email.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_exit_intent_lead( WP_REST_Request $request ) {
		// Honeypot filled in — pretend success so bots learn nothing.
		if ( '' !== $request->get_param( 'company_website' ) ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		$tour_id = $request->get_param( 'tour_id' );
		$name    = $request->get_param( 'name' );
		$email   = $request->get_param( 'email' );
		$phone   = $request->get_param( 'phone' );

		$post = get_post( $tour_id );
		if ( ! $post || 'h3vt_tour' !== $post->post_type ) {
			return new WP_Error(
				'h3vt_tour_not_found',
				__( 'Tour not found.', 'h3vt-tours' ),
				array( 'status' => 404 )
			);
		}

		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error(
				'h3vt_invalid_lead',
				__( 'A name and a valid email address are required.', 'h3vt-tours' ),
				array( 'status' => 400 )
			);
		}

		$notify_email = '';
		$template_id  = get_field( 'tour_template', $tour_id );
		if ( $template_id ) {
			$notify_email = get_field( 'exit_intent_notify_email', absint( $template_id ) ) ?: '';
		}
		if ( ! is_email( $notify_email ) ) {
			$notify_email = get_option( 'admin_email' );
		}

		$tour_title = get_the_title( $tour_id );
		$subject    = sprintf(
			/* translators: 1: lead name, 2: tour title. */
			__( 'New tour lead: %1$s — %2$s', 'h3vt-tours' ),
			$name,
			$tour_title
		);

		$lines = array(
			__( 'A visitor asked for more information before leaving a virtual tour.', 'h3vt-tours' ),
			'',
			sprintf( __( 'Tour: %s', 'h3vt-tours' ), $tour_title ),
			sprintf( __( 'Tour URL: %s', 'h3vt-tours' ), get_permalink( $tour_id ) ),
			'',
			sprintf( __( 'Name: %s', 'h3vt-tours' ), $name ),
			sprintf( __( 'Email: %s', 'h3vt-tours' ), $email ),
			sprintf( __( 'Phone: %s', 'h3vt-tours' ), $phone ? $phone : __( '(not provided)', 'h3vt-tours' ) ),
			'',
			sprintf( __( 'Submitted: %s', 'h3vt-tours' ), current_time( 'mysql' ) ),
		);

		$headers = array( 'Reply-To: ' . $name . ' <' . $email . '>' );

		$sent = wp_mail( $notify_email, $subject, implode( "\n", $lines ), $headers );

		if ( ! $sent ) {
			return new WP_Error(
				'h3vt_lead_not_sent',
				__( 'The lead could not be delivered. Please try again.', 'h3vt-tours' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
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
			$data  = H3VT_Tours_Renderer::get_tour_data( $id );
			$theme = $data['settings']['theme'];

			return new WP_REST_Response(
				array_merge(
					array(
						'html'    => H3VT_Tours_Renderer::render( $id, 'embed' ),
						'css_url' => H3VT_TOURS_URL . 'assets/css/h3vt-tours.css',
						'js_url'  => H3VT_TOURS_URL . 'assets/js/h3vt-tours.js',
					),
					H3VT_Tours_Theme_Loader::get_theme_asset_urls( $theme )
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
