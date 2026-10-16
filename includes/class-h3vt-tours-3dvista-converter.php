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

		// Drop assets the tour's navigation does not file under any category.
		// Some exports ship the same slide twice (once per skin layout) plus
		// hotspot/popup artwork; uploading those doubles the media library for
		// images that can never appear as slides.
		$images = $this->filter_to_categorised( $images, $metadata );

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
			'categorised_files'      => array(),
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
		// The tour's real navigation groups live in the DropDown skin elements.
		// Resolving them is delegated to resolve_nav_categories(), which handles
		// both of the export layouts 3DVista produces (see that method).
		$nav = $this->resolve_nav_categories( $content, $locale );

		$metadata['nav_categories'] = $nav['order'];

		$album_to_category = $nav['album_categories'];

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

		// 6b. Merge in assets that the category albums reference directly.
		//
		// In the "category album" export layout the navigation groups point at
		// standalone photo_XXX.jpg assets rather than at mainPlayList albums,
		// so those files never appear in $photo_map. They carry the only
		// reliable category data in the export, so they are added here — and
		// their titles come from the photo object rather than an album label.
		foreach ( $nav['file_categories'] as $filename => $category ) {
			$title = isset( $nav['file_titles'][ $filename ] ) ? $nav['file_titles'][ $filename ] : '';

			if ( isset( $metadata['photos'][ $filename ] ) ) {
				$metadata['photos'][ $filename ]['category'] = $category;
				if ( '' !== $title && '' === $metadata['photos'][ $filename ]['title'] ) {
					$metadata['photos'][ $filename ]['title'] = $title;
				}
				continue;
			}

			$metadata['photos'][ $filename ] = array(
				'title'       => $title,
				'description' => '',
				'category'    => $category,
			);
		}

		// Every asset the navigation actually files under a category, in both
		// export layouts. Used to keep non-slide artwork out of the import.
		foreach ( $metadata['photos'] as $filename => $photo_row ) {
			if ( ! empty( $photo_row['category'] ) ) {
				$metadata['categorised_files'][] = $filename;
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

			// Derive a label from the URL (e.g. "3D Virtual Tour").
			$label = '3D Tour';
			if ( false !== strpos( $value, 'matterport.com' ) ) {
				$label = '3D Virtual Tour';
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
	/**
	 * Restrict the panorama queue to assets the tour's navigation references.
	 *
	 * Only applies when the export actually carried navigation grouping — for
	 * exports without it every catalogued image is kept, since there is then
	 * no way to tell a real slide from a popup image.
	 *
	 * @param array $images   Absolute file paths.
	 * @param array $metadata Parsed 3DVista metadata.
	 * @return array Filtered file paths.
	 */
	private function filter_to_categorised( $images, $metadata ) {
		if ( empty( $metadata['categorised_files'] ) ) {
			return $images;
		}

		$keep     = array_flip( $metadata['categorised_files'] );
		$filtered = array();

		foreach ( $images as $path ) {
			if ( isset( $keep[ basename( $path ) ] ) ) {
				$filtered[] = $path;
			}
		}

		// Never empty the queue on an unexpected filename mismatch.
		return empty( $filtered ) ? $images : $filtered;
	}

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

		// Navigation categories — every imported tour starts from the
		// standard predetermined set so the nav structure is consistent,
		// and each slide is filed under the category the source tour's own
		// navigation puts it in. The slide title and description are stored
		// on the attachment itself so they're editable in the gallery's
		// Edit Image form.
		$grouped = array();
		foreach ( H3VT_Tours_ACF::get_default_nav_categories() as $row ) {
			$grouped[ $row['nav_label'] ] = array();
		}

		// Whether the export carried real navigation grouping. When it did,
		// assets outside every group are deliberately excluded below.
		$has_source_categories = false;
		if ( $has_metadata && ! empty( $metadata['photos'] ) ) {
			foreach ( $metadata['photos'] as $photo_row ) {
				if ( ! empty( $photo_row['category'] ) ) {
					$has_source_categories = true;
					break;
				}
			}
		}

		foreach ( $attachment_ids as $i => $att ) {
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

			// Slide description: from metadata or empty.
			$description = ( $photo_meta && '' !== $photo_meta['description'] )
				? $photo_meta['description']
				: '';

			wp_update_post( array(
				'ID'           => $att['id'],
				'post_title'   => $title,
				'post_excerpt' => $description,
			) );

			// Slide category. An explicit form-supplied default wins; otherwise
			// the tour's own navigation grouping is authoritative.
			if ( ! empty( $default_category ) ) {
				$category = $default_category;
			} elseif ( $photo_meta && ! empty( $photo_meta['category'] ) ) {
				$category = $photo_meta['category'];
			} elseif ( $has_source_categories ) {
				// The export does carry category data and this asset is not in
				// any of them — it is a hotspot/popup image or a duplicate of a
				// categorised slide, so it is not a navigable slide. Leave it
				// out rather than inventing a category for it.
				continue;
			} else {
				// No category data in the export at all: fall back to keywords.
				$category = $this->guess_slide_category( $title );
			}

			if ( ! isset( $grouped[ $category ] ) ) {
				$grouped[ $category ] = array();
			}
			$grouped[ $category ][] = $att['id'];
		}

		$category_rows = array();
		foreach ( $grouped as $label => $ids ) {
			$category_rows[] = array(
				'nav_label'   => $label,
				'nav_gallery' => $ids,
			);
		}
		update_field( 'field_h3vt_navigation_nav_categories', $category_rows, $tour_id );

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
	 * Return the complete JSON object surrounding a byte offset.
	 *
	 * script_general.js objects nest arbitrarily, so brace matching is the
	 * only reliable way to isolate one — regex and nearest-"items" heuristics
	 * silently capture a neighbouring object's items array instead.
	 *
	 * @param string $content Script contents.
	 * @param int    $offset  Byte offset known to sit inside the object.
	 * @return string The object text including its braces, or '' on failure.
	 */
	private function extract_object_at( $content, $offset ) {
		// Walk backwards to this object's opening brace.
		$depth = 0;
		$start = -1;
		for ( $i = $offset; $i >= 0; $i-- ) {
			$char = $content[ $i ];
			if ( '}' === $char ) {
				$depth++;
			} elseif ( '{' === $char ) {
				if ( 0 === $depth ) {
					$start = $i;
					break;
				}
				$depth--;
			}
		}

		if ( $start < 0 ) {
			return '';
		}

		// Walk forwards to the matching closing brace.
		$depth  = 0;
		$length = strlen( $content );
		for ( $j = $start; $j < $length; $j++ ) {
			$char = $content[ $j ];
			if ( '{' === $char ) {
				$depth++;
			} elseif ( '}' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $content, $start, $j - $start + 1 );
				}
			}
		}

		return '';
	}

	/**
	 * Locate a script object by its "id" value.
	 *
	 * @param string $content Script contents.
	 * @param string $id      Object id to find.
	 * @return string The object text, or '' when not present.
	 */
	private function find_object_by_id( $content, $id ) {
		if ( ! preg_match( '/"id"\s*:\s*"' . preg_quote( $id, '/' ) . '"/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		return $this->extract_object_at( $content, $m[0][1] );
	}

	/**
	 * Map a 3DVista category name onto the standard nav label set.
	 *
	 * Tours label the same group differently across brands — "AMENITIES" and
	 * "COMMON AREAS" are the same category, as are "DINING" and
	 * "DINING / LIVING AREAS" — so matching is by keyword rather than exact
	 * string. Aerial views are folded into Outdoor Areas, matching how the
	 * exports themselves group them internally.
	 *
	 * @param string $name Category name from the export.
	 * @return string A standard nav label, or '' when nothing matches.
	 */
	private function normalize_nav_category( $name ) {
		$text = ' ' . preg_replace( '/\s+/', ' ', strtolower( trim( $name ) ) ) . ' ';

		if ( ' ' === $text ) {
			return '';
		}

		// Order matters: "Dining / Living" must win before the broader
		// common-area keywords, and "aerial" before any outdoor synonym.
		$keywords = array(
			'Resident Rooms'        => array( 'resident', 'bedroom', 'private room', 'shared room' ),
			'Dining / Living Areas' => array( 'dining', 'living', 'bistro', 'kitchen', 'cafe' ),
			'Activity Rooms'        => array( 'activity', 'activities' ),
			'Outdoor Areas'         => array( 'outdoor', 'aerial', 'exterior', 'courtyard', 'garden', 'patio' ),
			'Common Areas'          => array( 'common', 'amenit', 'lobby', 'entrance' ),
		);

		foreach ( $keywords as $category => $words ) {
			foreach ( $words as $word ) {
				if ( false !== strpos( $text, $word ) ) {
					return $category;
				}
			}
		}

		return '';
	}

	/**
	 * Resolve the tour's real navigation categories and their member assets.
	 *
	 * 3DVista emits two different layouts and a tour uses one or the other:
	 *
	 *  A. The DropDown has a companion DropDown_XXX_playlist (or, on exports
	 *     that only ship a mobile skin, DropDown_XXX_mobile_playlist) whose
	 *     items reference the mainPlayList albums belonging to that category.
	 *     The slide asset for an album is album_XXX_0.<ext>.
	 *
	 *  B. The DropDown's click handler calls setPlayListSelectedIndex() on a
	 *     standalone playList_XXX holding a single "category album" — a
	 *     PhotoAlbum whose own playlist contains every photo_XXX.<ext> in that
	 *     category. Here the DropDown_XXX_playlist object exists but is an
	 *     empty stub, which is why keying off it alone yields nothing.
	 *
	 * The category name is taken from the DropDown's data.name (the author's
	 * internal grouping) in preference to its visible label, which is often a
	 * brand-specific synonym.
	 *
	 * @param string $content Contents of script_general.js.
	 * @param array  $locale  Parsed locale strings.
	 * @return array {
	 *     @type array $order            Standard nav labels in presentation order.
	 *     @type array $album_categories album_id => standard nav label (layout A).
	 *     @type array $file_categories  filename => standard nav label (layout B).
	 *     @type array $file_titles      filename => slide title (layout B).
	 * }
	 */
	private function resolve_nav_categories( $content, $locale ) {
		$result = array(
			'order'            => array(),
			'album_categories' => array(),
			'file_categories'  => array(),
			'file_titles'      => array(),
		);

		// Collect DropDown labels from locale (skip _mobile duplicates).
		$dropdown_labels = array();
		foreach ( $locale as $key => $value ) {
			if ( 0 !== strpos( $key, 'DropDown_' ) ) {
				continue;
			}
			if ( false === strpos( $key, '.label' ) ) {
				continue;
			}
			if ( false !== strpos( $key, '_mobile.' ) ) {
				continue;
			}
			$dropdown_labels[ substr( $key, 0, strpos( $key, '.label' ) ) ] = $value;
		}

		foreach ( $dropdown_labels as $dropdown_id => $dropdown_label ) {
			$dropdown_obj = $this->find_object_by_id( $content, $dropdown_id );
			if ( '' === $dropdown_obj ) {
				continue;
			}

			$data_name = '';
			if ( preg_match( '/"data"\s*:\s*\{[^}]*"name"\s*:\s*"([^"]*)"/', $dropdown_obj, $dm ) ) {
				$data_name = $dm[1];
			}

			$category = $this->normalize_nav_category( $data_name );
			if ( '' === $category ) {
				$category = $this->normalize_nav_category( $dropdown_label );
			}
			if ( '' === $category ) {
				continue;
			}

			$matched = false;

			// Layout A — companion playlist listing mainPlayList albums.
			foreach ( array( '_playlist', '_mobile_playlist' ) as $suffix ) {
				$playlist_obj = $this->find_object_by_id( $content, $dropdown_id . $suffix );
				if ( '' === $playlist_obj ) {
					continue;
				}
				if ( ! preg_match_all( '/"media"\s*:\s*"this\.(album_[^"]+)"/', $playlist_obj, $am ) ) {
					continue;
				}

				foreach ( $am[1] as $album_id ) {
					// First dropdown to claim an album wins.
					if ( ! isset( $result['album_categories'][ $album_id ] ) ) {
						$result['album_categories'][ $album_id ] = $category;
					}
				}
				$matched = true;
				break;
			}

			// Layout B — click handler points at a standalone category album.
			if ( ! $matched && preg_match_all( '/setPlayListSelectedIndex\(\s*this\.(playList_[A-Za-z0-9_]+)/', $dropdown_obj, $pm ) ) {
				foreach ( $pm[1] as $playlist_id ) {
					$playlist_obj = $this->find_object_by_id( $content, $playlist_id );
					if ( '' === $playlist_obj ) {
						continue;
					}
					if ( ! preg_match_all( '/"media"\s*:\s*"this\.(album_[^"]+)"/', $playlist_obj, $am ) ) {
						continue;
					}

					foreach ( $am[1] as $album_id ) {
						$files = $this->category_album_files( $content, $locale, $album_id );
						foreach ( $files as $filename => $title ) {
							if ( ! isset( $result['file_categories'][ $filename ] ) ) {
								$result['file_categories'][ $filename ] = $category;
								$result['file_titles'][ $filename ]     = $title;
							}
						}
						if ( ! empty( $files ) ) {
							$matched = true;
						}
					}

					if ( $matched ) {
						break;
					}
				}
			}

			if ( $matched && ! in_array( $category, $result['order'], true ) ) {
				$result['order'][] = $category;
			}
		}

		return $result;
	}

	/**
	 * Resolve a category album to its member image files and titles.
	 *
	 * @param string $content  Contents of script_general.js.
	 * @param array  $locale   Parsed locale strings.
	 * @param string $album_id The category album's id.
	 * @return array filename => title.
	 */
	private function category_album_files( $content, $locale, $album_id ) {
		$files      = array();
		$album_obj  = $this->find_object_by_id( $content, $album_id );
		if ( '' === $album_obj ) {
			return $files;
		}

		if ( ! preg_match( '/"playList"\s*:\s*"this\.([A-Za-z0-9_]+)"/', $album_obj, $pm ) ) {
			return $files;
		}

		$playlist_obj = $this->find_object_by_id( $content, $pm[1] );
		if ( '' === $playlist_obj ) {
			return $files;
		}

		if ( ! preg_match_all( '/"media"\s*:\s*"this\.(photo_[A-Za-z0-9_]+)"/', $playlist_obj, $mm ) ) {
			return $files;
		}

		foreach ( $mm[1] as $photo_id ) {
			$photo_obj = $this->find_object_by_id( $content, $photo_id );
			if ( '' === $photo_obj ) {
				continue;
			}

			// The full-size asset, not the _t thumbnail.
			if ( ! preg_match( '/"url"\s*:\s*"media\/(' . preg_quote( $photo_id, '/' ) . '\.(?:jpg|jpeg|png|webp))"/i', $photo_obj, $um ) ) {
				continue;
			}

			$title     = '';
			$label_key = $photo_id . '.label';
			if ( isset( $locale[ $label_key ] ) && '' !== $locale[ $label_key ] ) {
				$title = $locale[ $label_key ];
			} elseif ( preg_match( '/"data"\s*:\s*\{[^}]*"label"\s*:\s*"([^"]*)"/', $photo_obj, $lm ) ) {
				$title = $lm[1];
			}

			$files[ $um[1] ] = $title;
		}

		return $files;
	}

	/**
	 * Best-effort mapping of a slide title to a standard nav category.
	 *
	 * Fallback for exports whose skin carries no navigation grouping at all;
	 * when resolve_nav_categories() finds real categories they are used
	 * instead. Slides are bucketed by keyword match against the title. The
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
