<?php
/**
 * Custom Post Type registration.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the h3vt_tour post type.
 */
class H3VT_Tours_CPT {

	/**
	 * Constructor — hooks the CPT registration to init.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'register_template_cpt' ) );
	}

	/**
	 * Register the h3vt_tour post type.
	 */
	public function register() {
		$labels = array(
			'name'                  => _x( 'Tours', 'Post type general name', 'h3vt-tours' ),
			'singular_name'         => _x( 'Tour', 'Post type singular name', 'h3vt-tours' ),
			'add_new'               => __( 'Add New Tour', 'h3vt-tours' ),
			'add_new_item'          => __( 'Add New Tour', 'h3vt-tours' ),
			'edit_item'             => __( 'Edit Tour', 'h3vt-tours' ),
			'new_item'              => __( 'New Tour', 'h3vt-tours' ),
			'view_item'             => __( 'View Tour', 'h3vt-tours' ),
			'view_items'            => __( 'View Tours', 'h3vt-tours' ),
			'search_items'          => __( 'Search Tours', 'h3vt-tours' ),
			'not_found'             => __( 'No tours found.', 'h3vt-tours' ),
			'not_found_in_trash'    => __( 'No tours found in Trash.', 'h3vt-tours' ),
			'all_items'             => __( 'All Tours', 'h3vt-tours' ),
			'archives'              => __( 'Tour Archives', 'h3vt-tours' ),
			'attributes'            => __( 'Tour Attributes', 'h3vt-tours' ),
			'insert_into_item'      => __( 'Insert into tour', 'h3vt-tours' ),
			'uploaded_to_this_item' => __( 'Uploaded to this tour', 'h3vt-tours' ),
			'filter_items_list'     => __( 'Filter tours list', 'h3vt-tours' ),
			'items_list_navigation' => __( 'Tours list navigation', 'h3vt-tours' ),
			'items_list'            => __( 'Tours list', 'h3vt-tours' ),
			'menu_name'             => _x( 'H3VT Tours', 'Admin menu text', 'h3vt-tours' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'has_archive'        => false,
			'show_in_rest'       => true,
			'rewrite'            => array( 'slug' => 'tour' ),
			'supports'           => array( 'title', 'thumbnail' ),
			'menu_icon'          => 'dashicons-format-gallery',
			'capability_type'    => 'post',
		);

		register_post_type( 'h3vt_tour', $args );
	}

	/**
	 * Register the h3vt_tour_template post type (admin-only).
	 */
	public function register_template_cpt() {
		$labels = array(
			'name'               => _x( 'Templates', 'Post type general name', 'h3vt-tours' ),
			'singular_name'      => _x( 'Template', 'Post type singular name', 'h3vt-tours' ),
			'add_new'            => __( 'Add New Template', 'h3vt-tours' ),
			'add_new_item'       => __( 'Add New Template', 'h3vt-tours' ),
			'edit_item'          => __( 'Edit Template', 'h3vt-tours' ),
			'new_item'           => __( 'New Template', 'h3vt-tours' ),
			'view_item'          => __( 'View Template', 'h3vt-tours' ),
			'search_items'       => __( 'Search Templates', 'h3vt-tours' ),
			'not_found'          => __( 'No templates found.', 'h3vt-tours' ),
			'not_found_in_trash' => __( 'No templates found in Trash.', 'h3vt-tours' ),
			'all_items'          => __( 'Templates', 'h3vt-tours' ),
			'menu_name'          => _x( 'Templates', 'Admin menu text', 'h3vt-tours' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'edit.php?post_type=h3vt_tour',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'rewrite'         => false,
		);

		register_post_type( 'h3vt_tour_template', $args );
	}
}
