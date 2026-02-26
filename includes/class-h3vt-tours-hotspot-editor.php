<?php
/**
 * Admin hotspot editor — enqueues the visual click-to-place editor.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the hotspot editor JS and CSS on tour edit screens,
 * and localizes floorplan image data for the editor to consume.
 */
class H3VT_Tours_Hotspot_Editor {

	/**
	 * Constructor — hooks asset enqueue.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue editor assets on h3vt_tour edit screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'h3vt_tour' !== $screen->post_type ) {
			return;
		}
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$floorplans = array();
		if ( $post_id ) {
			$raw = get_field( 'floorplans', $post_id );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $i => $fp ) {
					$img_url = '';
					if ( ! empty( $fp['floorplan_image'] ) && is_array( $fp['floorplan_image'] ) ) {
						$img_url = $fp['floorplan_image']['url'];
					}
					$floorplans[] = array(
						'index' => $i,
						'label' => isset( $fp['floorplan_label'] ) ? $fp['floorplan_label'] : '',
						'image' => $img_url,
					);
				}
			}
		}

		wp_enqueue_style(
			'h3vt-hotspot-editor',
			H3VT_TOURS_URL . 'assets/admin/css/h3vt-hotspot-editor.css',
			array(),
			H3VT_TOURS_VERSION
		);

		wp_enqueue_script(
			'h3vt-hotspot-editor',
			H3VT_TOURS_URL . 'assets/admin/js/hotspot-editor.js',
			array( 'acf-input' ),
			H3VT_TOURS_VERSION,
			true
		);

		wp_localize_script( 'h3vt-hotspot-editor', 'h3vtHotspotData', array(
			'floorplans' => $floorplans,
		) );
	}
}
