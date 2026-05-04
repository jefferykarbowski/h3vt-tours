<?php
/**
 * Media Library filter for tour attachments.
 *
 * Hides tour-specific uploads from the main Media Library grid,
 * routes uploads to per-tour subdirectories, and stamps new
 * attachments with identifying postmeta.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into Media Library queries, upload directory routing,
 * and attachment creation to isolate tour media.
 */
class H3VT_Tours_Media_Filter {

	/**
	 * Constructor — registers all hooks.
	 */
	public function __construct() {
		// Hide tour media from the Media Library grid/list (AJAX modal + admin list).
		add_filter( 'ajax_query_attachments_args', array( $this, 'hide_tour_media' ) );

		// Route uploads to per-tour subdirectories.
		add_filter( 'upload_dir', array( $this, 'tour_upload_dir' ) );

		// Stamp new attachments parented to a tour with identifying meta.
		add_action( 'add_attachment', array( $this, 'stamp_tour_attachment' ) );
	}

	/**
	 * Exclude tour-flagged attachments from the Media Library grid.
	 *
	 * Fires on the AJAX query that populates the media modal. Adds a
	 * meta_query that excludes any attachment with _h3vt_tour_media meta
	 * so the global library stays uncluttered.
	 *
	 * Skipped when the modal is opened from a tour or template edit
	 * screen — editors need to see (and reuse / edit) media that's
	 * already been uploaded for tours. Detected via the AJAX request's
	 * post_id, then via the HTTP referer as a fallback.
	 *
	 * @param array $query WP_Query args for the attachment query.
	 * @return array Modified query args.
	 */
	public function hide_tour_media( $query ) {
		if ( $this->is_tour_edit_context() ) {
			return $query;
		}

		$meta_query = isset( $query['meta_query'] ) ? $query['meta_query'] : array();

		$meta_query[] = array(
			'key'     => '_h3vt_tour_media',
			'compare' => 'NOT EXISTS',
		);

		$query['meta_query'] = $meta_query;

		return $query;
	}

	/**
	 * Detect whether the current request is being made from a tour or
	 * template edit screen (the media modal opens from there).
	 *
	 * @return bool
	 */
	private function is_tour_edit_context() {
		$tour_types = array( 'h3vt_tour', 'h3vt_tour_template' );
		$post_id    = 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['post_id'] ) ) {
			$post_id = absint( $_REQUEST['post_id'] );
		}

		if ( ! $post_id && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = wp_parse_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! empty( $referer['query'] ) ) {
				$args = array();
				parse_str( $referer['query'], $args );

				if ( ! empty( $args['post'] ) ) {
					$post_id = absint( $args['post'] );
				} elseif ( ! empty( $args['post_type'] ) && in_array( $args['post_type'], $tour_types, true ) ) {
					// post-new.php?post_type=h3vt_tour — no auto-draft ID yet, but it's still tour context.
					return true;
				}
			}
		}

		if ( ! $post_id ) {
			return false;
		}

		return in_array( get_post_type( $post_id ), $tour_types, true );
	}

	/**
	 * Route uploads to a per-tour subdirectory when uploading to a tour post.
	 *
	 * When $_REQUEST['post_id'] points to an h3vt_tour post, overrides the
	 * default date-based upload path to wp-content/uploads/h3vt-tours/{post_id}/.
	 *
	 * @param array $uploads Upload directory data from wp_upload_dir().
	 * @return array Modified upload directory data.
	 */
	public function tour_upload_dir( $uploads ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;

		if ( ! $post_id ) {
			return $uploads;
		}

		if ( 'h3vt_tour' !== get_post_type( $post_id ) ) {
			return $uploads;
		}

		$subdir = '/h3vt-tours/' . $post_id;

		$uploads['subdir'] = $subdir;
		$uploads['path']   = $uploads['basedir'] . $subdir;
		$uploads['url']    = $uploads['baseurl'] . $subdir;

		return $uploads;
	}

	/**
	 * Stamp new attachments with tour media meta when parented to a tour.
	 *
	 * Fires immediately after a new attachment is inserted. If the
	 * attachment's post_parent is an h3vt_tour post, adds the
	 * _h3vt_tour_media flag so the Media Library filter can exclude it.
	 *
	 * @param int $attachment_id The newly created attachment ID.
	 */
	public function stamp_tour_attachment( $attachment_id ) {
		$parent_id = wp_get_post_parent_id( $attachment_id );

		if ( ! $parent_id ) {
			return;
		}

		if ( 'h3vt_tour' !== get_post_type( $parent_id ) ) {
			return;
		}

		update_post_meta( $attachment_id, '_h3vt_tour_media', '1' );
	}
}
