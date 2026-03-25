<?php
/**
 * Tour HTML renderer.
 *
 * Every piece of front-end markup is generated here so that the template,
 * shortcode, and REST embed endpoints all share a single source of truth.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper that assembles the complete tour HTML from ACF data.
 */
class H3VT_Tours_Renderer {

	/**
	 * Collect and normalise every ACF field for a tour post.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array
	 */
	public static function get_tour_data( $post_id ) {
		/*
		 * Resolve template — styling fields come from the linked template
		 * post when one is selected and valid, otherwise use defaults.
		 */
		$template_id = get_field( 'tour_template', $post_id );
		$use_template = false;

		if ( $template_id ) {
			$template_id  = absint( $template_id );
			$template_post = get_post( $template_id );

			if ( $template_post
				&& 'h3vt_tour_template' === $template_post->post_type
				&& 'publish' === $template_post->post_status
			) {
				$use_template = true;
			}
		}

		if ( $use_template ) {
			$primary_color_raw      = get_field( 'primary_color', $template_id ) ?: '#FF6B00';
			$secondary_color_raw    = get_field( 'secondary_color', $template_id ) ?: '#1A1A1A';
			$text_color             = get_field( 'text_color', $template_id ) ?: '#FFFFFF';
			$hover_color            = get_field( 'hover_color', $template_id ) ?: '';
			$dropdown_hover_color   = get_field( 'dropdown_hover_color', $template_id ) ?: '';
			$logo                   = get_field( 'logo', $template_id );
			$icon                   = get_field( 'icon', $template_id );
			$button_style           = get_field( 'button_style', $template_id ) ?: 'text';
			$autoplay_speed         = get_field( 'autoplay_speed', $template_id ) ?: 8;
			$theme                  = get_field( 'theme', $template_id ) ?: 'classic';
			$pdf_file               = get_field( 'pdf_file', $template_id );
			$pdf_button_text        = get_field( 'pdf_button_text', $template_id ) ?: __( 'Brochure', 'h3vt-tours' );
			$socials                = array(
				'facebook'  => get_field( 'social_facebook', $template_id ) ?: '',
				'instagram' => get_field( 'social_instagram', $template_id ) ?: '',
				'youtube'   => get_field( 'social_youtube', $template_id ) ?: '',
				'linkedin'  => get_field( 'social_linkedin', $template_id ) ?: '',
				'x'         => get_field( 'social_x', $template_id ) ?: '',
				'tiktok'    => get_field( 'social_tiktok', $template_id ) ?: '',
				'pinterest' => get_field( 'social_pinterest', $template_id ) ?: '',
			);
		} else {
			$primary_color_raw      = '#FF6B00';
			$secondary_color_raw    = '#1A1A1A';
			$text_color             = '#FFFFFF';
			$hover_color            = '';
			$dropdown_hover_color   = '';
			$logo                   = null;
			$icon                   = null;
			$button_style           = 'text';
			$autoplay_speed         = 8;
			$theme                  = 'classic';
			$pdf_file               = null;
			$pdf_button_text        = __( 'Brochure', 'h3vt-tours' );
			$socials                = array();
		}

		/*
		 * Parse gradient fields — extract the first color stop as
		 * a solid fallback for hover states, borders, links, etc.
		 * The full gradient string is passed separately for backgrounds.
		 */
		$primary_gradient   = '';
		$primary_color      = '#FF6B00';
		$secondary_gradient = '';
		$secondary_color    = '#1A1A1A';

		if ( is_string( $primary_color_raw ) && 0 === strpos( $primary_color_raw, 'linear-gradient' ) ) {
			$primary_gradient = $primary_color_raw;
			if ( function_exists( 'gpfa_parse_gradient' ) ) {
				$parsed = gpfa_parse_gradient( $primary_color_raw );
				if ( $parsed && ! empty( $parsed['stops'][0]['color'] ) ) {
					$primary_color = $parsed['stops'][0]['color'];
				}
			}
		} elseif ( $primary_color_raw ) {
			$primary_color = $primary_color_raw;
		}

		if ( is_string( $secondary_color_raw ) && 0 === strpos( $secondary_color_raw, 'linear-gradient' ) ) {
			$secondary_gradient = $secondary_color_raw;
			if ( function_exists( 'gpfa_parse_gradient' ) ) {
				$parsed = gpfa_parse_gradient( $secondary_color_raw );
				if ( $parsed && ! empty( $parsed['stops'][0]['color'] ) ) {
					$secondary_color = $parsed['stops'][0]['color'];
				}
			}
		} elseif ( $secondary_color_raw ) {
			$secondary_color = $secondary_color_raw;
		}

		/*
		 * Settings.
		 */
		$settings = array(
			'primary_color'          => $primary_color,
			'primary_gradient'       => $primary_gradient,
			'secondary_color'        => $secondary_color,
			'secondary_gradient'     => $secondary_gradient,
			'text_color'             => $text_color,
			'hover_color'            => $hover_color,
			'dropdown_hover_color'   => $dropdown_hover_color,
			'logo'                   => $logo,
			'icon'             => $icon,
			'hero_image'       => get_field( 'hero_image', $post_id ),
			'hero_media_type'  => get_field( 'hero_media_type', $post_id ) ?: 'image',
			'hero_video'       => get_field( 'hero_video', $post_id ),
			'hero_title'       => get_field( 'hero_title', $post_id ) ?: '',
			'hero_description' => get_field( 'hero_description', $post_id ) ?: '',
			'button_style'     => $button_style,
			'autoplay_speed'   => $autoplay_speed,
			'theme'            => $theme,
			'pdf_file'         => $pdf_file,
			'pdf_button_text'  => $pdf_button_text,
			'socials'          => array_filter( $socials ),
		);

		/*
		 * Navigation categories — sorted by nav_order.
		 */
		$raw_categories = get_field( 'nav_categories', $post_id );
		$nav_categories = array();
		if ( is_array( $raw_categories ) ) {
			$nav_categories = $raw_categories;
			usort( $nav_categories, function ( $a, $b ) {
				$a_order = isset( $a['nav_order'] ) ? (int) $a['nav_order'] : 0;
				$b_order = isset( $b['nav_order'] ) ? (int) $b['nav_order'] : 0;
				return $a_order - $b_order;
			} );
		}

		/*
		 * Slides — each receives a 1-based slide_index (0 = hero).
		 */
		$raw_slides = get_field( 'slides', $post_id );
		$slides     = array();
		if ( is_array( $raw_slides ) ) {
			$index = 1;
			foreach ( $raw_slides as $slide ) {
				$slide['slide_index'] = $index;
				$slides[]             = $slide;
				$index++;
			}
		}

		/*
		 * Testimonials.
		 */
		$testimonials_enabled = (bool) get_field( 'enable_testimonials', $post_id );
		$testimonials_items   = array();
		if ( $testimonials_enabled ) {
			$raw = get_field( 'testimonials', $post_id );
			if ( is_array( $raw ) ) {
				$testimonials_items = $raw;
			}
		}

		/*
		 * Floor plans.
		 */
		$floorplans_enabled = (bool) get_field( 'enable_floorplans', $post_id );
		$floorplans_items   = array();
		if ( $floorplans_enabled ) {
			$raw = get_field( 'floorplans', $post_id );
			if ( is_array( $raw ) ) {
				$floorplans_items = $raw;
			}
		}

		/*
		 * Merge slide-level hotspots into floorplan items.
		 */
		if ( $floorplans_enabled && ! empty( $floorplans_items ) ) {
			foreach ( $slides as $slide ) {
				$fp_index = isset( $slide['slide_floorplan'] ) ? $slide['slide_floorplan'] : '';
				$hs_x     = isset( $slide['slide_hotspot_x'] ) ? $slide['slide_hotspot_x'] : '';
				$hs_y     = isset( $slide['slide_hotspot_y'] ) ? $slide['slide_hotspot_y'] : '';

				if ( '' === $fp_index || '' === $hs_x || '' === $hs_y ) {
					continue;
				}

				$fp_index = absint( $fp_index );
				if ( ! isset( $floorplans_items[ $fp_index ] ) ) {
					continue;
				}

				if ( empty( $floorplans_items[ $fp_index ]['floorplan_hotspots'] ) || ! is_array( $floorplans_items[ $fp_index ]['floorplan_hotspots'] ) ) {
					$floorplans_items[ $fp_index ]['floorplan_hotspots'] = array();
				}

				$floorplans_items[ $fp_index ]['floorplan_hotspots'][] = array(
					'hotspot_x'            => floatval( $hs_x ),
					'hotspot_y'            => floatval( $hs_y ),
					'hotspot_target_slide' => $slide['slide_index'],
					'hotspot_label'        => ! empty( $slide['slide_title'] ) ? $slide['slide_title'] : '',
				);
			}
		}

		/*
		 * Embedded tours.
		 */
		$embedded_tours = get_field( 'embedded_tours', $post_id );
		if ( ! is_array( $embedded_tours ) ) {
			$embedded_tours = array();
		}
		$embedded_tours = array_values( array_filter( $embedded_tours, function ( $etour ) {
			return ! empty( $etour['tour_embed_url'] );
		} ) );

		/*
		 * Videos.
		 */
		$videos_enabled = (bool) get_field( 'enable_videos', $post_id );
		$videos_items   = array();
		if ( $videos_enabled ) {
			$raw = get_field( 'videos', $post_id );
			if ( is_array( $raw ) ) {
				$videos_items = array_values( array_filter( $raw, function ( $video ) {
					return ! empty( $video['video_url'] );
				} ) );
			}
		}

		/*
		 * Contact.
		 */
		$contact_enabled = (bool) get_field( 'enable_contact', $post_id );
		$contact         = array(
			'enabled'               => $contact_enabled,
			'contact_facility_name' => $contact_enabled ? ( get_field( 'contact_facility_name', $post_id ) ?: '' ) : '',
			'contact_address'       => $contact_enabled ? ( get_field( 'contact_address', $post_id ) ?: '' ) : '',
			'contact_email'         => $contact_enabled ? ( get_field( 'contact_email', $post_id ) ?: '' ) : '',
			'contact_phone'         => $contact_enabled ? ( get_field( 'contact_phone', $post_id ) ?: '' ) : '',
			'google_maps_embed_url' => $contact_enabled ? ( get_field( 'google_maps_embed_url', $post_id ) ?: '' ) : '',
		);

		return array(
			'settings'        => $settings,
			'nav_categories'  => $nav_categories,
			'slides'          => $slides,
			'testimonials'    => array(
				'enabled' => $testimonials_enabled,
				'items'   => $testimonials_items,
			),
			'floorplans'      => array(
				'enabled' => $floorplans_enabled,
				'items'   => $floorplans_items,
			),
			'embedded_tours'  => $embedded_tours,
			'videos'          => array(
				'enabled'         => $videos_enabled,
				'items'           => $videos_items,
				'slideshow'       => $videos_enabled ? (bool) get_field( 'video_slideshow', $post_id ) : false,
				'slideshow_label' => $videos_enabled ? ( get_field( 'video_slideshow_label', $post_id ) ?: __( 'Videos', 'h3vt-tours' ) ) : '',
			),
			'contact'         => $contact,
		);
	}

	/**
	 * Render the full tour HTML.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $context One of 'template', 'shortcode', 'embed'.
	 * @return string
	 */
	public static function render( $post_id, $context = 'template' ) {
		$data = self::get_tour_data( $post_id );

		$button_bg       = esc_attr( $data['settings']['primary_color'] );
		$btn_gradient    = esc_attr( $data['settings']['primary_gradient'] );
		$header_bg       = esc_attr( $data['settings']['secondary_color'] );
		$hdr_gradient    = esc_attr( $data['settings']['secondary_gradient'] );
		$text            = esc_attr( $data['settings']['text_color'] );
		$hover           = esc_attr( $data['settings']['hover_color'] );
		$dropdown_hover  = esc_attr( $data['settings']['dropdown_hover_color'] );
		$speed_ms        = absint( $data['settings']['autoplay_speed'] ) * 1000;

		$extra_css = '';
		if ( $hover ) {
			$extra_css .= ';--h3vt-hover:' . $hover;
		}
		if ( $btn_gradient ) {
			$extra_css .= ';--h3vt-button-bg-gradient:' . $btn_gradient;
		}
		if ( $hdr_gradient ) {
			$extra_css .= ';--h3vt-header-bg-gradient:' . $hdr_gradient;
		}
		if ( $dropdown_hover ) {
			$extra_css .= ';--h3vt-dropdown-hover:' . $dropdown_hover;
		}

		$theme       = $data['settings']['theme'];
		$theme_class = 'classic' !== $theme ? ' h3vt-tour--theme-' . esc_attr( $theme ) : '';

		ob_start();

		echo H3VT_Tours_Theme_Loader::get_head_markup( $theme );
		?>
		<div class="h3vt-tour<?php echo $theme_class; ?>"
			style="--h3vt-button-bg:<?php echo $button_bg; ?>;--h3vt-header-bg:<?php echo $header_bg; ?>;--h3vt-text:<?php echo $text; ?><?php echo $extra_css; ?>"
			data-autoplay-speed="<?php echo esc_attr( $speed_ms ); ?>">
			<?php
			self::render_header( $data );
			self::render_slides( $data );
			self::render_social_sidebar( $data );
			self::render_bottom_bar( $data );
			self::render_modals( $data );
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Header
	 * ----------------------------------------------------------------*/

	/**
	 * Render the top header bar with logo, navigation, and fullscreen toggle.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_header( $data ) {
		$logo = $data['settings']['logo'];
		?>
		<header class="h3vt-tour__header">
			<?php if ( ! empty( $logo ) && is_array( $logo ) ) : ?>
				<div class="h3vt-tour__logo">
					<img src="<?php echo esc_url( $logo['url'] ); ?>"
						alt="<?php echo esc_attr( $logo['alt'] ); ?>"
						width="<?php echo esc_attr( $logo['width'] ); ?>"
						height="<?php echo esc_attr( $logo['height'] ); ?>">
				</div>
			<?php endif; ?>

			<nav class="h3vt-tour__nav">
				<?php foreach ( $data['nav_categories'] as $category ) : ?>
					<div class="h3vt-tour__nav-item">
						<button class="h3vt-tour__nav-button">
							<?php echo esc_html( $category['nav_label'] ); ?>
							<svg viewBox="0 0 12 8" class="h3vt-tour__nav-chevron"><polyline points="1 1 6 6 11 1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
						</button>
						<div class="h3vt-tour__dropdown">
							<?php
							foreach ( $data['slides'] as $slide ) :
								$slide_category = isset( $slide['slide_nav_category'] ) ? $slide['slide_nav_category'] : '';
								if ( $slide_category !== $category['nav_label'] ) {
									continue;
								}
								?>
								<button class="h3vt-tour__dropdown-item" data-slide-index="<?php echo esc_attr( $slide['slide_index'] ); ?>">
									<?php echo esc_html( $slide['slide_title'] ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</nav>

			<button class="h3vt-tour__fullscreen-btn" aria-label="<?php esc_attr_e( 'Toggle fullscreen', 'h3vt-tours' ); ?>">
				<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>
			</button>

			<button class="h3vt-tour__hamburger" aria-label="<?php esc_attr_e( 'Toggle menu', 'h3vt-tours' ); ?>" aria-expanded="false">
				<span></span><span></span><span></span>
			</button>
		</header>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Slides
	 * ----------------------------------------------------------------*/

	/**
	 * Render the slideshow area: hero slide at index 0, then repeater slides.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_slides( $data ) {
		$hero_image      = $data['settings']['hero_image'];
		$hero_url        = ( ! empty( $hero_image ) && is_array( $hero_image ) ) ? $hero_image['url'] : '';
		$hero_media_type = $data['settings']['hero_media_type'];
		$hero_video      = $data['settings']['hero_video'];
		$hero_video_url  = ( ! empty( $hero_video ) && is_array( $hero_video ) ) ? $hero_video['url'] : '';
		$is_hero_video   = ( 'video' === $hero_media_type && $hero_video_url );
		$hero_kb         = $is_hero_video ? 0 : wp_rand( 1, 6 );
		?>
		<div class="h3vt-tour__slides">
			<?php /* Hero slide — index 0 */ ?>
			<div class="h3vt-tour__slide h3vt-tour__slide--active" data-index="0" data-kenburns="<?php echo esc_attr( $hero_kb ); ?>"<?php echo $is_hero_video ? ' data-hero-video="true"' : ''; ?>>
				<?php if ( $is_hero_video ) : ?>
					<video class="h3vt-tour__slide-video" autoplay muted playsinline preload="auto">
						<source src="<?php echo esc_url( $hero_video_url ); ?>" type="<?php echo esc_attr( $hero_video['mime_type'] ); ?>">
					</video>
				<?php else : ?>
					<div class="h3vt-tour__slide-image" style="background-image:url(<?php echo esc_url( $hero_url ); ?>)"></div>
				<?php endif; ?>
				<div class="h3vt-tour__slide-content">
					<?php if ( $data['settings']['hero_title'] ) : ?>
						<h2 class="h3vt-tour__slide-title"><?php echo esc_html( $data['settings']['hero_title'] ); ?></h2>
					<?php endif; ?>
					<?php if ( $data['settings']['hero_description'] ) : ?>
						<p class="h3vt-tour__slide-description"><?php echo esc_html( $data['settings']['hero_description'] ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php /* Repeater slides — index 1+ */ ?>
			<?php foreach ( $data['slides'] as $slide ) : ?>
				<?php
				$img_url = ( ! empty( $slide['slide_image'] ) && is_array( $slide['slide_image'] ) ) ? $slide['slide_image']['url'] : '';
				$kb      = wp_rand( 1, 6 );
				?>
				<div class="h3vt-tour__slide" data-index="<?php echo esc_attr( $slide['slide_index'] ); ?>" data-kenburns="<?php echo esc_attr( $kb ); ?>">
					<div class="h3vt-tour__slide-image" style="background-image:url(<?php echo esc_url( $img_url ); ?>)"></div>
					<div class="h3vt-tour__slide-content">
						<?php if ( ! empty( $slide['slide_title'] ) ) : ?>
							<h2 class="h3vt-tour__slide-title"><?php echo esc_html( $slide['slide_title'] ); ?></h2>
						<?php endif; ?>
						<?php if ( ! empty( $slide['slide_description'] ) ) : ?>
							<p class="h3vt-tour__slide-description"><?php echo esc_html( $slide['slide_description'] ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Bottom bar
	 * ----------------------------------------------------------------*/

	/**
	 * Render the bottom control bar with feature buttons, playback controls,
	 * and section buttons.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_bottom_bar( $data ) {
		?>
		<div class="h3vt-tour__bottom-bar">
			<div class="h3vt-tour__bottom-left">
				<?php if ( $data['testimonials']['enabled'] && ! empty( $data['testimonials']['items'] ) ) : ?>
					<?php self::render_bottom_button( __( 'Testimonials', 'h3vt-tours' ), 'testimonials', $data ); ?>
				<?php endif; ?>

				<?php if ( $data['videos']['enabled'] && ! empty( $data['videos']['items'] ) ) : ?>
					<?php if ( $data['videos']['slideshow'] ) : ?>
						<?php self::render_bottom_button( $data['videos']['slideshow_label'], 'videos', $data ); ?>
					<?php else : ?>
						<?php foreach ( $data['videos']['items'] as $vi => $video ) : ?>
							<?php
							$label = ! empty( $video['video_label'] ) ? $video['video_label'] : __( 'Video', 'h3vt-tours' );
							self::render_bottom_button( $label, 'video-' . $vi, $data );
							?>
						<?php endforeach; ?>
					<?php endif; ?>
				<?php endif; ?>

				<?php foreach ( $data['embedded_tours'] as $idx => $etour ) : ?>
					<?php
					$label = ! empty( $etour['tour_label'] ) ? $etour['tour_label'] : __( '3D Tour', 'h3vt-tours' );
					self::render_bottom_button( $label, '3dtour-' . $idx, $data );
					?>
				<?php endforeach; ?>
			</div>

			<div class="h3vt-tour__controls">
				<button class="h3vt-tour__control" data-action="home" aria-label="<?php esc_attr_e( 'Home', 'h3vt-tours' ); ?>">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
				</button>
				<button class="h3vt-tour__control" data-action="prev" aria-label="<?php esc_attr_e( 'Previous', 'h3vt-tours' ); ?>">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
				</button>
				<button class="h3vt-tour__control" data-action="playpause" aria-label="<?php esc_attr_e( 'Pause slideshow', 'h3vt-tours' ); ?>">
					<svg class="h3vt-tour__icon--pause" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
					<svg class="h3vt-tour__icon--play" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" style="display:none"><path d="M8 5v14l11-7z"/></svg>
				</button>
				<button class="h3vt-tour__control" data-action="next" aria-label="<?php esc_attr_e( 'Next', 'h3vt-tours' ); ?>">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
				</button>
			</div>

			<div class="h3vt-tour__bottom-right">
				<?php if ( ! empty( $data['settings']['pdf_file'] ) ) : ?>
					<?php self::render_bottom_button( esc_html( $data['settings']['pdf_button_text'] ), 'pdf', $data ); ?>
				<?php endif; ?>

				<?php if ( $data['floorplans']['enabled'] && ! empty( $data['floorplans']['items'] ) ) : ?>
					<?php self::render_bottom_button( __( 'Floor Plans', 'h3vt-tours' ), 'floorplans', $data ); ?>
				<?php endif; ?>

				<?php if ( $data['contact']['enabled'] ) : ?>
					<?php self::render_bottom_button( __( 'Contact', 'h3vt-tours' ), 'contact', $data ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single bottom-bar button (icon or text style).
	 *
	 * @param string $label      Visible label text.
	 * @param string $modal_name The data-modal value to open.
	 * @param array  $data       Tour data (used to determine style and icon).
	 */
	private static function render_bottom_button( $label, $modal_name, $data ) {
		$style = $data['settings']['button_style'];
		$icon  = $data['settings']['icon'];

		if ( 'icon' === $style && ! empty( $icon ) && is_array( $icon ) ) {
			?>
			<button class="h3vt-tour__bottom-btn h3vt-tour__bottom-btn--icon" data-modal="<?php echo esc_attr( $modal_name ); ?>">
				<img src="<?php echo esc_url( $icon['url'] ); ?>" alt="" width="32" height="32">
				<span><?php echo esc_html( $label ); ?></span>
			</button>
			<?php
		} else {
			?>
			<button class="h3vt-tour__bottom-btn h3vt-tour__bottom-btn--text" data-modal="<?php echo esc_attr( $modal_name ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
			</button>
			<?php
		}
	}

	/* ------------------------------------------------------------------
	 * Modals
	 * ----------------------------------------------------------------*/

	/**
	 * Render every modal / panel that may be toggled open.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_modals( $data ) {
		if ( $data['testimonials']['enabled'] && ! empty( $data['testimonials']['items'] ) ) {
			self::render_testimonials_modal( $data );
		}

		if ( $data['floorplans']['enabled'] && ! empty( $data['floorplans']['items'] ) ) {
			self::render_floorplans_panel( $data );
		}

		if ( $data['contact']['enabled'] ) {
			self::render_contact_modal( $data );
		}

		foreach ( $data['embedded_tours'] as $idx => $etour ) {
			self::render_3dtour_modal( $idx, $etour );
		}

		if ( $data['videos']['enabled'] && ! empty( $data['videos']['items'] ) ) {
			if ( $data['videos']['slideshow'] ) {
				self::render_videos_modal( $data );
			} else {
				foreach ( $data['videos']['items'] as $vi => $video ) {
					self::render_single_video_modal( $vi, $video );
				}
			}
		}

		if ( ! empty( $data['settings']['pdf_file'] ) ) {
			self::render_pdf_modal( $data );
		}
	}

	/**
	 * Testimonials modal with a video area and thumbnail carousel.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_testimonials_modal( $data ) {
		?>
		<div class="h3vt-tour__modal h3vt-tour__modal--testimonials" data-modal-name="testimonials" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Testimonials', 'h3vt-tours' ); ?>" hidden>
			<div class="h3vt-tour__modal-backdrop"></div>
			<div class="h3vt-tour__modal-content">
				<button class="h3vt-tour__modal-close" aria-label="<?php esc_attr_e( 'Close', 'h3vt-tours' ); ?>">&times;</button>
				<div class="h3vt-tour__testimonial-video"></div>
				<div class="h3vt-tour__testimonial-carousel">
					<?php foreach ( $data['testimonials']['items'] as $ti => $testimonial ) : ?>
						<?php
						$thumb_url = '';
						if ( ! empty( $testimonial['thumbnail'] ) && is_array( $testimonial['thumbnail'] ) ) {
							$thumb_url = $testimonial['thumbnail']['url'];
						}
						$video_file = $testimonial['video_url'];
						$video_url  = ( ! empty( $video_file ) && is_array( $video_file ) ) ? $video_file['url'] : '';
						?>
						<button class="h3vt-tour__testimonial-thumb"
							data-index="<?php echo esc_attr( $ti ); ?>"
							data-video-url="<?php echo esc_url( $video_url ); ?>">
							<?php if ( $thumb_url ) : ?>
								<img src="<?php echo esc_url( $thumb_url ); ?>"
									alt="<?php echo esc_attr( $testimonial['person_name'] ); ?>">
							<?php endif; ?>
							<span class="h3vt-tour__testimonial-name"><?php echo esc_html( $testimonial['person_name'] ); ?></span>
							<span class="h3vt-tour__testimonial-role"><?php echo esc_html( $testimonial['person_role'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Floor plans side panel with select dropdown and hotspots.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_floorplans_panel( $data ) {
		$floorplans = $data['floorplans']['items'];
		?>
		<div class="h3vt-tour__panel h3vt-tour__panel--floorplans" data-modal-name="floorplans" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Floor Plans', 'h3vt-tours' ); ?>" hidden>
			<button class="h3vt-tour__panel-close" aria-label="<?php esc_attr_e( 'Close', 'h3vt-tours' ); ?>">&times;</button>

			<?php if ( count( $floorplans ) > 1 ) : ?>
				<select class="h3vt-tour__floorplan-select">
					<?php foreach ( $floorplans as $fi => $fp ) : ?>
						<option value="<?php echo esc_attr( $fi ); ?>"><?php echo esc_html( $fp['floorplan_label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<div class="h3vt-tour__floorplan-wrapper">
				<?php foreach ( $floorplans as $fi => $fp ) : ?>
					<?php
					$fp_url   = '';
					$fp_label = ! empty( $fp['floorplan_label'] ) ? $fp['floorplan_label'] : '';
					if ( ! empty( $fp['floorplan_image'] ) && is_array( $fp['floorplan_image'] ) ) {
						$fp_url = $fp['floorplan_image']['url'];
					}
					$active_class = ( 0 === $fi ) ? ' h3vt-tour__floorplan-container--active' : '';
					?>
					<div class="h3vt-tour__floorplan-container<?php echo $active_class; ?>" data-floorplan-index="<?php echo esc_attr( $fi ); ?>">
						<img class="h3vt-tour__floorplan-image"
							src="<?php echo esc_url( $fp_url ); ?>"
							alt="<?php echo esc_attr( $fp_label ); ?>">

						<?php if ( ! empty( $fp['floorplan_hotspots'] ) && is_array( $fp['floorplan_hotspots'] ) ) : ?>
							<?php foreach ( $fp['floorplan_hotspots'] as $hs ) : ?>
								<?php
								$hs_x      = isset( $hs['hotspot_x'] ) ? floatval( $hs['hotspot_x'] ) : 0;
								$hs_y      = isset( $hs['hotspot_y'] ) ? floatval( $hs['hotspot_y'] ) : 0;
								$hs_target = isset( $hs['hotspot_target_slide'] ) ? absint( $hs['hotspot_target_slide'] ) : 0;
								$hs_label  = isset( $hs['hotspot_label'] ) ? $hs['hotspot_label'] : '';
								?>
								<button class="h3vt-tour__hotspot"
									style="left:<?php echo esc_attr( $hs_x ); ?>%;top:<?php echo esc_attr( $hs_y ); ?>%"
									data-slide-index="<?php echo esc_attr( $hs_target ); ?>"
									aria-label="<?php echo esc_attr( $hs_label ); ?>">
									<span class="h3vt-tour__hotspot-dot"></span>
									<span class="h3vt-tour__hotspot-label"><?php echo esc_html( $hs_label ); ?></span>
								</button>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Contact modal with facility details and Google Maps embed.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_contact_modal( $data ) {
		$contact = $data['contact'];
		$logo    = $data['settings']['logo'];
		?>
		<div class="h3vt-tour__modal h3vt-tour__modal--contact" data-modal-name="contact" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Contact', 'h3vt-tours' ); ?>" hidden>
			<div class="h3vt-tour__modal-backdrop"></div>
			<div class="h3vt-tour__modal-content">
				<button class="h3vt-tour__modal-close" aria-label="<?php esc_attr_e( 'Close', 'h3vt-tours' ); ?>">&times;</button>

				<div class="h3vt-tour__contact-info">
					<?php if ( ! empty( $logo ) && is_array( $logo ) ) : ?>
						<img class="h3vt-tour__contact-logo"
							src="<?php echo esc_url( $logo['url'] ); ?>"
							alt="<?php echo esc_attr( $logo['alt'] ); ?>">
					<?php endif; ?>

					<?php if ( $contact['contact_facility_name'] ) : ?>
						<h3 class="h3vt-tour__contact-name"><?php echo esc_html( $contact['contact_facility_name'] ); ?></h3>
					<?php endif; ?>

					<?php if ( $contact['contact_address'] ) : ?>
						<p class="h3vt-tour__contact-address"><?php echo nl2br( esc_html( $contact['contact_address'] ) ); ?></p>
					<?php endif; ?>

					<?php if ( $contact['contact_email'] ) : ?>
						<p class="h3vt-tour__contact-email">
							<a href="mailto:<?php echo esc_attr( $contact['contact_email'] ); ?>">
								<?php echo esc_html( $contact['contact_email'] ); ?>
							</a>
						</p>
					<?php endif; ?>

					<?php if ( $contact['contact_phone'] ) : ?>
						<p class="h3vt-tour__contact-phone">
							<a href="tel:<?php echo esc_attr( $contact['contact_phone'] ); ?>">
								<?php echo esc_html( $contact['contact_phone'] ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<?php if ( $contact['google_maps_embed_url'] ) : ?>
					<div class="h3vt-tour__contact-map">
						<iframe
							src="<?php echo esc_url( $contact['google_maps_embed_url'] ); ?>"
							width="100%"
							height="300"
							style="border:0"
							allowfullscreen=""
							loading="lazy"
							referrerpolicy="no-referrer-when-downgrade"
							title="<?php esc_attr_e( 'Map', 'h3vt-tours' ); ?>"></iframe>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * 3D / embedded tour modal — iframe injected by JS on open.
	 *
	 * @param int   $index Index of this embedded tour.
	 * @param array $etour Embedded tour data.
	 */
	private static function render_3dtour_modal( $index, $etour ) {
		$label     = ! empty( $etour['tour_label'] ) ? $etour['tour_label'] : __( '3D Tour', 'h3vt-tours' );
		$embed_url = ! empty( $etour['tour_embed_url'] ) ? $etour['tour_embed_url'] : '';
		?>
		<div class="h3vt-tour__modal h3vt-tour__modal--3dtour" data-modal-name="3dtour-<?php echo esc_attr( $index ); ?>" data-tour-index="<?php echo esc_attr( $index ); ?>" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $label ); ?>" hidden>
			<div class="h3vt-tour__modal-backdrop"></div>
			<div class="h3vt-tour__modal-content">
				<button class="h3vt-tour__modal-close" aria-label="<?php esc_attr_e( 'Close', 'h3vt-tours' ); ?>">&times;</button>
				<div class="h3vt-tour__3dtour-container" data-embed-url="<?php echo esc_url( $embed_url ); ?>">
					<?php /* iframe injected by JS on open, removed on close */ ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Videos modal with a video area and button carousel.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_videos_modal( $data ) {
		?>
		<div class="h3vt-tour__modal h3vt-tour__modal--videos" data-modal-name="videos" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Videos', 'h3vt-tours' ); ?>" hidden>
			<div class="h3vt-tour__modal-backdrop"></div>
			<div class="h3vt-tour__modal-content">
				<button class="h3vt-tour__modal-close" aria-label="<?php esc_attr_e( 'Close', 'h3vt-tours' ); ?>">&times;</button>
				<div class="h3vt-tour__videos-player"></div>
				<div class="h3vt-tour__videos-carousel">
					<?php foreach ( $data['videos']['items'] as $vi => $video ) : ?>
						<?php
					$video_file = $video['video_url'];
					$video_url  = ( ! empty( $video_file ) && is_array( $video_file ) ) ? $video_file['url'] : '';
					?>
						<button class="h3vt-tour__videos-btn"
							data-index="<?php echo esc_attr( $vi ); ?>"
							data-video-url="<?php echo esc_url( $video_url ); ?>">
							<span><?php echo esc_html( $video['video_label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Single-video modal (used when slideshow mode is off).
	 *
	 * @param int   $index Index of this video in the items array.
	 * @param array $video Video item with video_label and video_url.
	 */
	private static function render_single_video_modal( $index, $video ) {
		$video_file = $video['video_url'];
		$video_url  = ( ! empty( $video_file ) && is_array( $video_file ) ) ? $video_file['url'] : '';
		$label      = ! empty( $video['video_label'] ) ? $video['video_label'] : __( 'Video', 'h3vt-tours' );
		?>
		<div class="h3vt-tour__modal h3vt-tour__modal--single-video"
			data-modal-name="video-<?php echo esc_attr( $index ); ?>"
			data-video-url="<?php echo esc_url( $video_url ); ?>"
			role="dialog" aria-modal="true"
			aria-label="<?php echo esc_attr( $label ); ?>" hidden>
			<div class="h3vt-tour__modal-backdrop"></div>
			<div class="h3vt-tour__modal-content">
				<button class="h3vt-tour__modal-close" aria-label="<?php esc_attr_e( 'Close', 'h3vt-tours' ); ?>">&times;</button>
				<div class="h3vt-tour__videos-player"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * PDF lightbox modal — iframe injected by JS on open.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_pdf_modal( $data ) {
		$pdf      = $data['settings']['pdf_file'];
		$pdf_url  = ( is_array( $pdf ) && ! empty( $pdf['url'] ) ) ? $pdf['url'] : '';
		$pdf_text = $data['settings']['pdf_button_text'];
		?>
		<div class="h3vt-tour__modal h3vt-tour__modal--pdf" data-modal-name="pdf" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $pdf_text ); ?>" hidden>
			<div class="h3vt-tour__modal-backdrop"></div>
			<div class="h3vt-tour__modal-content">
				<button class="h3vt-tour__modal-close" aria-label="<?php esc_attr_e( 'Close', 'h3vt-tours' ); ?>">&times;</button>
				<div class="h3vt-tour__pdf-container" data-pdf-url="<?php echo esc_url( $pdf_url ); ?>">
					<?php /* iframe injected by JS on open, removed on close */ ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Social media sidebar with platform icon links.
	 *
	 * @param array $data Tour data.
	 */
	private static function render_social_sidebar( $data ) {
		$socials = $data['settings']['socials'];
		if ( empty( $socials ) ) {
			return;
		}

		$icons = array(
			'facebook'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
			'instagram' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
			'youtube'   => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
			'linkedin'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
			'x'         => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932zM17.61 20.644h2.039L6.486 3.24H4.298z"/></svg>',
			'tiktok'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
			'pinterest' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641 0 12.017 0z"/></svg>',
		);
		?>
		<div class="h3vt-tour__social">
			<?php foreach ( $socials as $platform => $url ) :
				if ( empty( $url ) || ! isset( $icons[ $platform ] ) ) {
					continue;
				}
				$label = ucfirst( $platform );
				?>
				<a class="h3vt-tour__social-link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $label ); ?>">
					<?php echo $icons[ $platform ]; ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
