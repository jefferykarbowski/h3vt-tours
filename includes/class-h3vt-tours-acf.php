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
		add_filter( 'acf/load_field/name=slide_floorplan', array( $this, 'populate_floorplan_choices' ) );
		add_filter( 'acf/load_field/name=theme', array( $this, 'populate_theme_choices' ) );
	}

	/**
	 * Register all six field groups.
	 */
	public function register_fields() {
		$this->register_template_selector();
		$this->register_tour_settings();
		$this->register_template_fields();
		$this->register_navigation_slides();
		$this->register_testimonials();
		$this->register_floorplans();
		$this->register_embedded_tours();
		$this->register_video_popup();
		$this->register_contact();
	}

	/**
	 * Template Selector — appears at the top of the tour editor.
	 */
	private function register_template_selector() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_template_selector',
			'title'    => 'Template',
			'fields'   => array(
				array(
					'key'           => 'field_h3vt_tour_template',
					'label'         => 'Tour Template',
					'name'          => 'tour_template',
					'type'          => 'post_object',
					'post_type'     => array( 'h3vt_tour_template' ),
					'return_format' => 'id',
					'allow_null'    => 1,
					'instructions'  => 'Select a template for shared styling (colors, logo, icon, button style, autoplay speed). Leave empty to use defaults.',
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
			'menu_order' => -1,
		) );
	}

	/**
	 * Group 1: Hero Settings (tour-specific fields only).
	 */
	private function register_tour_settings() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_settings',
			'title'    => 'Hero Settings',
			'fields'   => array(
				array(
					'key'           => 'field_h3vt_settings_hero_media_type',
					'label'         => 'Hero Media Type',
					'name'          => 'hero_media_type',
					'type'          => 'radio',
					'choices'       => array(
						'image' => 'Image',
						'video' => 'Video',
					),
					'default_value' => 'image',
					'layout'        => 'horizontal',
				),
				array(
					'key'               => 'field_h3vt_settings_hero_image',
					'label'             => 'Hero Image',
					'name'              => 'hero_image',
					'type'              => 'image',
					'return_format'     => 'array',
					'preview_size'      => 'large',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_h3vt_settings_hero_media_type',
								'operator' => '==',
								'value'    => 'image',
							),
						),
					),
				),
				array(
					'key'               => 'field_h3vt_settings_hero_video',
					'label'             => 'Hero Video',
					'name'              => 'hero_video',
					'type'              => 'file',
					'return_format'     => 'array',
					'mime_types'        => 'mp4,webm',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_h3vt_settings_hero_media_type',
								'operator' => '==',
								'value'    => 'video',
							),
						),
					),
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
	 * Template Fields — styling/branding fields on the template CPT.
	 */
	private function register_template_fields() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_template',
			'title'    => 'Template Settings',
			'fields'   => array(
				array(
					'key'           => 'field_h3vt_tpl_primary_color',
					'label'         => 'Button Background',
					'name'          => 'primary_color',
					'type'          => 'gradient',
					'default_value' => 'linear-gradient(to bottom, #FF6B00 0%, #FF6B00 100%)',
					'instructions'  => 'Gradient used for button backgrounds. The first color stop is also used as the solid accent color for hover states, borders, and links.',
				),
				array(
					'key'           => 'field_h3vt_tpl_secondary_color',
					'label'         => 'Header Background',
					'name'          => 'secondary_color',
					'type'          => 'gradient',
					'default_value' => 'linear-gradient(to bottom, #1A1A1A 0%, #1A1A1A 100%)',
					'instructions'  => 'Gradient used for the header bar background. The first color stop is also used as a solid fallback color.',
				),
				array(
					'key'           => 'field_h3vt_tpl_text_color',
					'label'         => 'Text Color',
					'name'          => 'text_color',
					'type'          => 'color_picker',
					'default_value' => '#FFFFFF',
				),
				array(
					'key'           => 'field_h3vt_tpl_hover_color',
					'label'         => 'Hover Color',
					'name'          => 'hover_color',
					'type'          => 'color_picker',
					'default_value' => '',
					'instructions'  => 'Color used for hover states on buttons and links. Defaults to Button Backgrounds color if empty.',
				),
					array(
					'key'           => 'field_h3vt_tpl_dropdown_hover_color',
					'label'         => 'Dropdown Hover Color',
					'name'          => 'dropdown_hover_color',
					'type'          => 'color_picker',
					'default_value' => '',
					'instructions'  => 'Hover color for navigation dropdown items. Defaults to Hover Color if empty.',
				),
				array(
					'key'           => 'field_h3vt_tpl_pdf_file',
					'label'         => 'PDF File',
					'name'          => 'pdf_file',
					'type'          => 'file',
					'return_format' => 'array',
					'mime_types'    => 'pdf',
					'instructions'  => 'Upload a PDF to display in a lightbox (e.g. brochure, menu, pricing).',
				),
				array(
					'key'          => 'field_h3vt_tpl_pdf_button_text',
					'label'        => 'PDF Button Text',
					'name'         => 'pdf_button_text',
					'type'         => 'text',
					'placeholder'  => 'Brochure',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_h3vt_tpl_pdf_file',
								'operator' => '!=empty',
							),
						),
					),
				),
				array(
					'key'          => 'field_h3vt_tpl_social_facebook',
					'label'        => 'Facebook URL',
					'name'         => 'social_facebook',
					'type'         => 'url',
					'wrapper'      => array( 'width' => '50' ),
				),
				array(
					'key'          => 'field_h3vt_tpl_social_instagram',
					'label'        => 'Instagram URL',
					'name'         => 'social_instagram',
					'type'         => 'url',
					'wrapper'      => array( 'width' => '50' ),
				),
				array(
					'key'          => 'field_h3vt_tpl_social_youtube',
					'label'        => 'YouTube URL',
					'name'         => 'social_youtube',
					'type'         => 'url',
					'wrapper'      => array( 'width' => '50' ),
				),
				array(
					'key'          => 'field_h3vt_tpl_social_linkedin',
					'label'        => 'LinkedIn URL',
					'name'         => 'social_linkedin',
					'type'         => 'url',
					'wrapper'      => array( 'width' => '50' ),
				),
				array(
					'key'          => 'field_h3vt_tpl_social_x',
					'label'        => 'X (Twitter) URL',
					'name'         => 'social_x',
					'type'         => 'url',
					'wrapper'      => array( 'width' => '50' ),
				),
				array(
					'key'          => 'field_h3vt_tpl_social_tiktok',
					'label'        => 'TikTok URL',
					'name'         => 'social_tiktok',
					'type'         => 'url',
					'wrapper'      => array( 'width' => '50' ),
				),
				array(
					'key'          => 'field_h3vt_tpl_social_pinterest',
					'label'        => 'Pinterest URL',
					'name'         => 'social_pinterest',
					'type'         => 'url',
					'wrapper'      => array( 'width' => '50' ),
				),
				array(
					'key'           => 'field_h3vt_tpl_logo',
					'label'         => 'Logo',
					'name'          => 'logo',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'medium',
				),
				array(
					'key'           => 'field_h3vt_tpl_icon',
					'label'         => 'Icon',
					'name'          => 'icon',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'thumbnail',
					'instructions'  => 'Icon image for icon-style buttons.',
				),
				array(
					'key'           => 'field_h3vt_tpl_button_style',
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
					'key'           => 'field_h3vt_tpl_autoplay_speed',
					'label'         => 'Autoplay Speed',
					'name'          => 'autoplay_speed',
					'type'          => 'number',
					'default_value' => 8,
					'min'           => 2,
					'max'           => 30,
					'step'          => 1,
					'prepend'       => 'seconds',
				),
				array(
					'key'           => 'field_h3vt_tpl_theme',
					'label'         => 'Theme',
					'name'          => 'theme',
					'type'          => 'select',
					'choices'       => array(),
					'default_value' => 'classic',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'h3vt_tour_template',
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
						array(
							'key'          => 'field_h3vt_navigation_slide_floorplan',
							'label'        => 'Floorplan Hotspot',
							'name'         => 'slide_floorplan',
							'type'         => 'select',
							'choices'      => array(),
							'instructions' => 'Select a floor plan to place this slide\'s hotspot on.',
							'allow_null'   => 1,
							'wrapper'      => array( 'class' => 'h3vt-hotspot-floorplan-select' ),
						),
						array(
							'key'     => 'field_h3vt_navigation_slide_hotspot_x',
							'label'   => 'Hotspot X',
							'name'    => 'slide_hotspot_x',
							'type'    => 'number',
							'min'     => 0,
							'max'     => 100,
							'step'    => 0.1,
							'append'  => '%',
							'wrapper' => array( 'class' => 'h3vt-hotspot-x-field' ),
						),
						array(
							'key'     => 'field_h3vt_navigation_slide_hotspot_y',
							'label'   => 'Hotspot Y',
							'name'    => 'slide_hotspot_y',
							'type'    => 'number',
							'min'     => 0,
							'max'     => 100,
							'step'    => 0.1,
							'append'  => '%',
							'wrapper' => array( 'class' => 'h3vt-hotspot-y-field' ),
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
							'key'           => 'field_h3vt_testimonials_video_url',
							'label'         => 'Video',
							'name'          => 'video_url',
							'type'          => 'file',
							'return_format' => 'array',
							'mime_types'    => 'mp4,webm,mov',
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
	 * Group: Videos.
	 */
	private function register_video_popup() {
		$videos_conditional = array(
			array(
				array(
					'field'    => 'field_h3vt_videos_enable',
					'operator' => '==',
					'value'    => '1',
				),
			),
		);

		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_videos',
			'title'    => 'Videos',
			'fields'   => array(
				array(
					'key'           => 'field_h3vt_videos_enable',
					'label'         => 'Enable Videos',
					'name'          => 'enable_videos',
					'type'          => 'true_false',
					'default_value' => 0,
					'ui'            => 1,
				),
				array(
					'key'               => 'field_h3vt_videos_items',
					'label'             => 'Videos',
					'name'              => 'videos',
					'type'              => 'repeater',
					'layout'            => 'table',
					'button_label'      => 'Add Video',
					'conditional_logic' => $videos_conditional,
					'sub_fields'        => array(
						array(
							'key'         => 'field_h3vt_videos_label',
							'label'       => 'Label',
							'name'        => 'video_label',
							'type'        => 'text',
							'placeholder' => 'e.g. Walk Our Courtyard',
						),
						array(
							'key'           => 'field_h3vt_videos_url',
							'label'         => 'Video',
							'name'          => 'video_url',
							'type'          => 'file',
							'return_format' => 'array',
							'mime_types'    => 'mp4,webm,mov',
						),
					),
				),
				array(
					'key'               => 'field_h3vt_videos_slideshow',
					'label'             => 'Bundle as Slideshow',
					'name'              => 'video_slideshow',
					'type'              => 'true_false',
					'instructions'      => 'Enable to combine all videos into a single slideshow button instead of individual buttons.',
					'default_value'     => 0,
					'ui'                => 1,
					'conditional_logic' => $videos_conditional,
				),
				array(
					'key'               => 'field_h3vt_videos_slideshow_label',
					'label'             => 'Slideshow Button Label',
					'name'              => 'video_slideshow_label',
					'type'              => 'text',
					'placeholder'       => 'Videos',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_h3vt_videos_enable',
								'operator' => '==',
								'value'    => '1',
							),
							array(
								'field'    => 'field_h3vt_videos_slideshow',
								'operator' => '==',
								'value'    => '1',
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
			'menu_order' => 25,
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

	/**
	 * Dynamically populate the slide_floorplan select field with the
	 * floorplans repeater labels from the current post.
	 *
	 * @param array $field ACF field config.
	 * @return array
	 */
	public function populate_floorplan_choices( $field ) {
		$post_id = 0;

		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_POST['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$post_id = absint( $_POST['post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( ! $post_id ) {
			return $field;
		}

		$floorplans = get_field( 'floorplans', $post_id );

		if ( empty( $floorplans ) || ! is_array( $floorplans ) ) {
			$field['choices'] = array();
			return $field;
		}

		$choices = array();
		foreach ( $floorplans as $index => $fp ) {
			$label = ! empty( $fp['floorplan_label'] ) ? sanitize_text_field( $fp['floorplan_label'] ) : sprintf( 'Floor Plan %d', $index + 1 );
			$choices[ $index ] = $label;
		}

		$field['choices'] = $choices;

		return $field;
	}

	/**
	 * Dynamically populate the theme select field with discovered themes.
	 *
	 * @param array $field ACF field config.
	 * @return array
	 */
	public function populate_theme_choices( $field ) {
		$field['choices'] = H3VT_Tours_Theme_Loader::get_theme_choices();
		return $field;
	}
}
