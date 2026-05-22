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

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php?action=h3vt_3dvista_import' ) ); ?>">
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

			<p class="description">
				<?php
				printf(
					/* translators: 1: upload_max_filesize value, 2: post_max_size value */
					esc_html__( 'Server upload limit: %1$s (upload_max_filesize) / %2$s (post_max_size).', 'h3vt-tours' ),
					esc_html( ini_get( 'upload_max_filesize' ) ),
					esc_html( ini_get( 'post_max_size' ) )
				);
				?>
			</p>
		</div>

		<script>
		(function() {
			var maxBytes = <?php echo (int) wp_max_upload_size(); ?>;
			var fileInput = document.getElementById('h3vt_zip_file');
			if (fileInput) {
				fileInput.addEventListener('change', function() {
					if (this.files[0] && this.files[0].size > maxBytes) {
						var sizeMB = (this.files[0].size / 1024 / 1024).toFixed(1);
						var limitMB = (maxBytes / 1024 / 1024).toFixed(0);
						alert(<?php echo wp_json_encode( __( 'This file is ', 'h3vt-tours' ) ); ?> + sizeMB + 'MB but the server only allows ' + limitMB + 'MB. The upload will fail. Please ask your host to increase the PHP upload limits.');
					}
				});
			}
		})();
		</script>
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
		// Extend limits for large archive processing (ZIP extraction + metadata parsing).
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_raise_memory_limit( 'admin' );

		// Register a shutdown handler so fatal errors reach debug.log.
		register_shutdown_function( array( $this, 'log_fatal_error' ) );

		// Detect when the upload exceeds PHP's post_max_size.
		// PHP silently discards all POST data (including the nonce and file),
		// which causes a white screen because admin-post.php has no action
		// to dispatch. Check before the nonce verification.
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 0 && empty( $_POST ) && empty( $_FILES ) ) {
			$post_max  = ini_get( 'post_max_size' );
			$upload_max = ini_get( 'upload_max_filesize' );
			wp_die(
				sprintf(
					/* translators: 1: post_max_size value, 2: upload_max_filesize value */
					esc_html__( 'The uploaded file exceeds the server size limit. Your server allows uploads up to %1$s (post_max_size) / %2$s (upload_max_filesize). Please ask your host to increase these PHP limits, or use a smaller archive.', 'h3vt-tours' ),
					esc_html( $post_max ),
					esc_html( $upload_max )
				),
				esc_html__( 'Upload Too Large', 'h3vt-tours' ),
				array( 'back_link' => true )
			);
		}

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

		// Parse 3DVista metadata (titles, descriptions, categories, etc.).
		$metadata = $this->parse_3dvista_metadata( $extract_dir );

		// Catalog panorama images (excludes thumbnails and floorplan images).
		$images = $this->catalog_panoramas( $extract_dir );
		if ( empty( $images ) ) {
			$this->cleanup( $extract_dir );
			$this->add_admin_notice( __( 'No panorama images found in the archive media/ folder.', 'h3vt-tours' ), 'error' );
			$this->redirect_back();
			return;
		}

		// Reorder panoramas to match the 3DVista presentation order.
		$images = $this->order_images_by_metadata( $images, $metadata );

		// Catalog floorplan and testimonial files separately.
		$floorplan_images  = $this->catalog_floorplan_images( $extract_dir, $metadata );
		$testimonial_items = $this->catalog_testimonial_files( $extract_dir, $metadata );

		// Build a single upload queue with a parallel metadata array that
		// records the role of each file (panorama / floorplan / testimonial
		// video / testimonial thumbnail). The finalize step uses this to
		// route each uploaded attachment to the correct ACF field.
		$all_images = array();
		$image_meta = array();

		foreach ( $images as $path ) {
			$all_images[] = $path;
			$image_meta[] = array( 'role' => 'panorama' );
		}
		foreach ( $floorplan_images as $fp_index => $path ) {
			$all_images[] = $path;
			$image_meta[] = array( 'role' => 'floorplan', 'index' => $fp_index );
		}
		foreach ( $testimonial_items as $item ) {
			$all_images[] = $item['path'];
			$image_meta[] = $item['meta'];
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
			'images'           => $all_images,
			'image_meta'       => $image_meta,
			'template_id'      => $template_id,
			'default_category' => $default_category,
			'parsed_title'     => $parsed_title,
			'attachment_ids'   => array(),
			'metadata'         => $metadata,
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
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		register_shutdown_function( array( $this, 'log_fatal_error' ) );

		try {
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
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => 'Upload exception: ' . $e->getMessage() ) );
		} catch ( \Error $e ) {
			wp_send_json_error( array( 'message' => 'Upload error: ' . $e->getMessage() ) );
		}
	}

	/**
	 * AJAX handler: finalize the import — populate ACF fields and clean up.
	 *
	 * Expects POST param: job_id.
	 */
	public function ajax_finalize() {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		register_shutdown_function( array( $this, 'log_fatal_error' ) );

		try {
			check_ajax_referer( 'h3vt_3dvista_ajax' );

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'h3vt-tours' ) ) );
			}

			$job_id = sanitize_key( $_POST['job_id'] ?? '' );
			$job    = get_transient( self::TRANSIENT_PREFIX . $job_id );

			if ( ! $job ) {
				wp_send_json_error( array( 'message' => __( 'Import job expired or not found.', 'h3vt-tours' ) ) );
			}

			$tour_id = $job['tour_id'];

			if ( empty( $job['attachment_ids'] ) ) {
				wp_delete_post( $tour_id, true );
				$this->cleanup( $job['extract_dir'] );
				delete_transient( self::TRANSIENT_PREFIX . $job_id );
				wp_send_json_error( array( 'message' => __( 'All image uploads failed. Tour was not created.', 'h3vt-tours' ) ) );
			}

			// Partition uploaded attachments by their queued role. Using the
			// per-file metadata (rather than count-based slicing) keeps the
			// buckets correct even when individual uploads fail.
			$panorama_atts      = array();
			$floorplan_atts     = array();
			$testimonial_videos = array();
			$testimonial_thumbs = array();

			foreach ( $job['images'] as $i => $path ) {
				if ( ! isset( $job['attachment_ids'][ $i ] ) ) {
					continue;
				}

				$att  = $job['attachment_ids'][ $i ];
				$meta = isset( $job['image_meta'][ $i ] ) ? $job['image_meta'][ $i ] : array();
				$role = isset( $meta['role'] ) ? $meta['role'] : 'panorama';

				switch ( $role ) {
					case 'floorplan':
						$floorplan_atts[ $meta['index'] ] = $att;
						break;
					case 'testimonial_video':
						$testimonial_videos[ $meta['index'] ] = $att;
						break;
					case 'testimonial_thumb':
						$testimonial_thumbs[ $meta['index'] ] = $att;
						break;
					default:
						$panorama_atts[] = $att;
				}
			}

			$metadata = isset( $job['metadata'] ) ? $job['metadata'] : array();

			// Populate ACF fields with metadata.
			$this->populate_acf_fields( $tour_id, $panorama_atts, $job['template_id'], $job['default_category'], $metadata, $floorplan_atts, $testimonial_videos, $testimonial_thumbs );

			// Clean up temp directory and transient.
			$this->cleanup( $job['extract_dir'] );
			delete_transient( self::TRANSIENT_PREFIX . $job_id );

			// Build success message.
			$edit_link = get_edit_post_link( $tour_id, 'raw' );
			$message   = sprintf(
				/* translators: 1: slide count, 2: opening anchor tag, 3: closing anchor tag */
				__( 'Tour imported with %1$d slides. %2$sEdit tour &rarr;%3$s', 'h3vt-tours' ),
				count( $panorama_atts ),
				'<a href="' . esc_url( $edit_link ) . '">',
				'</a>'
			);

			$testimonial_total = count( $testimonial_videos );
			if ( $testimonial_total > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: number of testimonial videos */
					_n( '%d testimonial video imported.', '%d testimonial videos imported.', $testimonial_total, 'h3vt-tours' ),
					$testimonial_total
				);
			}

			if ( ! empty( $job['parsed_title'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: parsed title from the 3DVista archive */
					__( '(Source: %s)', 'h3vt-tours' ),
					esc_html( $job['parsed_title'] )
				);
			}

			wp_send_json_success( array( 'message' => $message ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => 'Finalization exception: ' . $e->getMessage() ) );
		} catch ( \Error $e ) {
			wp_send_json_error( array( 'message' => 'Finalization error: ' . $e->getMessage() ) );
		}
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

		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
	 * Parse the locale/en.txt translation file from a 3DVista archive.
	 *
	 * The file uses key = value format with # comments, ## section headers,
	 * and trailing backslash line continuations.
	 *
	 * @param string $extract_dir Extracted archive directory.
	 * @return array Associative array of translation key => value.
	 */
	private function parse_locale( $extract_dir ) {
		$locale_file = $extract_dir . '/locale/en.txt';
		if ( ! file_exists( $locale_file ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $locale_file );
		if ( false === $raw ) {
			return array();
		}

		$translations = array();
		$lines        = explode( "\n", $raw );
		$pending_key  = '';
		$pending_val  = '';

		foreach ( $lines as $line ) {
			// Handle line continuation from previous line.
			if ( '' !== $pending_key ) {
				$trimmed = rtrim( $line );
				if ( '\\' === substr( $trimmed, -1 ) ) {
					$pending_val .= ' ' . trim( substr( $trimmed, 0, -1 ) );
					continue;
				}
				$pending_val .= ' ' . trim( $trimmed );
				$translations[ $pending_key ] = trim( $pending_val );
				$pending_key = '';
				$pending_val = '';
				continue;
			}

			$line = rtrim( $line );

			// Skip empty lines, comments, and section headers.
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}

			// Parse key = value.
			$eq_pos = strpos( $line, '=' );
			if ( false === $eq_pos ) {
				continue;
			}

			$key   = trim( substr( $line, 0, $eq_pos ) );
			$value = ltrim( substr( $line, $eq_pos + 1 ) );

			// Check for line continuation (trailing backslash).
			$value_trimmed = rtrim( $value );
			if ( '\\' === substr( $value_trimmed, -1 ) ) {
				$pending_key = $key;
				$pending_val = substr( $value_trimmed, 0, -1 );
				continue;
			}

			$translations[ $key ] = trim( $value );
		}

		// Flush any remaining continuation.
		if ( '' !== $pending_key ) {
			$translations[ $pending_key ] = trim( $pending_val );
		}

		return $translations;
	}

	/**
	 * Parse 3DVista metadata from script_general.js and locale files.
	 *
	 * Returns structured metadata including slide titles, descriptions,
	 * navigation categories, floorplans, and embedded tour URLs.
	 *
	 * @param string $extract_dir Extracted archive directory.
	 * @return array {
	 *     @type array  $photos                  Keyed by image filename => { title, description, category }.
	 *     @type array  $nav_categories           Unique category labels in presentation order.
	 *     @type array  $main_playlist_filenames  Ordered image filenames from mainPlayList.
	 *     @type array  $floorplans               Array of { label, image_file, map_id }.
	 *     @type array  $embedded_tours            Array of { label, url }.
	 *     @type array  $testimonials             Array of { role, video_file, thumb_file }.
	 *     @type array  $contact                  { facility_name, address, email, phone, maps_embed_url }.
	 * }
	 */
	private function parse_3dvista_metadata( $extract_dir ) {
		$metadata = array(
			'photos'                 => array(),
			'nav_categories'         => array(),
			'main_playlist_filenames' => array(),
			'floorplans'             => array(),
			'embedded_tours'         => array(),
			'testimonials'           => array(),
			'contact'                => array(),
		);

		$script_file = $extract_dir . '/script_general.js';
		if ( ! file_exists( $script_file ) ) {
			return $metadata;
		}

		// Guard against excessively large script files exhausting memory.
		$file_size = filesize( $script_file );
		if ( false === $file_size || $file_size > 50 * 1024 * 1024 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[H3VT Tours] script_general.js too large to parse: ' . size_format( $file_size ) );
			return $metadata;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $script_file );
		if ( false === $content ) {
			return $metadata;
		}

		$locale = $this->parse_locale( $extract_dir );

		// 1. Extract panorama photos from their media URL references.
		//
		// Photo objects are emitted with inconsistent field ordering across
		// 3DVista export versions — sometimes "id" precedes "class":"Photo",
		// sometimes the reverse — so matching the Photo object itself is
		// unreliable and silently yields zero photos on newer exports.
		// Every panorama photo is, however, referenced by a media URL of the
		// form media/album_XXX_0.<ext> where album_XXX_0 is the photo ID, so
		// keying off that URL works regardless of object field order.
		$photo_map = array(); // photo_id => { filename, title }
		if ( preg_match_all(
			'/"url"\s*:\s*"media\/((album_[A-Za-z0-9_]+_0)\.(?:jpg|jpeg|png|webp))"/i',
			$content,
			$url_matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $url_matches as $url_match ) {
				$filename = $url_match[1]; // album_XXX_0.jpg
				$photo_id = $url_match[2]; // album_XXX_0

				// Multiple resolution levels can reference the same file.
				if ( isset( $photo_map[ $photo_id ] ) ) {
					continue;
				}

				// Get title from the photo-level locale label.
				$title     = '';
				$label_key = $photo_id . '.label';
				if ( isset( $locale[ $label_key ] ) ) {
					$title = $locale[ $label_key ];
				}

				$photo_map[ $photo_id ] = array(
					'filename' => $filename,
					'title'    => $title,
				);
			}
		}

		// 2. Build album map from locale translations.
		// Locale keys like "album_XXX.label" and "album_XXX.subtitle"
		// identify albums without needing to regex-match PhotoAlbum objects
		// (which have nested braces that break simple regex patterns).
		$album_map = array(); // album_id => { label, subtitle }
		foreach ( $locale as $key => $value ) {
			if ( 0 !== strpos( $key, 'album_' ) ) {
				continue;
			}

			// Extract the album_id and field from keys like "album_XXX.label".
			$dot_pos = strrpos( $key, '.' );
			if ( false === $dot_pos ) {
				continue;
			}

			$album_id = substr( $key, 0, $dot_pos );
			$field    = substr( $key, $dot_pos + 1 );

			// Skip photo-level keys (album_XXX_0.label) — those are slide titles.
			if ( preg_match( '/_\d+$/', $album_id ) ) {
				continue;
			}

			if ( ! isset( $album_map[ $album_id ] ) ) {
				$album_map[ $album_id ] = array(
					'label'    => '',
					'subtitle' => '',
				);
			}

			if ( 'label' === $field ) {
				$album_map[ $album_id ]['label'] = $value;
			} elseif ( 'subtitle' === $field ) {
				$album_map[ $album_id ]['subtitle'] = $value;
			}
		}

		// 3. Parse navigation categories from DropDown skin elements.
		//
		// The actual tour navigation categories live in DropDown_XXX.label
		// entries in the locale file (e.g. "RESIDENT ROOMS", "OUTDOOR AREAS").
		// Each DropDown has a corresponding DropDown_XXX_playlist in
		// script_general.js that lists which albums belong to that category.
		//
		// This replaces the old approach of using album labels as categories,
		// which produced incorrect per-album "categories" instead of the real
		// navigation groups.

		// 3a. Collect DropDown labels from locale (skip _mobile duplicates).
		$dropdown_labels = array(); // dropdown_id => label
		foreach ( $locale as $key => $value ) {
			if ( 0 !== strpos( $key, 'DropDown_' ) ) {
				continue;
			}
			// Match "DropDown_XXX.label" but not "DropDown_XXX_mobile.label".
			if ( false === strpos( $key, '.label' ) ) {
				continue;
			}
			if ( false !== strpos( $key, '_mobile.' ) ) {
				continue;
			}

			// Extract the DropDown ID: everything before ".label".
			$dropdown_id = substr( $key, 0, strpos( $key, '.label' ) );
			$dropdown_labels[ $dropdown_id ] = $value;
		}

		// 3b. Parse DropDown playlists from script_general.js.
		// Each DropDown_XXX_playlist contains items with "media":"this.album_YYY".
		$album_to_category = array(); // album_id => category label
		$dropdown_order    = array(); // ordered list of category labels

		foreach ( $dropdown_labels as $dropdown_id => $label ) {
			$playlist_id  = $dropdown_id . '_playlist';
			$playlist_pos = strpos( $content, '"id":"' . $playlist_id . '"' );
			if ( false === $playlist_pos ) {
				continue;
			}

			// Search backwards for the items array belonging to this playlist.
			$items_start = strrpos( substr( $content, 0, $playlist_pos ), '"items"' );
			if ( false === $items_start ) {
				continue;
			}

			$section   = substr( $content, $items_start, $playlist_pos - $items_start );
			$album_ids = array();
			if ( preg_match_all( '/"media"\s*:\s*"this\.(album_[^"]+)"/', $section, $dd_matches ) ) {
				$album_ids = $dd_matches[1];
			}

			if ( empty( $album_ids ) ) {
				continue;
			}

			$dropdown_order[] = $label;

			// Assign each album to this category (first match wins, so more
			// specific dropdowns should be processed before catch-all ones).
			foreach ( $album_ids as $album_id ) {
				if ( ! isset( $album_to_category[ $album_id ] ) ) {
					$album_to_category[ $album_id ] = $label;
				}
			}
		}

		$metadata['nav_categories'] = $dropdown_order;

		// 4. Extract mainPlayList — ordered album references.
		// Pattern: "media":"this.album_XXX" inside mainPlayList items.
		$playlist_album_ids = array();
		$playlist_pos       = strpos( $content, '"id":"mainPlayList"' );
		if ( false !== $playlist_pos ) {
			// Search backwards for the items array.
			$items_start = strrpos( substr( $content, 0, $playlist_pos ), '"items"' );
			if ( false !== $items_start ) {
				$playlist_section = substr( $content, $items_start, $playlist_pos - $items_start );
				if ( preg_match_all( '/"media"\s*:\s*"this\.(album_[^"]+)"/', $playlist_section, $pl_matches ) ) {
					$playlist_album_ids = $pl_matches[1];
				}
			}
		}

		// 5. Map photos to albums and build the final photos array.
		// A photo_id like album_XXX_0 belongs to album_id album_XXX.
		foreach ( $playlist_album_ids as $album_id ) {
			$album_info = isset( $album_map[ $album_id ] ) ? $album_map[ $album_id ] : null;

			// Category comes from the DropDown mapping, not the album label.
			$category = isset( $album_to_category[ $album_id ] ) ? $album_to_category[ $album_id ] : '';

			// Find the photo for this album (album_id + '_0').
			$photo_id = $album_id . '_0';
			if ( isset( $photo_map[ $photo_id ] ) ) {
				$photo    = $photo_map[ $photo_id ];
				$filename = $photo['filename'];

				if ( '' !== $filename ) {
					$metadata['main_playlist_filenames'][] = $filename;

					// Title: prefer the album display label (what 3DVista shows
					// on screen), fall back to photo-specific label.
					$title = $album_info ? $album_info['label'] : '';
					if ( '' === $title ) {
						$title = $photo['title'];
					}

					$description = $album_info ? $album_info['subtitle'] : '';

					$metadata['photos'][ $filename ] = array(
						'title'       => $title,
						'description' => $description,
						'category'    => $category,
					);
				}
			}
		}

		// 6. Add any photos not in the mainPlayList.
		foreach ( $photo_map as $photo_id => $photo ) {
			$filename = $photo['filename'];
			if ( '' !== $filename && ! isset( $metadata['photos'][ $filename ] ) ) {
				// Derive album_id by stripping the trailing _0.
				$album_id   = preg_replace( '/_0$/', '', $photo_id );
				$album_info = isset( $album_map[ $album_id ] ) ? $album_map[ $album_id ] : null;
				$category   = isset( $album_to_category[ $album_id ] ) ? $album_to_category[ $album_id ] : '';

				$title = $album_info ? $album_info['label'] : '';
				if ( '' === $title ) {
					$title = $photo['title'];
				}

				$metadata['photos'][ $filename ] = array(
					'title'       => $title,
					'description' => $album_info ? $album_info['subtitle'] : '',
					'category'    => $category,
				);
			}
		}

		// 6. Extract floorplan (Map) data.
		if ( preg_match_all( '/"id"\s*:\s*"(map_[^"]+)"[^}]*"class"\s*:\s*"Map"/', $content, $map_matches ) ) {
			foreach ( $map_matches[1] as $map_id ) {
				$label     = '';
				$label_key = $map_id . '.label';
				if ( isset( $locale[ $label_key ] ) ) {
					$label = $locale[ $label_key ];
				}

				// Find floorplan image files from locale imlevel entries.
				$image_file = '';
				foreach ( $locale as $key => $value ) {
					if ( 0 === strpos( $key, 'imlevel_' ) && false !== strpos( $key, '.url' ) ) {
						if ( false !== strpos( $value, $map_id ) ) {
							// Use the highest-resolution (level 0).
							if ( false !== strpos( $value, '_0.' ) || '' === $image_file ) {
								$image_file = str_replace( 'media/', '', $value );
							}
						}
					}
				}

				if ( '' !== $image_file ) {
					$metadata['floorplans'][] = array(
						'label'      => $label,
						'image_file' => $image_file,
						'map_id'     => $map_id,
					);
				}
			}
		}

		// Also match Map objects where class comes before id.
		if ( preg_match_all( '/"class"\s*:\s*"Map"[^}]*"id"\s*:\s*"(map_[^"]+)"/', $content, $map_rev ) ) {
			$existing_ids = array_column( $metadata['floorplans'], 'map_id' );
			foreach ( $map_rev[1] as $map_id ) {
				if ( in_array( $map_id, $existing_ids, true ) ) {
					continue;
				}

				$label     = '';
				$label_key = $map_id . '.label';
				if ( isset( $locale[ $label_key ] ) ) {
					$label = $locale[ $label_key ];
				}

				$image_file = '';
				foreach ( $locale as $key => $value ) {
					if ( 0 === strpos( $key, 'imlevel_' ) && false !== strpos( $key, '.url' ) ) {
						if ( false !== strpos( $value, $map_id ) ) {
							if ( false !== strpos( $value, '_0.' ) || '' === $image_file ) {
								$image_file = str_replace( 'media/', '', $value );
							}
						}
					}
				}

				if ( '' !== $image_file ) {
					$metadata['floorplans'][] = array(
						'label'      => $label,
						'image_file' => $image_file,
						'map_id'     => $map_id,
					);
				}
			}
		}

		// 7. Extract embedded tour URLs (Matterport, etc.) from locale.
		foreach ( $locale as $key => $value ) {
			if ( 0 !== strpos( $key, 'WebFrame_' ) || false === strpos( $key, '.url' ) ) {
				continue;
			}

			// Skip mobile duplicates and Google Maps embeds.
			if ( false !== strpos( $key, '_mobile' ) ) {
				continue;
			}
			if ( false !== strpos( $value, 'google.com/maps' ) ) {
				continue;
			}

			// Derive a label from the URL (e.g. "Matterport Tour").
			$label = '3D Tour';
			if ( false !== strpos( $value, 'matterport.com' ) ) {
				$label = 'Matterport Tour';
			}

			$metadata['embedded_tours'][] = array(
				'label' => $label,
				'url'   => $value,
			);
		}

		// 8. Extract testimonial videos.
		//
		// Each Video object exposes a "video_<ID>.label" locale entry (the
		// speaker's role, e.g. "Daughter of Current Resident") and a
		// "videolevel_<ID2>.url" entry pointing at the media/ mp4 file. The
		// thumbnail is the "_t.jpg" sidecar next to the video.
		$video_labels = array(); // video_<ID> => role label.
		foreach ( $locale as $key => $value ) {
			if ( 0 !== strpos( $key, 'video_' ) ) {
				continue;
			}
			if ( '.label' !== substr( $key, -6 ) ) {
				continue;
			}
			if ( false !== strpos( $key, '_mobile' ) ) {
				continue;
			}
			$video_id                  = substr( $key, 0, -6 );
			$video_labels[ $video_id ] = $value;
		}

		if ( ! empty( $video_labels ) ) {
			$seen_videos = array();
			foreach ( $locale as $key => $value ) {
				if ( 0 !== strpos( $key, 'videolevel_' ) || '.url' !== substr( $key, -4 ) ) {
					continue;
				}
				if ( false !== strpos( $key, '_mobile' ) ) {
					continue;
				}

				$video_file = basename( $value );
				if ( '' === $video_file ) {
					continue;
				}

				// Match the file against a known video_<ID>.label entry.
				$matched_id = '';
				foreach ( $video_labels as $video_id => $role ) {
					if ( 0 === strpos( $video_file, $video_id ) ) {
						$matched_id = $video_id;
						break;
					}
				}
				if ( '' === $matched_id ) {
					continue;
				}

				// A video may be listed at multiple resolutions — keep the first.
				if ( isset( $seen_videos[ $matched_id ] ) ) {
					continue;
				}
				$seen_videos[ $matched_id ] = true;

				$metadata['testimonials'][] = array(
					'role'       => $video_labels[ $matched_id ],
					'video_file' => $video_file,
					'thumb_file' => $matched_id . '_t.jpg',
				);
			}
		}

		// 9. Extract contact details.
		//
		// The contact card is built from two skin elements: an HTMLText
		// block (facility name, address, email, phone) and a WebFrame
		// holding a Google Maps embed URL. The HTMLText markup is heavily
		// styled, so block-level tags are converted to newlines before the
		// visible text is read line by line.
		$contact = array(
			'facility_name'  => isset( $locale['tour.name'] ) ? trim( $locale['tour.name'] ) : '',
			'address'        => '',
			'email'          => '',
			'phone'          => '',
			'maps_embed_url' => '',
		);

		foreach ( $locale as $key => $value ) {
			if ( 0 !== strpos( $key, 'WebFrame_' ) || false === strpos( $key, '.url' ) ) {
				continue;
			}
			if ( false !== strpos( $key, '_mobile' ) ) {
				continue;
			}
			if ( false !== strpos( $value, 'google.com/maps' ) ) {
				$contact['maps_embed_url'] = trim( $value );
				break;
			}
		}

		// Locate the contact HTMLText block — the one that carries an email.
		$contact_html = '';
		foreach ( $locale as $key => $value ) {
			if ( 0 !== strpos( $key, 'HTMLText_' ) || '.html' !== substr( $key, -5 ) ) {
				continue;
			}
			if ( false !== strpos( $key, '_mobile' ) ) {
				continue;
			}
			if ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.-]+/', $value ) ) {
				$contact_html = $value;
				break;
			}
		}

		if ( '' !== $contact_html ) {
			// Convert block tags to newlines, then strip the remaining markup.
			$text = preg_replace( '/<br\b[^>]*>/i', "\n", $contact_html );
			$text = preg_replace( '/<\/(p|div)>/i', "\n", $text );
			$text = wp_strip_all_tags( $text );
			$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

			$contact_lines = array();
			foreach ( explode( "\n", $text ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$contact_lines[] = $line;
				}
			}

			// Pull the email and phone out; the remaining lines are the
			// facility name and the street address. Fax numbers are ignored.
			$remaining = array();
			foreach ( $contact_lines as $line ) {
				if ( '' === $contact['email'] && preg_match( '/[\w.+-]+@[\w-]+\.[\w.-]+/', $line, $em ) ) {
					$contact['email'] = $em[0];
					continue;
				}
				if ( preg_match( '/\(?\d{3}\)?[\s.\-]*\d{3}[\s.\-]*\d{4}/', $line, $ph ) ) {
					if ( '' === $contact['phone'] && false === stripos( $line, 'fax' ) ) {
						$contact['phone'] = trim( $ph[0] );
					}
					continue;
				}
				$remaining[] = $line;
			}

			// Separate the facility-name line from the address lines.
			if ( '' === $contact['facility_name'] && ! empty( $remaining ) ) {
				$contact['facility_name'] = array_shift( $remaining );
			} else {
				foreach ( $remaining as $idx => $line ) {
					if ( 0 === strcasecmp( $line, $contact['facility_name'] ) ) {
						unset( $remaining[ $idx ] );
						break;
					}
				}
			}

			$contact['address'] = implode( "\n", array_values( $remaining ) );
		}

		$metadata['contact'] = $contact;

		return $metadata;
	}

	/**
	 * Find floorplan image files in the extracted archive.
	 *
	 * @param string $extract_dir Extracted archive directory.
	 * @param array  $metadata    Parsed 3DVista metadata.
	 * @return array List of absolute file paths for floorplan images.
	 */
	private function catalog_floorplan_images( $extract_dir, $metadata ) {
		if ( empty( $metadata['floorplans'] ) ) {
			return array();
		}

		$media_dir = $extract_dir . '/media';
		$images    = array();

		foreach ( $metadata['floorplans'] as $floorplan ) {
			$file_path = $media_dir . '/' . $floorplan['image_file'];
			if ( file_exists( $file_path ) ) {
				$images[] = $file_path;
			}
		}

		return $images;
	}

	/**
	 * Find testimonial video and thumbnail files in the extracted archive.
	 *
	 * Each returned item is an upload-queue entry carrying the absolute
	 * file path and a metadata array describing its role and the index of
	 * the testimonial it belongs to, so the finalize step can pair videos
	 * with thumbnails even if some uploads fail.
	 *
	 * @param string $extract_dir Extracted archive directory.
	 * @param array  $metadata    Parsed 3DVista metadata.
	 * @return array List of { path, meta } upload-queue items.
	 */
	private function catalog_testimonial_files( $extract_dir, $metadata ) {
		if ( empty( $metadata['testimonials'] ) ) {
			return array();
		}

		$media_dir = $extract_dir . '/media';
		$items     = array();

		foreach ( $metadata['testimonials'] as $t_index => $testimonial ) {
			if ( ! empty( $testimonial['video_file'] ) ) {
				$video_path = $media_dir . '/' . $testimonial['video_file'];
				if ( file_exists( $video_path ) ) {
					$items[] = array(
						'path' => $video_path,
						'meta' => array( 'role' => 'testimonial_video', 'index' => $t_index ),
					);
				}
			}

			if ( ! empty( $testimonial['thumb_file'] ) ) {
				$thumb_path = $media_dir . '/' . $testimonial['thumb_file'];
				if ( file_exists( $thumb_path ) ) {
					$items[] = array(
						'path' => $thumb_path,
						'meta' => array( 'role' => 'testimonial_thumb', 'index' => $t_index ),
					);
				}
			}
		}

		return $items;
	}

	/**
	 * Reorder images to match the mainPlayList presentation order.
	 *
	 * Images not in the playlist are appended at the end.
	 *
	 * @param array $images   List of absolute file paths.
	 * @param array $metadata Parsed 3DVista metadata.
	 * @return array Reordered list of absolute file paths.
	 */
	private function order_images_by_metadata( $images, $metadata ) {
		if ( empty( $metadata['main_playlist_filenames'] ) ) {
			return $images;
		}

		// Build a map of basename => full path.
		$by_basename = array();
		foreach ( $images as $path ) {
			$by_basename[ basename( $path ) ] = $path;
		}

		$ordered   = array();
		$used      = array();

		foreach ( $metadata['main_playlist_filenames'] as $filename ) {
			if ( isset( $by_basename[ $filename ] ) ) {
				$ordered[]          = $by_basename[ $filename ];
				$used[ $filename ]  = true;
			}
		}

		// Append any images not in the playlist.
		foreach ( $images as $path ) {
			if ( ! isset( $used[ basename( $path ) ] ) ) {
				$ordered[] = $path;
			}
		}

		return $ordered;
	}

	/**
	 * Scan the media/ folder for full-resolution panorama images.
	 *
	 * Only files directly inside media/ are considered. 3DVista stores each
	 * 360 panorama's multi-resolution tile pyramid in a per-panorama subfolder
	 * (media/panorama_<id>/d/<level>/); those tiles are runtime rendering
	 * assets, not importable slides, and must not be cataloged.
	 *
	 * Excludes thumbnail variants (files ending in _t before the extension)
	 * and floorplan images (files starting with map_).
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

			// Skip deep-zoom panorama tiles. Importable panoramas (album_*.jpg)
			// sit directly in media/; anything in a subfolder is tile-pyramid
			// data from a media/panorama_*/d/ directory.
			if ( '' !== $iter->getSubPath() ) {
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

			// Skip floorplan images (filenames starting with map_).
			if ( 0 === strpos( $basename, 'map_' ) ) {
				continue;
			}

			// Skip video sidecar images — testimonial/popup video posters
			// (video_XXX_poster_en.jpg) and thumbnails (video_XXX_t.jpg).
			// The poster files are solid-black placeholders and must not
			// become panorama slides; the videos are imported separately.
			if ( 0 === strpos( $basename, 'video_' ) ) {
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
	 * @param int    $tour_id          The tour post ID.
	 * @param array  $attachment_ids   Array of [ 'id' => int, 'filename' => string ].
	 * @param int    $template_id      Template post ID (0 for none).
	 * @param string $default_category Default navigation category label.
	 * @param array  $metadata           Parsed 3DVista metadata (optional).
	 * @param array  $floorplan_atts     Floorplan attachment data (optional).
	 * @param array  $testimonial_videos Testimonial video attachments keyed by testimonial index (optional).
	 * @param array  $testimonial_thumbs Testimonial thumbnail attachments keyed by testimonial index (optional).
	 */
	private function populate_acf_fields( $tour_id, $attachment_ids, $template_id, $default_category, $metadata = array(), $floorplan_atts = array(), $testimonial_videos = array(), $testimonial_thumbs = array() ) {
		$has_metadata = ! empty( $metadata ) && ! empty( $metadata['photos'] );

		// Template.
		if ( $template_id > 0 ) {
			update_field( 'tour_template', $template_id, $tour_id );
		}

		// Hero — use the first slide image.
		if ( ! empty( $attachment_ids ) ) {
			$first = $attachment_ids[0];
			update_field( 'hero_media_type', 'image', $tour_id );
			update_field( 'hero_image', $first['id'], $tour_id );
		}

		// Navigation categories — every imported tour uses the standard
		// predetermined set so the nav structure is consistent. 3DVista's
		// own category grouping is intentionally not used.
		update_field( 'nav_categories', H3VT_Tours_ACF::get_default_nav_categories(), $tour_id );

		// Slides repeater.
		$slide_count = count( $attachment_ids );
		update_post_meta( $tour_id, 'slides', $slide_count );
		update_post_meta( $tour_id, '_slides', 'field_h3vt_navigation_slides' );

		// Pre-scan for duplicate titles so we can append numbers.
		$title_counts = array();
		foreach ( $attachment_ids as $att ) {
			$fn = $att['filename'];
			$t  = '';
			if ( $has_metadata && isset( $metadata['photos'][ $fn ] ) && '' !== $metadata['photos'][ $fn ]['title'] ) {
				$t = $metadata['photos'][ $fn ]['title'];
			} else {
				$t = $this->filename_to_title( $fn );
			}
			if ( ! isset( $title_counts[ $t ] ) ) {
				$title_counts[ $t ] = 0;
			}
			$title_counts[ $t ]++;
		}
		$title_seen = array(); // track how many times we've output each title

		foreach ( $attachment_ids as $i => $att ) {
			$prefix   = "slides_{$i}_";
			$filename = $att['filename'];

			// Look up metadata for this image.
			$photo_meta = null;
			if ( $has_metadata && isset( $metadata['photos'][ $filename ] ) ) {
				$photo_meta = $metadata['photos'][ $filename ];
			}

			// Slide title: prefer metadata, fall back to filename.
			$title = ( $photo_meta && '' !== $photo_meta['title'] )
				? $photo_meta['title']
				: $this->filename_to_title( $filename );

			// Append a number when multiple slides share the same title.
			if ( $title_counts[ $title ] > 1 ) {
				if ( ! isset( $title_seen[ $title ] ) ) {
					$title_seen[ $title ] = 0;
				}
				$title_seen[ $title ]++;
				$title .= ' ' . $title_seen[ $title ];
			}

			update_post_meta( $tour_id, "{$prefix}slide_title", $title );
			update_post_meta( $tour_id, "_{$prefix}slide_title", 'field_h3vt_navigation_slide_title' );

			// Slide description: from metadata or empty.
			$description = ( $photo_meta && '' !== $photo_meta['description'] )
				? $photo_meta['description']
				: '';

			update_post_meta( $tour_id, "{$prefix}slide_description", $description );
			update_post_meta( $tour_id, "_{$prefix}slide_description", 'field_h3vt_navigation_slide_description' );

			// Slide category: an explicit form-supplied default wins;
			// otherwise auto-detect from the slide title and description.
			if ( ! empty( $default_category ) ) {
				$category = $default_category;
			} else {
				$category = $this->guess_slide_category( $title );
			}

			if ( '' !== $category ) {
				update_post_meta( $tour_id, "{$prefix}slide_nav_category", $category );
				update_post_meta( $tour_id, "_{$prefix}slide_nav_category", 'field_h3vt_navigation_slide_nav_category' );
			}

			// Store the real attachment ID — ACF validates this natively.
			update_post_meta( $tour_id, "{$prefix}slide_image", $att['id'] );
			update_post_meta( $tour_id, "_{$prefix}slide_image", 'field_h3vt_navigation_slide_image' );
		}

		// Floor plans.
		if ( ! empty( $floorplan_atts ) && ! empty( $metadata['floorplans'] ) ) {
			update_field( 'enable_floorplans', true, $tour_id );

			$floorplan_rows = array();
			foreach ( $floorplan_atts as $fp_index => $fp_att ) {
				$fp_meta = isset( $metadata['floorplans'][ $fp_index ] ) ? $metadata['floorplans'][ $fp_index ] : null;
				$label   = $fp_meta ? $fp_meta['label'] : sprintf( 'Floor Plan %d', $fp_index + 1 );

				$floorplan_rows[] = array(
					'floorplan_label' => $label,
					'floorplan_image' => $fp_att['id'],
				);
			}

			update_field( 'floorplans', $floorplan_rows, $tour_id );
		}

		// Embedded tours.
		if ( ! empty( $metadata['embedded_tours'] ) ) {
			$tour_rows = array();
			foreach ( $metadata['embedded_tours'] as $embed ) {
				$tour_rows[] = array(
					'tour_label'     => $embed['label'],
					'tour_embed_url' => $embed['url'],
				);
			}

			update_field( 'embedded_tours', $tour_rows, $tour_id );
		}

		// Testimonials — one repeater row per video that uploaded successfully.
		if ( ! empty( $metadata['testimonials'] ) && ! empty( $testimonial_videos ) ) {
			$testimonial_rows = array();

			foreach ( $metadata['testimonials'] as $t_index => $testimonial ) {
				if ( ! isset( $testimonial_videos[ $t_index ] ) ) {
					continue;
				}

				$row = array(
					'person_name' => '',
					'person_role' => isset( $testimonial['role'] ) ? $testimonial['role'] : '',
					'video_url'   => $testimonial_videos[ $t_index ]['id'],
				);

				if ( isset( $testimonial_thumbs[ $t_index ] ) ) {
					$row['thumbnail'] = $testimonial_thumbs[ $t_index ]['id'];
				}

				$testimonial_rows[] = $row;
			}

			if ( ! empty( $testimonial_rows ) ) {
				update_field( 'enable_testimonials', true, $tour_id );
				update_field( 'testimonials', $testimonial_rows, $tour_id );
			}
		}

		// Contact — populate from parsed 3DVista contact details.
		if ( ! empty( $metadata['contact'] ) ) {
			$contact = $metadata['contact'];

			$has_contact = '' !== $contact['facility_name']
				|| '' !== $contact['address']
				|| '' !== $contact['email']
				|| '' !== $contact['phone']
				|| '' !== $contact['maps_embed_url'];

			if ( $has_contact ) {
				update_field( 'enable_contact', true, $tour_id );

				if ( '' !== $contact['facility_name'] ) {
					update_field( 'contact_facility_name', $contact['facility_name'], $tour_id );
				}
				if ( '' !== $contact['address'] ) {
					update_field( 'contact_address', $contact['address'], $tour_id );
				}
				if ( '' !== $contact['email'] ) {
					update_field( 'contact_email', $contact['email'], $tour_id );
				}
				if ( '' !== $contact['phone'] ) {
					update_field( 'contact_phone', $contact['phone'], $tour_id );
				}
				if ( '' !== $contact['maps_embed_url'] ) {
					update_field( 'google_maps_embed_url', $contact['maps_embed_url'], $tour_id );
				}
			}
		}
	}

	/**
	 * Best-effort mapping of a slide title to a standard nav category.
	 *
	 * 3DVista archives do not carry the client's category taxonomy, so
	 * each slide is bucketed by keyword match against its title. The
	 * description is intentionally ignored — it is marketing copy that
	 * frequently references other areas. Unrecognised titles fall back
	 * to "Common Areas".
	 *
	 * @param string $text The slide title.
	 * @return string One of the standard nav category labels.
	 */
	private function guess_slide_category( $text ) {
		$text = ' ' . strtolower( $text ) . ' ';

		$keywords = array(
			'Resident Rooms'        => array( 'resident room', 'bedroom', 'bed room', 'private room', 'model room', 'resident suite', 'studio apartment', 'sleeping room' ),
			'Dining / Living Areas' => array( 'dining', 'living room', 'kitchen', 'cafe', 'bistro', 'family room', 'great room', 'family-style', 'share meals' ),
			'Activity Rooms'        => array( 'activity', 'activities', 'arts', 'art studio', 'art room', 'craft', 'game', 'fitness', 'exercise', 'theater', 'theatre', 'cinema', 'media room', 'music', 'library', 'salon', 'beauty', 'hobby' ),
			'Outdoor Areas'         => array( 'outdoor', 'courtyard', 'garden', 'patio', 'deck', 'balcony', 'walking path', 'landscap', 'aerial', 'exterior', 'gazebo', 'porch' ),
			'Common Areas'          => array( 'common', 'lobby', 'entrance', 'entry', 'welcome', 'reception', 'hallway', 'corridor', 'chapel', 'fireplace', 'mailroom' ),
		);

		foreach ( $keywords as $category => $words ) {
			foreach ( $words as $word ) {
				if ( false !== strpos( $text, $word ) ) {
					return $category;
				}
			}
		}

		return 'Common Areas';
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

	/**
	 * Shutdown handler that logs fatal errors to debug.log.
	 *
	 * PHP fatal errors (memory exhaustion, timeouts) kill the process
	 * before WordPress's error handler can catch them, so they never
	 * appear in debug.log. This handler ensures they do.
	 */
	public function log_fatal_error() {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ), true ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[H3VT Tours] Fatal error during 3DVista import: %s in %s on line %d',
						$error['message'],
						$error['file'],
						$error['line']
					)
				);
			}
		}
	}
}
