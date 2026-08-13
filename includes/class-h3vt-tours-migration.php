<?php
/**
 * One-time migration: slides repeater → per-category galleries.
 *
 * Converts the legacy `slides` repeater on each tour into per-category
 * gallery fields on the `nav_categories` repeater, copying slide titles
 * to attachment titles, descriptions to attachment captions, and
 * floorplan hotspot values to attachment fields.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the gallery migration once per site (admin visit) and exposes a
 * WP-CLI command for running it manually.
 */
class H3VT_Tours_Migration {

	/**
	 * Option flag set once the site-wide migration has completed.
	 */
	const OPTION = 'h3vt_tours_gallery_migration_done';

	/**
	 * Per-post meta flag set when a tour has been migrated.
	 */
	const POST_META = '_h3vt_gallery_migrated';

	/**
	 * Category label used for legacy slides without a matching category.
	 */
	const FALLBACK_LABEL = 'Uncategorized';

	/**
	 * Constructor — hooks the one-time run and the CLI command.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_run' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'h3vt-tours migrate-galleries', array( $this, 'cli_run' ) );
		}
	}

	/**
	 * Run the migration once per site.
	 */
	public function maybe_run() {
		if ( get_option( self::OPTION ) ) {
			return;
		}
		if ( ! function_exists( 'update_field' ) ) {
			return;
		}

		self::run();

		update_option( self::OPTION, H3VT_TOURS_VERSION );
	}

	/**
	 * WP-CLI handler: wp h3vt-tours migrate-galleries [--force]
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function cli_run( $args, $assoc_args ) {
		$force   = ! empty( $assoc_args['force'] );
		$results = self::run( $force );

		foreach ( $results as $post_id => $status ) {
			WP_CLI::log( sprintf( '#%d — %s', $post_id, $status ) );
		}

		update_option( self::OPTION, H3VT_TOURS_VERSION );
		WP_CLI::success( sprintf( 'Processed %d tours.', count( $results ) ) );
	}

	/**
	 * Migrate every tour post.
	 *
	 * @param bool $force Re-run even on tours already flagged as migrated.
	 * @return array Map of post ID => result string.
	 */
	public static function run( $force = false ) {
		$post_ids = get_posts( array(
			'post_type'      => 'h3vt_tour',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$results = array();
		foreach ( $post_ids as $post_id ) {
			$results[ $post_id ] = self::migrate_post( $post_id, $force );
		}

		return $results;
	}

	/**
	 * Migrate a single tour post.
	 *
	 * Reads the legacy repeater rows straight from raw meta (the old
	 * fields are no longer registered), groups slides by category, and
	 * rewrites nav_categories with per-category galleries. Legacy slide
	 * meta is left in place as a backup.
	 *
	 * @param int  $post_id Tour post ID.
	 * @param bool $force   Re-run even when already migrated.
	 * @return string Result: 'migrated', 'skipped', or 'no legacy slides'.
	 */
	public static function migrate_post( $post_id, $force = false ) {
		if ( ! $force && get_post_meta( $post_id, self::POST_META, true ) ) {
			return 'skipped';
		}

		// Already holding gallery data? Nothing to port.
		if ( ! $force && self::has_gallery_data( $post_id ) ) {
			update_post_meta( $post_id, self::POST_META, current_time( 'mysql' ) );
			return 'skipped';
		}

		$categories = self::get_legacy_categories( $post_id );
		$slides     = self::get_legacy_slides( $post_id );

		if ( empty( $slides ) && empty( $categories ) ) {
			update_post_meta( $post_id, self::POST_META, current_time( 'mysql' ) );
			return 'no legacy slides';
		}

		// Ordered label => attachment IDs map, seeded with the existing
		// categories so empty ones survive in their sorted order.
		$grouped = array();
		foreach ( $categories as $label ) {
			if ( ! isset( $grouped[ $label ] ) ) {
				$grouped[ $label ] = array();
			}
		}

		foreach ( $slides as $slide ) {
			$att_id = $slide['image_id'];
			if ( ! $att_id || 'attachment' !== get_post_type( $att_id ) ) {
				continue;
			}

			$label = '' !== $slide['category'] ? $slide['category'] : self::FALLBACK_LABEL;
			if ( ! isset( $grouped[ $label ] ) ) {
				$grouped[ $label ] = array();
			}
			$grouped[ $label ][] = $att_id;

			self::copy_slide_data_to_attachment( $slide );
		}

		$rows = array();
		foreach ( $grouped as $label => $ids ) {
			$rows[] = array(
				'nav_label'   => $label,
				'nav_gallery' => $ids,
			);
		}

		update_field( 'field_h3vt_navigation_nav_categories', $rows, $post_id );
		update_post_meta( $post_id, self::POST_META, current_time( 'mysql' ) );

		return sprintf( 'migrated (%d slides, %d categories)', count( $slides ), count( $rows ) );
	}

	/**
	 * Whether the post already stores images in the new gallery sub-field.
	 *
	 * @param int $post_id Tour post ID.
	 * @return bool
	 */
	private static function has_gallery_data( $post_id ) {
		$count = (int) get_post_meta( $post_id, 'nav_categories', true );
		for ( $i = 0; $i < $count; $i++ ) {
			$gallery = get_post_meta( $post_id, "nav_categories_{$i}_nav_gallery", true );
			if ( ! empty( $gallery ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Legacy nav_categories labels sorted by the old nav_order sub-field.
	 *
	 * @param int $post_id Tour post ID.
	 * @return array Ordered, de-duplicated labels.
	 */
	private static function get_legacy_categories( $post_id ) {
		$count = (int) get_post_meta( $post_id, 'nav_categories', true );
		$rows  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$label = get_post_meta( $post_id, "nav_categories_{$i}_nav_label", true );
			if ( '' === $label ) {
				continue;
			}
			$rows[] = array(
				'label'    => $label,
				'order'    => (int) get_post_meta( $post_id, "nav_categories_{$i}_nav_order", true ),
				'position' => $i,
			);
		}

		usort( $rows, function ( $a, $b ) {
			if ( $a['order'] === $b['order'] ) {
				return $a['position'] - $b['position'];
			}
			return $a['order'] - $b['order'];
		} );

		$labels = array();
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['label'], $labels, true ) ) {
				$labels[] = $row['label'];
			}
		}

		return $labels;
	}

	/**
	 * Legacy slides repeater rows from raw meta.
	 *
	 * @param int $post_id Tour post ID.
	 * @return array Rows of image_id/title/description/category/floorplan/x/y.
	 */
	private static function get_legacy_slides( $post_id ) {
		$count  = (int) get_post_meta( $post_id, 'slides', true );
		$slides = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$image_id = (int) get_post_meta( $post_id, "slides_{$i}_slide_image", true );
			if ( ! $image_id ) {
				continue;
			}
			$slides[] = array(
				'image_id'    => $image_id,
				'title'       => (string) get_post_meta( $post_id, "slides_{$i}_slide_title", true ),
				'description' => (string) get_post_meta( $post_id, "slides_{$i}_slide_description", true ),
				'category'    => (string) get_post_meta( $post_id, "slides_{$i}_slide_nav_category", true ),
				'floorplan'   => get_post_meta( $post_id, "slides_{$i}_slide_floorplan", true ),
				'hotspot_x'   => get_post_meta( $post_id, "slides_{$i}_slide_hotspot_x", true ),
				'hotspot_y'   => get_post_meta( $post_id, "slides_{$i}_slide_hotspot_y", true ),
			);
		}

		return $slides;
	}

	/**
	 * Copy a legacy slide's title, description, and hotspot placement
	 * onto its attachment so the gallery "Edit Image" form shows them.
	 *
	 * @param array $slide Legacy slide row (see get_legacy_slides).
	 */
	private static function copy_slide_data_to_attachment( $slide ) {
		$att_id = $slide['image_id'];

		$update = array( 'ID' => $att_id );
		if ( '' !== $slide['title'] ) {
			$update['post_title'] = $slide['title'];
		}
		if ( '' !== $slide['description'] ) {
			$update['post_excerpt'] = $slide['description'];
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		if ( '' !== $slide['floorplan'] && '' !== $slide['hotspot_x'] && '' !== $slide['hotspot_y'] ) {
			update_field( 'field_h3vt_attachment_slide_floorplan', $slide['floorplan'], $att_id );
			update_field( 'field_h3vt_attachment_slide_hotspot_x', $slide['hotspot_x'], $att_id );
			update_field( 'field_h3vt_attachment_slide_hotspot_y', $slide['hotspot_y'], $att_id );
		}
	}
}
