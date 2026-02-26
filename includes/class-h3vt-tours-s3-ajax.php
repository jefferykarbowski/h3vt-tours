<?php
/**
 * AJAX endpoints for S3 presigned URL generation and connection testing.
 *
 * Also handles enqueuing the S3 uploader assets on tour edit screens.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers AJAX handlers and enqueues the S3 uploader script.
 */
class H3VT_Tours_S3_Ajax {

	/**
	 * Allowed MIME types for S3 upload.
	 *
	 * @var array
	 */
	private $allowed_types = array(
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
		'image/svg+xml',
		'video/mp4',
		'video/webm',
	);

	/**
	 * Max upload size in bytes (100 MB).
	 *
	 * @var int
	 */
	private $max_size = 104857600;

	/**
	 * Constructor — registers AJAX actions and asset enqueue.
	 */
	public function __construct() {
		add_action( 'wp_ajax_h3vt_tours_s3_presign', array( $this, 'handle_presign' ) );
		add_action( 'wp_ajax_h3vt_tours_s3_test', array( $this, 'handle_test' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_uploader' ) );
	}

	/**
	 * Handle presign AJAX request.
	 *
	 * Validates nonce, capabilities, file type, and returns a presigned URL.
	 */
	public function handle_presign() {
		check_ajax_referer( 'h3vt_s3_upload', '_ajax_nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'h3vt-tours' ) ), 403 );
		}

		$filename     = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
		$content_type = sanitize_mime_type( wp_unslash( $_POST['content_type'] ?? '' ) );
		$post_id      = absint( $_POST['post_id'] ?? 0 );

		if ( empty( $filename ) || empty( $content_type ) || ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'h3vt-tours' ) ), 400 );
		}

		if ( ! in_array( $content_type, $this->allowed_types, true ) ) {
			wp_send_json_error( array(
				'message' => __( 'File type not allowed.', 'h3vt-tours' ),
			), 400 );
		}

		$object_key = H3VT_Tours_S3::generate_object_key( $filename, $post_id );
		$presigned  = H3VT_Tours_S3::generate_presigned_put_url( $object_key, $content_type );

		if ( is_wp_error( $presigned ) ) {
			wp_send_json_error( array( 'message' => $presigned->get_error_message() ), 500 );
		}

		$object_url = H3VT_Tours_S3::get_object_url( $object_key );

		wp_send_json_success( array(
			'presigned_url' => $presigned,
			'object_url'    => $object_url,
			'object_key'    => $object_key,
		) );
	}

	/**
	 * Handle S3 connection test AJAX request.
	 *
	 * Attempts a presigned PUT to verify bucket access.
	 */
	public function handle_test() {
		check_ajax_referer( 'h3vt_s3_test', '_ajax_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'h3vt-tours' ) ), 403 );
		}

		if ( ! H3VT_Tours_S3::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'S3 is not configured.', 'h3vt-tours' ) ), 400 );
		}

		$test_key   = 'h3vt-tours/_connection-test.txt';
		$presigned  = H3VT_Tours_S3::generate_presigned_put_url( $test_key, 'text/plain', 60 );

		if ( is_wp_error( $presigned ) ) {
			wp_send_json_error( array( 'message' => $presigned->get_error_message() ), 500 );
		}

		// Attempt PUT.
		$put_response = wp_remote_request( $presigned, array(
			'method'  => 'PUT',
			'body'    => 'h3vt-tours connection test',
			'headers' => array(
				'Content-Type' => 'text/plain',
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $put_response ) ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'PUT failed: %s', 'h3vt-tours' ),
					$put_response->get_error_message()
				),
			), 500 );
		}

		$put_code = wp_remote_retrieve_response_code( $put_response );

		if ( $put_code < 200 || $put_code >= 300 ) {
			$body = wp_remote_retrieve_body( $put_response );
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: 1: HTTP status code, 2: response body */
					__( 'PUT returned HTTP %1$d. Response: %2$s', 'h3vt-tours' ),
					$put_code,
					wp_strip_all_tags( substr( $body, 0, 200 ) )
				),
			), 500 );
		}

		// Verify with HEAD request.
		$object_url   = H3VT_Tours_S3::get_object_url( $test_key );
		$head_response = wp_remote_head( $object_url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $head_response ) ) {
			wp_send_json_success( array(
				'message' => __( 'PUT succeeded, but HEAD verification failed. Check bucket policy for public read access.', 'h3vt-tours' ),
			) );
		}

		$head_code = wp_remote_retrieve_response_code( $head_response );

		if ( 200 === $head_code ) {
			wp_send_json_success( array(
				'message' => __( 'Connection successful! Upload and read access verified.', 'h3vt-tours' ),
			) );
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: HTTP status code */
				__( 'PUT succeeded but public read returned HTTP %d. Check your bucket policy.', 'h3vt-tours' ),
				$head_code
			),
		) );
	}

	/**
	 * Enqueue the S3 uploader script on tour/template edit screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_uploader( $hook ) {
		if ( ! H3VT_Tours_S3::is_configured() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$allowed_types = array( 'h3vt_tour', 'h3vt_tour_template' );
		if ( ! in_array( $screen->post_type, $allowed_types, true ) ) {
			return;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_enqueue_style(
			'h3vt-s3-uploader',
			H3VT_TOURS_URL . 'assets/admin/css/h3vt-s3-uploader.css',
			array(),
			H3VT_TOURS_VERSION
		);

		$asset_file = H3VT_TOURS_PATH . 'assets/admin/js/s3-uploader.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => H3VT_TOURS_VERSION,
		);

		// Ensure acf-input is a dependency.
		if ( ! in_array( 'acf-input', $asset['dependencies'], true ) ) {
			$asset['dependencies'][] = 'acf-input';
		}

		wp_enqueue_script(
			'h3vt-s3-uploader',
			H3VT_TOURS_URL . 'assets/admin/js/s3-uploader.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$image_types = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml' );
		$video_types = array( 'video/mp4', 'video/webm' );

		wp_localize_script( 'h3vt-s3-uploader', 'h3vtS3Config', array(
			'enabled' => true,
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'h3vt_s3_upload' ),
			'postId'  => $post_id,
			'maxSize' => $this->max_size,
			'allowed' => array(
				'image' => $image_types,
				'video' => $video_types,
			),
		) );
	}
}
