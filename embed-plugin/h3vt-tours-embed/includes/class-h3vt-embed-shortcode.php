<?php
/**
 * Shortcode handler for embedding tours from a remote H3VT Tours host.
 *
 * @package H3VT_Tours_Embed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the [h3vt_tour] shortcode and handles API fetching with transient caching.
 */
class H3VT_Embed_Shortcode {

	/**
	 * Track whether CSS has been enqueued for this page load.
	 *
	 * @var bool
	 */
	private $css_enqueued = false;

	/**
	 * Track whether JS has been enqueued for this page load.
	 *
	 * @var bool
	 */
	private $js_enqueued = false;

	/**
	 * Track theme-specific CSS handles already enqueued.
	 *
	 * @var array
	 */
	private $theme_css_enqueued = array();

	/**
	 * Track theme-specific JS handles already enqueued.
	 *
	 * @var array
	 */
	private $theme_js_enqueued = array();

	/**
	 * Constructor — registers the shortcode.
	 */
	public function __construct() {
		add_shortcode( 'h3vt_tour', array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( array(
			'id'     => 0,
			'slug'   => '',
			'height' => '100vh',
			'host'   => '',
		), $atts, 'h3vt_tour' );

		$id   = absint( $atts['id'] );
		$slug = sanitize_title( $atts['slug'] );

		if ( ! $id && empty( $slug ) ) {
			return $this->error_html( __( 'Please provide a tour ID or slug.', 'h3vt-tours-embed' ) );
		}

		// Determine host URL: shortcode attribute → global setting → error.
		$host = $this->get_host_url( $atts['host'] );
		if ( ! $host ) {
			if ( current_user_can( 'manage_options' ) ) {
				return $this->error_html( __( 'H3VT Tours: Please configure the Tour Host URL in Settings → H3VT Tours.', 'h3vt-tours-embed' ) );
			}
			return '';
		}

		// Build the API URL.
		if ( $id ) {
			$api_url = $host . '/wp-json/h3vt-tours/v1/tour/' . $id . '?format=html';
		} else {
			$api_url = $host . '/wp-json/h3vt-tours/v1/tour/by-slug/' . $slug . '?format=html';
		}

		// Fetch (cached).
		$data = $this->fetch_tour( $api_url );

		if ( is_wp_error( $data ) ) {
			$message = 'h3vt_tour_not_found' === $data->get_error_code()
				? __( 'Tour not found.', 'h3vt-tours-embed' )
				: __( 'Tour temporarily unavailable.', 'h3vt-tours-embed' );
			return $this->error_html( $message );
		}

		// Enqueue assets from the host site.
		$this->enqueue_assets( $data );

		$height = esc_attr( $atts['height'] );
		return sprintf(
			'<div class="h3vt-tour-embed" style="height:%s;position:relative;overflow:hidden">%s</div>',
			$height,
			$data['html']
		);
	}

	/**
	 * Resolve the host URL from shortcode attribute or global setting.
	 *
	 * @param string $override Optional per-shortcode override.
	 * @return string|false Host URL or false if not configured.
	 */
	private function get_host_url( $override = '' ) {
		if ( ! empty( $override ) ) {
			return esc_url_raw( untrailingslashit( trim( $override ) ) );
		}

		$opts = get_option( H3VT_Embed_Settings::OPTION, array() );
		return ! empty( $opts['host_url'] ) ? $opts['host_url'] : false;
	}

	/**
	 * Fetch tour data from the API with transient caching.
	 *
	 * @param string $api_url Full API endpoint URL.
	 * @return array|WP_Error Tour data array or error.
	 */
	private function fetch_tour( $api_url ) {
		$opts     = get_option( H3VT_Embed_Settings::OPTION, array() );
		$ttl      = isset( $opts['cache_duration'] ) ? (int) $opts['cache_duration'] : 86400;
		$cache_key = 'h3vt_embed_' . md5( $api_url );

		// Check cache.
		if ( $ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = wp_remote_get( $api_url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			// Serve stale cache if available.
			$stale = get_transient( $cache_key );
			if ( false !== $stale ) {
				return $stale;
			}
			error_log( 'H3VT Tours Embed: API request failed — ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			return new WP_Error( 'h3vt_tour_not_found', 'Tour not found.' );
		}

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['html'] ) ) {
			$stale = get_transient( $cache_key );
			if ( false !== $stale ) {
				return $stale;
			}
			error_log( 'H3VT Tours Embed: Unexpected API response (HTTP ' . $code . ').' );
			return new WP_Error( 'h3vt_api_error', 'Unexpected API response.' );
		}

		// Cache the successful response.
		if ( $ttl > 0 ) {
			set_transient( $cache_key, $body, $ttl );
		}

		return $body;
	}

	/**
	 * Enqueue the tour CSS and JS from the host site (once per page load).
	 *
	 * @param array $data Tour data with css_url and js_url keys.
	 */
	private function enqueue_assets( $data ) {
		if ( ! $this->css_enqueued && ! empty( $data['css_url'] ) ) {
			wp_enqueue_style( 'h3vt-tours-embed', $data['css_url'], array(), H3VT_EMBED_VERSION );
			$this->css_enqueued = true;
		}

		if ( ! $this->js_enqueued && ! empty( $data['js_url'] ) ) {
			wp_enqueue_script( 'h3vt-tours-embed', $data['js_url'], array(), H3VT_EMBED_VERSION, true );
			$this->js_enqueued = true;
		}

		if ( ! empty( $data['theme_css_url'] ) ) {
			$handle = 'h3vt-tours-embed-theme-' . md5( $data['theme_css_url'] );
			if ( ! isset( $this->theme_css_enqueued[ $handle ] ) ) {
				wp_enqueue_style( $handle, $data['theme_css_url'], array( 'h3vt-tours-embed' ), H3VT_EMBED_VERSION );
				$this->theme_css_enqueued[ $handle ] = true;
			}
		}

		if ( ! empty( $data['theme_js_url'] ) ) {
			$handle = 'h3vt-tours-embed-theme-' . md5( $data['theme_js_url'] );
			if ( ! isset( $this->theme_js_enqueued[ $handle ] ) ) {
				wp_enqueue_script( $handle, $data['theme_js_url'], array( 'h3vt-tours-embed' ), H3VT_EMBED_VERSION, true );
				$this->theme_js_enqueued[ $handle ] = true;
			}
		}
	}

	/**
	 * Wrap an error message in a styled container.
	 *
	 * @param string $message Error message to display.
	 * @return string HTML.
	 */
	private function error_html( $message ) {
		return '<div class="h3vt-tour-embed-error" style="padding:20px;text-align:center;color:#666;font-style:italic">'
			. esc_html( $message )
			. '</div>';
	}
}
