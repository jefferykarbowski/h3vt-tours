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
		add_filter( 'acf/load_field/name=slide_floorplan', array( $this, 'populate_floorplan_choices' ) );
		add_filter( 'acf/load_field/name=theme', array( $this, 'populate_theme_choices' ) );
		add_action( 'wp_insert_post', array( $this, 'set_default_nav_categories' ), 10, 3 );
	}

	/**
	 * Default navigation categories applied to every new tour.
	 *
	 * Row order in this array is the display order — categories are
	 * reordered by dragging repeater rows in the editor.
	 *
	 * @return array
	 */
	public static function get_default_nav_categories() {
		return array(
			array( 'nav_label' => 'Resident Rooms' ),
			array( 'nav_label' => 'Common Areas' ),
			array( 'nav_label' => 'Activity Rooms' ),
			array( 'nav_label' => 'Dining / Living Areas' ),
			array( 'nav_label' => 'Outdoor Areas' ),
		);
	}

	/**
	 * Seed the nav_categories repeater with the standard defaults the first
	 * time an h3vt_tour post is created (auto-draft) so editors see the
	 * standard set without having to type them in manually.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  True when updating an existing post.
	 */
	public function set_default_nav_categories( $post_id, $post, $update ) {
		if ( $update ) {
			return;
		}
		if ( 'h3vt_tour' !== $post->post_type ) {
			return;
		}
		if ( ! function_exists( 'update_field' ) || ! function_exists( 'get_field' ) ) {
			return;
		}

		// Only seed when nothing has been saved yet.
		$existing = get_field( 'nav_categories', $post_id );
		if ( ! empty( $existing ) ) {
			return;
		}

		update_field( 'nav_categories', self::get_default_nav_categories(), $post_id );
	}

	/**
	 * Register all six field groups.
	 */
	public function register_fields() {
		$this->register_template_selector();
		$this->register_tour_settings();
		$this->register_template_fields();
		$this->register_navigation_galleries();
		$this->register_attachment_fields();
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
				array(
					'key'           => 'field_h3vt_tour_logo_override',
					'label'         => 'Logo Override',
					'name'          => 'logo_override',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'medium',
					'instructions'  => 'Optional logo used for this tour only, replacing the template logo. Leave empty to use the template logo.',
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
				array(
					'key'          => 'field_h3vt_settings_tour_address',
					'label'        => 'Address',
					'name'         => 'tour_address',
					'type'         => 'textarea',
					'rows'         => 3,
					// Renderer escapes then nl2br()s — leave raw newlines in the value.
					'new_lines'    => '',
					'instructions' => 'Multi-line address shown in the header next to the logo.',
				),
				array(
					'key'           => 'field_h3vt_settings_voiceover',
					'label'         => 'Voice-Over',
					'name'          => 'voiceover',
					'type'          => 'file',
					'return_format' => 'array',
					'mime_types'    => 'mp3',
					'instructions'  => 'Upload an MP3 narration for this tour. A floating play button appears for visitors when one is set.',
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
					'key'           => 'field_h3vt_tpl_nav_control_hover_color',
					'label'         => 'Navigation Control Hover Color',
					'name'          => 'nav_control_hover_color',
					'type'          => 'color_picker',
					'default_value' => '',
					'instructions'  => 'Hover color for playback controls (prev, next, play, home). Defaults to Hover Color if empty.',
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
	 * Group 2: Navigation + Galleries.
	 *
	 * Each navigation category row carries its own gallery — adding a
	 * category adds its gallery, removing it removes the gallery, and
	 * images are reordered by dragging inside the gallery grid. Slide
	 * Title, Description, and Floorplan Hotspot are edited per image in
	 * the gallery sidebar (Edit Image form).
	 */
	private function register_navigation_galleries() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_navigation',
			'title'    => 'Navigation + Galleries',
			'fields'   => array(
				array(
					'key'          => 'field_h3vt_navigation_nav_categories',
					'label'        => 'Navigation Categories',
					'name'         => 'nav_categories',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => 'Add Category',
					'collapsed'    => 'field_h3vt_navigation_nav_label',
					'instructions' => 'Drag rows to reorder categories. Drag images inside a gallery to reorder slides. Click an image in a gallery to edit its Title, Description, and Floorplan Hotspot.',
					'sub_fields'   => array(
						array(
							'key'      => 'field_h3vt_navigation_nav_label',
							'label'    => 'Label',
							'name'     => 'nav_label',
							'type'     => 'text',
							'required' => 1,
						),
						array(
							'key'           => 'field_h3vt_navigation_nav_gallery',
							'label'         => 'Gallery',
							'name'          => 'nav_gallery',
							'type'          => 'gallery',
							'return_format' => 'array',
							'preview_size'  => 'thumbnail',
							'insert'        => 'append',
							'library'       => 'all',
							'button_label'  => 'Add Images',
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
	 * Attachment fields — floorplan hotspot placement, edited per image
	 * inside the gallery sidebar (Edit Image form). Title and Description
	 * use the native attachment Title and Caption fields shown in the
	 * same sidebar.
	 */
	private function register_attachment_fields() {
		acf_add_local_field_group( array(
			'key'      => 'group_h3vt_attachment',
			'title'    => 'Tour Slide',
			'fields'   => array(
				array(
					'key'          => 'field_h3vt_attachment_slide_floorplan',
					'label'        => 'Floorplan Hotspot',
					'name'         => 'slide_floorplan',
					'type'         => 'select',
					'choices'      => array(),
					'instructions' => 'Select a floor plan to place this slide\'s hotspot on.',
					'allow_null'   => 1,
					'wrapper'      => array( 'class' => 'h3vt-hotspot-floorplan-select' ),
				),
				array(
					'key'     => 'field_h3vt_attachment_slide_hotspot_x',
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
					'key'     => 'field_h3vt_attachment_slide_hotspot_y',
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
			'location' => array(
				array(
					array(
						'param'    => 'attachment',
						'operator' => '==',
						'value'    => 'image',
					),
				),
			),
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
	 * Dynamically populate the slide_floorplan select field with the
	 * floorplans repeater labels from the tour the image belongs to.
	 *
	 * The field now lives on attachments and renders inside the gallery
	 * sidebar, so the tour is resolved from (in order): the edit-screen
	 * post, the AJAX post_id, or the attachment's parent post.
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

		// Attachment context (media modal / gallery sidebar) — use the
		// image's parent tour.
		if ( $post_id && 'attachment' === get_post_type( $post_id ) ) {
			$post_id = (int) wp_get_post_parent_id( $post_id );
		}

		if ( ! $post_id || 'h3vt_tour' !== get_post_type( $post_id ) ) {
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
