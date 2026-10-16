<?php
/**
 * Client analytics page: the [tour_analytics_display] shortcode.
 *
 * Shows a logged-in client the numbers for the tours assigned to them, for
 * all tours together or one at a time, over a chosen date range. The
 * shortcode name is the one h3-tour-management used, so the existing
 * /tour-analytics/ page keeps working without an edit.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the client analytics shortcode.
 */
class H3VT_Tours_Client_Page {

	const SHORTCODE = 'tour_analytics_display';

	/**
	 * Date range presets: request value => days.
	 */
	const RANGES = array(
		'7days'   => 7,
		'30days'  => 30,
		'90days'  => 90,
		'365days' => 365,
	);

	public function __construct() {
		// After h3tm's plugins_loaded registration, so its shortcode wins while it is active.
		add_action( 'init', array( $this, 'register' ), 20 );
	}

	public function register() {
		if ( H3VT_Tours_Client_Analytics::is_enabled() && ! shortcode_exists( self::SHORTCODE ) ) {
			add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		}
	}

	/**
	 * Shortcode output.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . sprintf(
				/* translators: %s: login URL */
				wp_kses_post( __( 'Please <a href="%s">log in</a> to see your tour analytics.', 'h3vt-tours' ) ),
				esc_url( wp_login_url( get_permalink() ) )
			) . '</p>';
		}

		$tours = H3VT_Tours_Client_Analytics::get_user_tours( get_current_user_id() );
		if ( ! $tours ) {
			return '<p>' . esc_html__( 'No tours are assigned to your account yet.', 'h3vt-tours' ) . '</p>';
		}

		// Read-only filters on the viewer's own data, so no nonce is needed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_GET['tour'] ) ? sanitize_text_field( wp_unslash( $_GET['tour'] ) ) : 'all';
		$range    = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30days';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! array_key_exists( $range, self::RANGES ) ) {
			$range = '30days';
		}

		// Only tours assigned to this user can be selected.
		$current = null;
		foreach ( $tours as $tour ) {
			if ( $tour['key'] === $selected ) {
				$current = $tour;
			}
		}
		if ( ! $current ) {
			$selected = 'all';
		}

		$paths = array();
		foreach ( $current ? array( $current ) : $tours as $tour ) {
			$paths = array_merge( $paths, $tour['paths'] );
		}

		$end   = new DateTimeImmutable( current_time( 'Y-m-d' ) );
		$start = $end->modify( '-' . ( self::RANGES[ $range ] - 1 ) . ' days' );

		$error = null;
		try {
			$data = H3VT_Tours_Client_Analytics::get_dashboard_stats( $paths, $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
		} catch ( Exception $e ) {
			error_log( 'H3VT Tours client analytics page: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$error = current_user_can( 'manage_options' )
				? $e->getMessage()
				: __( 'Analytics are unavailable right now. Please try again later.', 'h3vt-tours' );
			$data  = null;
		}

		$title = $current ? $current['title'] : __( 'All Tours', 'h3vt-tours' );

		ob_start();
		include H3VT_TOURS_PATH . 'templates/client-analytics-page.php';
		return ob_get_clean();
	}

	/**
	 * Pie chart colours, in order.
	 *
	 * @param int $index Slice index.
	 * @return string
	 */
	public static function chart_color( $index ) {
		$colors = array( '#00bcd4', '#4caf50', '#ff9800', '#9c27b0', '#f44336', '#2196f3', '#795548', '#607d8b' );
		return $colors[ $index % count( $colors ) ];
	}
}
