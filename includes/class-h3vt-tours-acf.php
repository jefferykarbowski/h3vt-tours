<?php
/**
 * ACF field group registration.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all Advanced Custom Fields groups for the h3vt_tour post type.
 */
class H3VT_Tours_ACF {

	/**
	 * Constructor — hooks field registration and dynamic field population.
	 */
	public function __construct() {
		add_action( 'acf/init', array( $this, 'register_fields' ) );
		add_filter( 'acf/load_field/name=slide_nav_category', array( $this, 'populate_nav_categories' ) );
	}

	/**
	 * Register all six field groups.
	 */
	public function register_fields() {
		$this->register_tour_settings();
		$this->register_navigation_slides();
		$this->register_testimonials();
		$this->register_floorplans();
		$this->register_embedded_tours();
		$this->register_contact();
	}

	/**
	 * Group 1: Tour Settings.
	 */
	private function register_tour_settings() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_settings',
			'title'    => 'Tour Settings',
			'fields'   => array(
				array(
					'key'           => 'field_h3vt_settings_primary_color',
					'label'         => 'Primary Color',
					'name'          => 'primary_color',
					'type'          => 'color_picker',
					'default_value' => '#FF6B00',
				),
				array(
					'key'           => 'field_h3vt_settings_secondary_color',
					'label'         => 'Secondary Color',
					'name'          => 'secondary_color',
					'type'          => 'color_picker',
					'default_value' => '#1A1A1A',
				),
				array(
					'key'           => 'field_h3vt_settings_text_color',
					'label'         => 'Text Color',
					'name'          => 'text_color',
					'type'          => 'color_picker',
					'default_value' => '#FFFFFF',
				),
				array(
					'key'           => 'field_h3vt_settings_logo',
					'label'         => 'Logo',
					'name'          => 'logo',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'medium',
				),
				array(
					'key'           => 'field_h3vt_settings_icon',
					'label'         => 'Icon',
					'name'          => 'icon',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'thumbnail',
					'instructions'  => 'Icon image for icon-style buttons.',
				),
				array(
					'key'           => 'field_h3vt_settings_hero_image',
					'label'         => 'Hero Image',
					'name'          => 'hero_image',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'large',
				),
				array(
					'key'   => 'field_h3vt_settings_hero_title',
					'label' => 'Hero Title',
					'name'  => 'hero_title',
					'type'  => 'text',
				),
				array(
					'key'   => 'field_h3vt_settings_hero_description',
					'label' => 'Hero Description',
					'name'  => 'hero_description',
					'type'  => 'textarea',
					'rows'  => 3,
				),
				array(
					'key'           => 'field_h3vt_settings_button_style',
					'label'         => 'Button Style',
					'name'          => 'button_style',
					'type'          => 'select',
					'choices'       => array(
						'icon' => 'Icon Style',
						'text' => 'Text Style',
					),
					'default_value' => 'text',
				),
				array(
					'key'           => 'field_h3vt_settings_autoplay_speed',
					'label'         => 'Autoplay Speed',
					'name'          => 'autoplay_speed',
					'type'          => 'number',
					'default_value' => 8,
					'min'           => 2,
					'max'           => 30,
					'step'          => 1,
					'prepend'       => 'seconds',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'h3vt_tour',
					),
				),
			),
			'menu_order' => 0,
		) );
	}

	/**
	 * Group 2: Navigation + Slides.
	 */
	private function register_navigation_slides() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_navigation',
			'title'    => 'Navigation + Slides',
			'fields'   => array(
				array(
					'key'          => 'field_h3vt_navigation_nav_categories',
					'label'        => 'Navigation Categories',
					'name'         => 'nav_categories',
					'type'         => 'repeater',
					'layout'       => 'table',
					'button_label' => 'Add Category',
					'sub_fields'   => array(
						array(
							'key'      => 'field_h3vt_navigation_nav_label',
							'label'    => 'Label',
							'name'     => 'nav_label',
							'type'     => 'text',
							'required' => 1,
						),
						array(
							'key'           => 'field_h3vt_navigation_nav_order',
							'label'         => 'Order',
							'name'          => 'nav_order',
							'type'          => 'number',
							'default_value' => 0,
						),
					),
				),
				array(
					'key'          => 'field_h3vt_navigation_slides',
					'label'        => 'Slides',
					'name'         => 'slides',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => 'Add Slide',
					'sub_fields'   => array(
						array(
							'key'           => 'field_h3vt_navigation_slide_image',
							'label'         => 'Image',
							'name'          => 'slide_image',
							'type'          => 'image',
							'return_format' => 'array',
							'required'      => 1,
						),
						array(
							'key'   => 'field_h3vt_navigation_slide_title',
							'label' => 'Title',
							'name'  => 'slide_title',
							'type'  => 'text',
						),
						array(
							'key'   => 'field_h3vt_navigation_slide_description',
							'label' => 'Description',
							'name'  => 'slide_description',
							'type'  => 'textarea',
							'rows'  => 2,
						),
						array(
							'key'     => 'field_h3vt_navigation_slide_nav_category',
							'label'   => 'Category',
							'name'    => 'slide_nav_category',
							'type'    => 'select',
							'choices' => array(),
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'h3vt_tour',
					),
				),
			),
			'menu_order' => 10,
		) );
	}

	/**
	 * Group 3: Testimonials.
	 */
	private function register_testimonials() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_testimonials',
			'title'    => 'Testimonials',
			'fields'   => array(
				array(
					'key'           => 'field_h3vt_testimonials_enable',
					'label'         => 'Enable Testimonials',
					'name'          => 'enable_testimonials',
					'type'          => 'true_false',
					'default_value' => 0,
					'ui'            => 1,
				),
				array(
					'key'               => 'field_h3vt_testimonials_items',
					'label'             => 'Testimonials',
					'name'              => 'testimonials',
					'type'              => 'repeater',
					'layout'            => 'block',
					'button_label'      => 'Add Testimonial',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_h3vt_testimonials_enable',
								'operator' => '==',
								'value'    => '1',
							),
						),
					),
					'sub_fields'        => array(
						array(
							'key'   => 'field_h3vt_testimonials_person_name',
							'label' => 'Name',
							'name'  => 'person_name',
							'type'  => 'text',
						),
						array(
							'key'   => 'field_h3vt_testimonials_person_role',
							'label' => 'Role',
							'name'  => 'person_role',
							'type'  => 'text',
						),
						array(
							'key'   => 'field_h3vt_testimonials_video_url',
							'label' => 'Video URL',
							'name'  => 'video_url',
							'type'  => 'url',
						),
						array(
							'key'           => 'field_h3vt_testimonials_thumbnail',
							'label'         => 'Thumbnail',
							'name'          => 'thumbnail',
							'type'          => 'image',
							'return_format' => 'array',
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'h3vt_tour',
					),
				),
			),
			'menu_order' => 20,
		) );
	}

	/**
	 * Group 4: Floor Plans.
	 */
	private function register_floorplans() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_floorplans',
			'title'    => 'Floor Plans',
			'fields'   => array(
				array(
					'key'           => 'field_h3vt_floorplans_enable',
					'label'         => 'Enable Floor Plans',
					'name'          => 'enable_floorplans',
					'type'          => 'true_false',
					'default_value' => 0,
					'ui'            => 1,
				),
				array(
					'key'               => 'field_h3vt_floorplans_items',
					'label'             => 'Floor Plans',
					'name'              => 'floorplans',
					'type'              => 'repeater',
					'layout'            => 'block',
					'button_label'      => 'Add Floor Plan',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_h3vt_floorplans_enable',
								'operator' => '==',
								'value'    => '1',
							),
						),
					),
					'sub_fields'        => array(
						array(
							'key'   => 'field_h3vt_floorplans_label',
							'label' => 'Label',
							'name'  => 'floorplan_label',
							'type'  => 'text',
						),
						array(
							'key'           => 'field_h3vt_floorplans_image',
							'label'         => 'Image',
							'name'          => 'floorplan_image',
							'type'          => 'image',
							'return_format' => 'array',
						),
						array(
							'key'          => 'field_h3vt_floorplans_hotspots',
							'label'        => 'Hotspots',
							'name'         => 'floorplan_hotspots',
							'type'         => 'repeater',
							'layout'       => 'table',
							'button_label' => 'Add Hotspot',
							'sub_fields'   => array(
								array(
									'key'    => 'field_h3vt_floorplans_hotspot_x',
									'label'  => 'X Position',
									'name'   => 'hotspot_x',
									'type'   => 'number',
									'min'    => 0,
									'max'    => 100,
									'append' => '%',
								),
								array(
									'key'    => 'field_h3vt_floorplans_hotspot_y',
									'label'  => 'Y Position',
									'name'   => 'hotspot_y',
									'type'   => 'number',
									'min'    => 0,
									'max'    => 100,
									'append' => '%',
								),
								array(
									'key'   => 'field_h3vt_floorplans_hotspot_target',
									'label' => 'Target Slide',
									'name'  => 'hotspot_target_slide',
									'type'  => 'number',
									'min'   => 0,
								),
								array(
									'key'   => 'field_h3vt_floorplans_hotspot_label',
									'label' => 'Label',
									'name'  => 'hotspot_label',
									'type'  => 'text',
								),
							),
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'h3vt_tour',
					),
				),
			),
			'menu_order' => 30,
		) );
	}

	/**
	 * Group 5: Embedded Tours.
	 */
	private function register_embedded_tours() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_embedded',
			'title'    => 'Embedded Tours',
			'fields'   => array(
				array(
					'key'          => 'field_h3vt_embedded_tours',
					'label'        => 'Embedded Tours',
					'name'         => 'embedded_tours',
					'type'         => 'repeater',
					'layout'       => 'table',
					'button_label' => 'Add Embedded Tour',
					'sub_fields'   => array(
						array(
							'key'         => 'field_h3vt_embedded_tour_label',
							'label'       => 'Label',
							'name'        => 'tour_label',
							'type'        => 'text',
							'placeholder' => 'e.g. 3D Tour',
						),
						array(
							'key'   => 'field_h3vt_embedded_tour_url',
							'label' => 'Embed URL',
							'name'  => 'tour_embed_url',
							'type'  => 'url',
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'h3vt_tour',
					),
				),
			),
			'menu_order' => 40,
		) );
	}

	/**
	 * Group 6: Contact.
	 */
	private function register_contact() {
		$contact_conditional = array(
			array(
				array(
					'field'    => 'field_h3vt_contact_enable',
					'operator' => '==',
					'value'    => '1',
				),
			),
		);

		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_contact',
			'title'    => 'Contact',
			'fields'   => array(
				array(
					'key'           => 'field_h3vt_contact_enable',
					'label'         => 'Enable Contact',
					'name'          => 'enable_contact',
					'type'          => 'true_false',
					'default_value' => 0,
					'ui'            => 1,
				),
				array(
					'key'               => 'field_h3vt_contact_facility_name',
					'label'             => 'Facility Name',
					'name'              => 'contact_facility_name',
					'type'              => 'text',
					'conditional_logic' => $contact_conditional,
				),
				array(
					'key'               => 'field_h3vt_contact_address',
					'label'             => 'Address',
					'name'              => 'contact_address',
					'type'              => 'textarea',
					'rows'              => 3,
					'conditional_logic' => $contact_conditional,
				),
				array(
					'key'               => 'field_h3vt_contact_email',
					'label'             => 'Email',
					'name'              => 'contact_email',
					'type'              => 'email',
					'conditional_logic' => $contact_conditional,
				),
				array(
					'key'               => 'field_h3vt_contact_phone',
					'label'             => 'Phone',
					'name'              => 'contact_phone',
					'type'              => 'text',
					'conditional_logic' => $contact_conditional,
				),
				array(
					'key'               => 'field_h3vt_contact_maps_url',
					'label'             => 'Google Maps Embed URL',
					'name'              => 'google_maps_embed_url',
					'type'              => 'url',
					'conditional_logic' => $contact_conditional,
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'h3vt_tour',
					),
				),
			),
			'menu_order' => 50,
		) );
	}

	/**
	 * Dynamically populate the slide_nav_category select field with the
	 * nav_categories repeater labels from the current post.
	 *
	 * @param array $field ACF field config.
	 * @return array
	 */
	public function populate_nav_categories( $field ) {
		$post_id = 0;

		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_POST['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$post_id = absint( $_POST['post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( ! $post_id ) {
			return $field;
		}

		$categories = get_field( 'nav_categories', $post_id );

		if ( empty( $categories ) || ! is_array( $categories ) ) {
			$field['choices'] = array();
			return $field;
		}

		$choices = array();
		foreach ( $categories as $category ) {
			if ( ! empty( $category['nav_label'] ) ) {
				$label             = sanitize_text_field( $category['nav_label'] );
				$choices[ $label ] = $label;
			}
		}

		$field['choices'] = $choices;

		return $field;
	}
}
