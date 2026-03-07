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
			$primary_color   = get_field( 'primary_color', $template_id ) ?: '#FF6B00';
			$secondary_color = get_field( 'secondary_color', $template_id ) ?: '#1A1A1A';
			$text_color      = get_field( 'text_color', $template_id ) ?: '#FFFFFF';
			$hover_color     = get_field( 'hover_color', $template_id ) ?: '';
			$logo            = get_field( 'logo', $template_id );
			$icon            = get_field( 'icon', $template_id );
			$button_style    = get_field( 'button_style', $template_id ) ?: 'text';
			$autoplay_speed  = get_field( 'autoplay_speed', $template_id ) ?: 8;
			$theme           = get_field( 'theme', $template_id ) ?: 'classic';
		} else {
			$primary_color   = '#FF6B00';
			$secondary_color = '#1A1A1A';
			$text_color      = '#FFFFFF';
			$hover_color     = '';
			$logo            = null;
			$icon            = null;
			$button_style    = 'text';
			$autoplay_speed  = 8;
			$theme           = 'classic';
		}

		/*
		 * Settings.
		 */
		$settings = array(
			'primary_color'    => $primary_color,
			'secondary_color'  => $secondary_color,
			'text_color'       => $text_color,
			'hover_color'      => $hover_color,
			'logo'             => $logo,
			'icon'             => $icon,
			'hero_image'       => get_field( 'hero_image', $post_id ),
			'hero_media_type'  => get_field( 'hero_media_type', $post_id ) ?: 'image',
			'hero_video'       => get_field( 'hero_video', $post_id ),
			'hero_title'       => get_field( 'hero_title', $post_id ) ?: '',
			'hero_description' => get_field( 'hero_description', $post_id ) ?: '',
			'button_style'     => $button_style,
			'autoplay_speed'   => $autoplay_speed,
			'theme'            => $theme,
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

		$button_bg = esc_attr( $data['settings']['primary_color'] );
		$header_bg = esc_attr( $data['settings']['secondary_color'] );
		$text      = esc_attr( $data['settings']['text_color'] );
		$hover     = esc_attr( $data['settings']['hover_color'] );
		$speed_ms  = absint( $data['settings']['autoplay_speed'] ) * 1000;

		$hover_css   = $hover ? ';--h3vt-hover:' . $hover : '';
		$theme       = $data['settings']['theme'];
		$theme_class = 'classic' !== $theme ? ' h3vt-tour--theme-' . esc_attr( $theme ) : '';

		ob_start();

		echo H3VT_Tours_Theme_Loader::get_head_markup( $theme );
		?>
		<div class="h3vt-tour<?php echo $theme_class; ?>"
			style="--h3vt-button-bg:<?php echo $button_bg; ?>;--h3vt-header-bg:<?php echo $header_bg; ?>;--h3vt-text:<?php echo $text; ?><?php echo $hover_css; ?>"
			data-autoplay-speed="<?php echo esc_attr( $speed_ms ); ?>">
			<?php
			self::render_header( $data );
			self::render_slides( $data );
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
}
