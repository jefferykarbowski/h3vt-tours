<?php
/**
 * CORS headers for the REST API so tours can be embedded on external sites.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds CORS headers to H3VT Tours REST responses.
 */
class H3VT_Tours_Embed {

	/**
	 * Constructor — hooks CORS header filter.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'add_cors_headers' ) );
	}

	/**
	 * Register the filter that injects CORS headers on our namespace.
	 */
	public function add_cors_headers() {
		add_filter( 'rest_pre_serve_request', array( $this, 'send_cors_headers' ), 10, 4 );
	}

	/**
	 * Send CORS headers when the request targets the h3vt-tours namespace.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  Result to send to the client.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function send_cors_headers( $served, $result, $request, $server ) {
		$route = $request->get_route();

		if ( strpos( $route, '/h3vt-tours/' ) === 0 ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type' );
		}

		return $served;
	}
}
