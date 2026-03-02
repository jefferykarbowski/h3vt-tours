<?php
/**
 * 3DVista tour archive converter.
 *
 * Extracts panorama images from a 3DVista .zip archive, uploads them
 * to the WordPress media library (producing real attachment IDs that
 * ACF validates natively), and creates a draft h3vt_tour post with
 * all ACF fields populated.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page and import pipeline for 3DVista tour archives.
 */
class H3VT_Tours_3DVista_Converter {

	/**
	 * Image extensions considered valid panorama files.
	 *
	 * @var array
	 */
	private $image_extensions = array( 'jpg', 'jpeg', 'png', 'webp' );

	/**
	 * Constructor — hooks admin menu and form handler.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_h3vt_3dvista_import', array( $this, 'handle_import' ) );
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
	}

	/**
	 * Register the "3DVista Import" submenu under H3VT Tours.
	 */
	public function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=h3vt_tour',
			__( '3DVista Import', 'h3vt-tours' ),
			__( '3DVista Import', 'h3vt-tours' ),
			'edit_posts',
			'h3vt-tours-3dvista-import',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the import form.
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$templates = get_posts( array(
			'post_type'      => 'h3vt_tour_template',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post_status'    => 'publish',
		) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import 3DVista Tour', 'h3vt-tours' ); ?></h1>

			<p><?php esc_html_e( 'Upload a 3DVista tour archive (.zip). Panorama images from the media/ folder will be imported into the WordPress media library and used as tour slides.', 'h3vt-tours' ); ?></p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'h3vt_3dvista_import', 'h3vt_3dvista_nonce' ); ?>
				<input type="hidden" name="action" value="h3vt_3dvista_import">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="h3vt_tour_name"><?php esc_html_e( 'Tour Name', 'h3vt-tours' ); ?></label>
						</th>
						<td>
							<input type="text" name="h3vt_tour_name" id="h3vt_tour_name" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'The title for the new tour post.', 'h3vt-tours' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="h3vt_template_id"><?php esc_html_e( 'Template', 'h3vt-tours' ); ?></label>
						</th>
						<td>
							<select name="h3vt_template_id" id="h3vt_template_id">
								<option value=""><?php esc_html_e( '&mdash; None &mdash;', 'h3vt-tours' ); ?></option>
								<?php foreach ( $templates as $tpl ) : ?>
									<option value="<?php echo esc_attr( $tpl->ID ); ?>">
										<?php echo esc_html( $tpl->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="h3vt_default_category"><?php esc_html_e( 'Default Category', 'h3vt-tours' ); ?></label>
						</th>
						<td>
							<input type="text" name="h3vt_default_category" id="h3vt_default_category" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Interior Views', 'h3vt-tours' ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. All slides will be assigned to this navigation category.', 'h3vt-tours' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="h3vt_zip_file"><?php esc_html_e( 'Archive (.zip)', 'h3vt-tours' ); ?></label>
						</th>
						<td>
							<input type="file" name="h3vt_zip_file" id="h3vt_zip_file" accept=".zip" required>
							<p class="description"><?php esc_html_e( '3DVista tour export archive. Images will be extracted from the media/ folder.', 'h3vt-tours' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Import Tour', 'h3vt-tours' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the import form submission.
	 */
	public function handle_import() {
		if ( ! check_admin_referer( 'h3vt_3dvista_import', 'h3vt_3dvista_nonce' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'h3vt-tours' ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to import tours.', 'h3vt-tours' ) );
		}

		// Validate file upload.
		if ( empty( $_FILES['h3vt_zip_file']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['h3vt_zip_file']['error'] ) {
			$this->add_admin_notice( __( 'No file uploaded or upload error occurred.', 'h3vt-tours' ), 'error' );
			$this->redirect_back();
			return;
		}

		$file_info = wp_check_filetype( $_FILES['h3vt_zip_file']['name'] );
		if ( 'zip' !== strtolower( pathinfo( $_FILES['h3vt_zip_file']['name'], PATHINFO_EXTENSION ) ) || empty( $file_info['type'] ) ) {
			$this->add_admin_notice( __( 'Please upload a valid .zip file.', 'h3vt-tours' ), 'error' );
			$this->redirect_back();
			return;
		}

		$tour_name = sanitize_text_field( wp_unslash( $_POST['h3vt_tour_name'] ?? '' ) );
		if ( empty( $tour_name ) ) {
			$this->add_admin_notice( __( 'Tour name is required.', 'h3vt-tours' ), 'error' );
			$this->redirect_back();
			return;
		}

		$template_id      = absint( $_POST['h3vt_template_id'] ?? 0 );
		$default_category = sanitize_text_field( wp_unslash( $_POST['h3vt_default_category'] ?? '' ) );

		// Extract zip.
		$tmp_name = $_FILES['h3vt_zip_file']['tmp_name'];
		$extract_dir = $this->extract_zip( $tmp_name );
		if ( is_wp_error( $extract_dir ) ) {
			$this->add_admin_notice( $extract_dir->get_error_message(), 'error' );
			$this->redirect_back();
			return;
		}

		// Parse tour title from index.htm for reference.
		$parsed_title = $this->parse_tour_title( $extract_dir );

		// Catalog panorama images.
		$images = $this->catalog_panoramas( $extract_dir );
		if ( empty( $images ) ) {
			$this->cleanup( $extract_dir );
			$this->add_admin_notice( __( 'No panorama images found in the archive media/ folder.', 'h3vt-tours' ), 'error' );
			$this->redirect_back();
			return;
		}

		// Require media handling functions.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// Create the tour post as a draft.
		$tour_id = wp_insert_post( array(
			'post_type'   => 'h3vt_tour',
			'post_title'  => $tour_name,
			'post_status' => 'draft',
		) );

		if ( is_wp_error( $tour_id ) ) {
			$this->cleanup( $extract_dir );
			$this->add_admin_notice(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to create tour post: %s', 'h3vt-tours' ),
					$tour_id->get_error_message()
				),
				'error'
			);
			$this->redirect_back();
			return;
		}

		// Upload images to media library.
		$attachment_ids = array();
		$upload_errors  = array();

		foreach ( $images as $image_path ) {
			$att_id = $this->upload_image_to_media_library( $image_path, $tour_id );
			if ( is_wp_error( $att_id ) ) {
				$upload_errors[] = basename( $image_path ) . ': ' . $att_id->get_error_message();
			} else {
				$attachment_ids[] = array(
					'id'       => $att_id,
					'filename' => basename( $image_path ),
				);
			}
		}

		if ( empty( $attachment_ids ) ) {
			wp_delete_post( $tour_id, true );
			$this->cleanup( $extract_dir );
			$this->add_admin_notice( __( 'All image uploads failed. Tour was not created.', 'h3vt-tours' ), 'error' );
			$this->redirect_back();
			return;
		}

		// Populate ACF fields.
		$this->populate_acf_fields( $tour_id, $attachment_ids, $template_id, $default_category );

		// Clean up.
		$this->cleanup( $extract_dir );

		// Build success message.
		$edit_link = get_edit_post_link( $tour_id, 'raw' );
		$message   = sprintf(
			/* translators: 1: slide count, 2: opening anchor tag, 3: closing anchor tag */
			__( 'Tour imported with %1$d slides. %2$sEdit tour%3$s', 'h3vt-tours' ),
			count( $attachment_ids ),
			'<a href="' . esc_url( $edit_link ) . '">',
			'</a>'
		);

		if ( ! empty( $parsed_title ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: parsed title from the 3DVista archive */
				__( '(Source: %s)', 'h3vt-tours' ),
				esc_html( $parsed_title )
			);
		}

		if ( ! empty( $upload_errors ) ) {
			$message .= '<br>' . sprintf(
				/* translators: %s: error details */
				__( 'Some images failed to upload: %s', 'h3vt-tours' ),
				esc_html( implode( '; ', $upload_errors ) )
			);
		}

		$this->add_admin_notice( $message, 'success' );
		$this->redirect_back();
	}

	/**
	 * Extract a zip archive to a temporary directory under uploads.
	 *
	 * @param string $zip_path Path to the uploaded zip file.
	 * @return string|WP_Error Extraction directory path or error.
	 */
	private function extract_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'ZipArchive extension is not available on this server.', 'h3vt-tours' ) );
		}

		$upload_dir = wp_upload_dir();
		$tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'h3vt-3dvista-tmp-' . wp_generate_password( 8, false );

		$zip = new ZipArchive();
		$res = $zip->open( $zip_path );

		if ( true !== $res ) {
			return new WP_Error( 'zip_open', __( 'Could not open the zip file.', 'h3vt-tours' ) );
		}

		// Scan for path traversal before extracting.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry_name = $zip->getNameIndex( $i );
			if ( false !== strpos( $entry_name, '..' ) || 0 === strpos( $entry_name, '/' ) || 0 === strpos( $entry_name, '\\' ) ) {
				$zip->close();
				return new WP_Error( 'zip_traversal', __( 'The zip archive contains unsafe paths and cannot be extracted.', 'h3vt-tours' ) );
			}
		}

		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			$zip->close();
			return new WP_Error( 'mkdir_fail', __( 'Could not create temporary extraction directory.', 'h3vt-tours' ) );
		}

		$zip->extractTo( $tmp_dir );
		$zip->close();

		return $tmp_dir;
	}

	/**
	 * Parse the <title> from the 3DVista index.htm file.
	 *
	 * @param string $extract_dir Extracted archive directory.
	 * @return string The parsed title, or empty string.
	 */
	private function parse_tour_title( $extract_dir ) {
		$index_file = $extract_dir . '/index.htm';
		if ( ! file_exists( $index_file ) ) {
			$index_file = $extract_dir . '/index.html';
		}

		if ( ! file_exists( $index_file ) ) {
			return '';
		}

		$html = file_get_contents( $index_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( preg_match( '/<title>([^<]+)<\/title>/i', $html, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Scan the media/ folder for full-resolution panorama images.
	 *
	 * Excludes thumbnail variants (files ending in _t before the extension).
	 *
	 * @param string $extract_dir Extracted archive directory.
	 * @return array List of absolute file paths.
	 */
	private function catalog_panoramas( $extract_dir ) {
		$media_dir = $extract_dir . '/media';
		if ( ! is_dir( $media_dir ) ) {
			return array();
		}

		$images = array();
		$iter   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $media_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, $this->image_extensions, true ) ) {
				continue;
			}

			$basename = $file->getBasename( '.' . $ext );

			// Skip thumbnails (filenames ending with _t).
			if ( '_t' === substr( $basename, -2 ) ) {
				continue;
			}

			$images[] = $file->getPathname();
		}

		// Sort for consistent ordering.
		sort( $images );

		return $images;
	}

	/**
	 * Upload a local image file to the WordPress media library.
	 *
	 * Uses media_handle_sideload() to produce a real attachment ID
	 * that ACF validates natively — this is the core fix for the
	 * "Image value is required" validation error.
	 *
	 * @param string $file_path Absolute path to the image file.
	 * @param int    $tour_id   Post ID to attach the image to.
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function upload_image_to_media_library( $file_path, $tour_id ) {
		$filename = basename( $file_path );

		// Copy to a temp file because media_handle_sideload() moves the file.
		$tmp_file = wp_tempnam( $filename );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		if ( ! copy( $file_path, $tmp_file ) ) {
			return new WP_Error( 'copy_fail', __( 'Could not copy image to temp location.', 'h3vt-tours' ) );
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$att_id = media_handle_sideload( $file_array, $tour_id, $this->filename_to_title( $filename ) );

		// Clean up temp file if sideload failed.
		if ( is_wp_error( $att_id ) && file_exists( $tmp_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tmp_file );
		}

		return $att_id;
	}

	/**
	 * Populate ACF fields on the tour post using real attachment IDs.
	 *
	 * Follows the same meta pattern as scripts/import-bath.php but stores
	 * integer attachment IDs instead of JSON objects with id:0, which is
	 * what makes ACF validation pass on save.
	 *
	 * @param int    $tour_id         The tour post ID.
	 * @param array  $attachment_ids  Array of [ 'id' => int, 'filename' => string ].
	 * @param int    $template_id     Template post ID (0 for none).
	 * @param string $default_category Default navigation category label.
	 */
	private function populate_acf_fields( $tour_id, $attachment_ids, $template_id, $default_category ) {
		// Template.
		if ( $template_id > 0 ) {
			update_field( 'tour_template', $template_id, $tour_id );
		}

		// Hero — use the first image.
		$first = $attachment_ids[0];
		update_field( 'hero_media_type', 'image', $tour_id );
		update_field( 'hero_image', $first['id'], $tour_id );

		// Navigation categories.
		if ( ! empty( $default_category ) ) {
			$categories = array(
				array(
					'nav_label' => $default_category,
					'nav_order' => 1,
				),
			);
			update_field( 'nav_categories', $categories, $tour_id );
		}

		// Slides repeater.
		$slide_count = count( $attachment_ids );
		update_post_meta( $tour_id, 'slides', $slide_count );
		update_post_meta( $tour_id, '_slides', 'field_h3vt_navigation_slides' );

		foreach ( $attachment_ids as $i => $att ) {
			$prefix = "slides_{$i}_";

			$title = $this->filename_to_title( $att['filename'] );

			update_post_meta( $tour_id, "{$prefix}slide_title", $title );
			update_post_meta( $tour_id, "_{$prefix}slide_title", 'field_h3vt_navigation_slide_title' );

			update_post_meta( $tour_id, "{$prefix}slide_description", '' );
			update_post_meta( $tour_id, "_{$prefix}slide_description", 'field_h3vt_navigation_slide_description' );

			if ( ! empty( $default_category ) ) {
				update_post_meta( $tour_id, "{$prefix}slide_nav_category", $default_category );
				update_post_meta( $tour_id, "_{$prefix}slide_nav_category", 'field_h3vt_navigation_slide_nav_category' );
			}

			// Store the real attachment ID — ACF validates this natively.
			update_post_meta( $tour_id, "{$prefix}slide_image", $att['id'] );
			update_post_meta( $tour_id, "_{$prefix}slide_image", 'field_h3vt_navigation_slide_image' );
		}
	}

	/**
	 * Convert a filename (often a UUID) to a human-readable title.
	 *
	 * Examples:
	 *   "a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg" → "A1b2c3d4 E5f6 7890 Abcd Ef1234567890"
	 *   "living-room-panorama.jpg" → "Living Room Panorama"
	 *   "01-core.jpg" → "01 Core"
	 *
	 * @param string $filename The filename (with or without extension).
	 * @return string Human-readable title.
	 */
	private function filename_to_title( $filename ) {
		$name = pathinfo( $filename, PATHINFO_FILENAME );
		$name = str_replace( array( '-', '_' ), ' ', $name );
		$name = ucwords( $name );
		return $name;
	}

	/**
	 * Recursively delete a temporary extraction directory.
	 *
	 * Safety: only deletes directories within the uploads folder.
	 *
	 * @param string $dir Directory to delete.
	 */
	private function cleanup( $dir ) {
		$upload_dir = wp_upload_dir();
		$base       = realpath( $upload_dir['basedir'] );
		$target     = realpath( $dir );

		// Safety check: only delete within uploads.
		if ( false === $target || false === $base || 0 !== strpos( $target, $base ) ) {
			return;
		}

		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iter as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	/**
	 * Store a transient-based admin notice for display after redirect.
	 *
	 * @param string $message The notice message (may contain HTML).
	 * @param string $type    Notice type: 'success', 'error', 'warning', 'info'.
	 */
	private function add_admin_notice( $message, $type = 'info' ) {
		$notices   = get_transient( 'h3vt_3dvista_notices' );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);
		set_transient( 'h3vt_3dvista_notices', $notices, 30 );
	}

	/**
	 * Display transient-based admin notices on the import page.
	 */
	public function display_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'h3vt_tour_page_h3vt-tours-3dvista-import' !== $screen->id ) {
			return;
		}

		$notices = get_transient( 'h3vt_3dvista_notices' );
		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}

		delete_transient( 'h3vt_3dvista_notices' );

		foreach ( $notices as $notice ) {
			$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				wp_kses_post( $notice['message'] )
			);
		}
	}

	/**
	 * Redirect back to the import page.
	 */
	private function redirect_back() {
		$url = admin_url( 'edit.php?post_type=h3vt_tour&page=h3vt-tours-3dvista-import' );
		wp_safe_redirect( $url );
		exit;
	}
}
