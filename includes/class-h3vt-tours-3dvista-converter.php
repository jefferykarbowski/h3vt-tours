<?php
/**
 * 3DVista tour archive converter.
 *
 * Extracts panorama images from a 3DVista .zip archive, uploads them
 * to the WordPress media library (producing real attachment IDs that
 * ACF validates natively), and creates a draft h3vt_tour post with
 * all ACF fields populated.
 *
 * Image uploads are handled individually via AJAX to avoid server
 * timeout (503) errors with large panorama files.
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
	 * Transient key prefix for import jobs.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'h3vt_3dvista_job_';

	/**
	 * Image extensions considered valid panorama files.
	 *
	 * @var array
	 */
	private $image_extensions = array( 'jpg', 'jpeg', 'png', 'webp' );

	/**
	 * Constructor — hooks admin menu, form handler, and AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_h3vt_3dvista_import', array( $this, 'handle_import' ) );
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );

		// AJAX endpoints for chunked image upload.
		add_action( 'wp_ajax_h3vt_3dvista_upload_image', array( $this, 'ajax_upload_image' ) );
		add_action( 'wp_ajax_h3vt_3dvista_finalize', array( $this, 'ajax_finalize' ) );
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
	 * Render the import form or progress UI.
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Check for an active import job via query param.
		$job_id = isset( $_GET['job'] ) ? sanitize_key( $_GET['job'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $job_id ) {
			$job = get_transient( self::TRANSIENT_PREFIX . $job_id );
			if ( $job ) {
				$this->render_progress_page( $job_id, $job );
				return;
			}
		}

		$this->render_upload_form();
	}

	/**
	 * Render the upload form.
	 */
	private function render_upload_form() {
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
	 * Render the progress page with AJAX-driven image uploads.
	 *
	 * @param string $job_id The import job ID.
	 * @param array  $job    The import job data.
	 */
	private function render_progress_page( $job_id, $job ) {
		$total  = count( $job['images'] );
		$nonce  = wp_create_nonce( 'h3vt_3dvista_ajax' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Importing Tour...', 'h3vt-tours' ); ?></h1>

			<div id="h3vt-import-progress">
				<p id="h3vt-import-status">
					<?php
					printf(
						/* translators: 1: current count, 2: total count */
						esc_html__( 'Uploading image %1$d of %2$d...', 'h3vt-tours' ),
						1,
						$total
					);
					?>
				</p>

				<div style="background:#ddd;border-radius:4px;overflow:hidden;height:30px;max-width:600px;margin:16px 0;">
					<div id="h3vt-import-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;border-radius:4px;"></div>
				</div>

				<p id="h3vt-import-detail" style="color:#666;font-style:italic;"></p>
			</div>

			<div id="h3vt-import-result" style="display:none;"></div>
		</div>

		<script>
		(function() {
			var jobId    = <?php echo wp_json_encode( $job_id ); ?>;
			var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
			var total    = <?php echo intval( $total ); ?>;
			var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var current  = 0;
			var errors   = [];

			var statusEl = document.getElementById('h3vt-import-status');
			var barEl    = document.getElementById('h3vt-import-bar');
			var detailEl = document.getElementById('h3vt-import-detail');
			var resultEl = document.getElementById('h3vt-import-result');
			var progressEl = document.getElementById('h3vt-import-progress');

			function uploadNext() {
				if (current >= total) {
					finalize();
					return;
				}

				var num = current + 1;
				statusEl.textContent = <?php echo wp_json_encode( __( 'Uploading image ', 'h3vt-tours' ) ); ?> + num + <?php echo wp_json_encode( __( ' of ', 'h3vt-tours' ) ); ?> + total + '...';
				barEl.style.width = Math.round((num / (total + 1)) * 100) + '%';

				var data = new FormData();
				data.append('action', 'h3vt_3dvista_upload_image');
				data.append('_ajax_nonce', nonce);
				data.append('job_id', jobId);
				data.append('index', current);

				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxUrl);
				xhr.onload = function() {
					try {
						var resp = JSON.parse(xhr.responseText);
						if (resp.success) {
							detailEl.textContent = resp.data.filename || '';
						} else {
							var msg = (resp.data && resp.data.message) ? resp.data.message : 'Unknown error';
							errors.push(msg);
							detailEl.textContent = msg;
						}
					} catch(e) {
						errors.push('Unexpected response for image ' + num);
					}
					current++;
					uploadNext();
				};
				xhr.onerror = function() {
					errors.push('Network error uploading image ' + num);
					current++;
					uploadNext();
				};
				xhr.send(data);
			}

			function finalize() {
				statusEl.textContent = <?php echo wp_json_encode( __( 'Finalizing tour...', 'h3vt-tours' ) ); ?>;
				barEl.style.width = '95%';
				detailEl.textContent = '';

				var data = new FormData();
				data.append('action', 'h3vt_3dvista_finalize');
				data.append('_ajax_nonce', nonce);
				data.append('job_id', jobId);

				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxUrl);
				xhr.onload = function() {
					barEl.style.width = '100%';
					progressEl.style.display = 'none';
					resultEl.style.display = '';

					try {
						var resp = JSON.parse(xhr.responseText);
						if (resp.success) {
							var html = '<div class="notice notice-success"><p>' + resp.data.message + '</p></div>';
							if (errors.length > 0) {
								html += '<div class="notice notice-warning"><p>' + <?php echo wp_json_encode( __( 'Some images failed to upload: ', 'h3vt-tours' ) ); ?> + errors.join('; ') + '</p></div>';
							}
							resultEl.innerHTML = html;
						} else {
							resultEl.innerHTML = '<div class="notice notice-error"><p>' + ((resp.data && resp.data.message) || 'Finalization failed.') + '</p></div>';
						}
					} catch(e) {
						resultEl.innerHTML = '<div class="notice notice-error"><p>Unexpected response during finalization.</p></div>';
					}
				};
				xhr.onerror = function() {
					progressEl.style.display = 'none';
					resultEl.style.display = '';
					resultEl.innerHTML = '<div class="notice notice-error"><p>Network error during finalization.</p></div>';
				};
				xhr.send(data);
			}

			uploadNext();
		})();
		</script>
		<?php
	}

	/**
	 * Handle the import form submission.
	 *
	 * Validates input, extracts the zip, catalogs images, creates the
	 * draft tour post, then redirects to the progress page where AJAX
	 * handles the actual image uploads one at a time.
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
		$extract_dir = $this->extract_zip( $_FILES['h3vt_zip_file']['tmp_name'] );
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

		// Store job state in a transient for AJAX processing.
		$job_id = wp_generate_password( 12, false );
		$job    = array(
			'tour_id'          => $tour_id,
			'extract_dir'      => $extract_dir,
			'images'           => $images,
			'template_id'      => $template_id,
			'default_category' => $default_category,
			'parsed_title'     => $parsed_title,
			'attachment_ids'   => array(),
		);

		// 1-hour expiry — plenty of time for large imports.
		set_transient( self::TRANSIENT_PREFIX . $job_id, $job, HOUR_IN_SECONDS );

		// Redirect to progress page.
		$url = admin_url( 'edit.php?post_type=h3vt_tour&page=h3vt-tours-3dvista-import&job=' . $job_id );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * AJAX handler: upload a single image from the extracted archive.
	 *
	 * Expects POST params: job_id, index.
	 */
	public function ajax_upload_image() {
		check_ajax_referer( 'h3vt_3dvista_ajax' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'h3vt-tours' ) ) );
		}

		$job_id = sanitize_key( $_POST['job_id'] ?? '' );
		$index  = absint( $_POST['index'] ?? 0 );

		$job = get_transient( self::TRANSIENT_PREFIX . $job_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Import job expired or not found.', 'h3vt-tours' ) ) );
		}

		if ( ! isset( $job['images'][ $index ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Image index out of range.', 'h3vt-tours' ) ) );
		}

		// Require media handling functions.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$image_path = $job['images'][ $index ];

		// Set post_id in request so the upload_dir filter routes to the tour subdirectory.
		$_REQUEST['post_id'] = $job['tour_id'];

		$att_id = $this->upload_image_to_media_library( $image_path, $job['tour_id'] );

		if ( is_wp_error( $att_id ) ) {
			wp_send_json_error( array(
				'message'  => basename( $image_path ) . ': ' . $att_id->get_error_message(),
				'filename' => basename( $image_path ),
			) );
		}

		// Belt-and-suspenders: ensure the tour media flag is set even if
		// the add_attachment hook didn't fire (e.g. post_parent timing).
		update_post_meta( $att_id, '_h3vt_tour_media', '1' );

		// Store attachment ID in the job transient.
		$job['attachment_ids'][ $index ] = array(
			'id'       => $att_id,
			'filename' => basename( $image_path ),
		);
		set_transient( self::TRANSIENT_PREFIX . $job_id, $job, HOUR_IN_SECONDS );

		wp_send_json_success( array(
			'attachment_id' => $att_id,
			'filename'      => basename( $image_path ),
			'index'         => $index,
		) );
	}

	/**
	 * AJAX handler: finalize the import — populate ACF fields and clean up.
	 *
	 * Expects POST param: job_id.
	 */
	public function ajax_finalize() {
		check_ajax_referer( 'h3vt_3dvista_ajax' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'h3vt-tours' ) ) );
		}

		$job_id = sanitize_key( $_POST['job_id'] ?? '' );
		$job    = get_transient( self::TRANSIENT_PREFIX . $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Import job expired or not found.', 'h3vt-tours' ) ) );
		}

		// Collect successfully uploaded attachments (preserving order).
		$attachment_ids = array();
		foreach ( $job['images'] as $i => $path ) {
			if ( isset( $job['attachment_ids'][ $i ] ) ) {
				$attachment_ids[] = $job['attachment_ids'][ $i ];
			}
		}

		$tour_id = $job['tour_id'];

		if ( empty( $attachment_ids ) ) {
			wp_delete_post( $tour_id, true );
			$this->cleanup( $job['extract_dir'] );
			delete_transient( self::TRANSIENT_PREFIX . $job_id );
			wp_send_json_error( array( 'message' => __( 'All image uploads failed. Tour was not created.', 'h3vt-tours' ) ) );
		}

		// Populate ACF fields.
		$this->populate_acf_fields( $tour_id, $attachment_ids, $job['template_id'], $job['default_category'] );

		// Clean up temp directory and transient.
		$this->cleanup( $job['extract_dir'] );
		delete_transient( self::TRANSIENT_PREFIX . $job_id );

		// Build success message.
		$edit_link = get_edit_post_link( $tour_id, 'raw' );
		$message   = sprintf(
			/* translators: 1: slide count, 2: opening anchor tag, 3: closing anchor tag */
			__( 'Tour imported with %1$d slides. %2$sEdit tour &rarr;%3$s', 'h3vt-tours' ),
			count( $attachment_ids ),
			'<a href="' . esc_url( $edit_link ) . '">',
			'</a>'
		);

		if ( ! empty( $job['parsed_title'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: parsed title from the 3DVista archive */
				__( '(Source: %s)', 'h3vt-tours' ),
				esc_html( $job['parsed_title'] )
			);
		}

		wp_send_json_success( array( 'message' => $message ) );
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
		if ( empty( $dir ) || ! is_dir( $dir ) ) {
			return;
		}

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
	 * Redirect back to the import form page.
	 */
	private function redirect_back() {
		$url = admin_url( 'edit.php?post_type=h3vt_tour&page=h3vt-tours-3dvista-import' );
		wp_safe_redirect( $url );
		exit;
	}
}
