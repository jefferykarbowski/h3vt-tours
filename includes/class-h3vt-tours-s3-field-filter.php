<?php
/**
 * ACF field filters for S3 media storage.
 *
 * Serializes S3 metadata on save and deserializes on retrieval,
 * allowing ACF image/file fields to work transparently with S3 URLs.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into ACF update_value, load_value, and format_value to handle S3-stored media.
 *
 * ACF's native image/file handlers call acf_idval() on save (converts everything
 * to an attachment ID) and acf_get_attachment() on format (looks up the attachment).
 * This class intercepts at all three stages to preserve S3 JSON values:
 *
 * - load_value (priority 5): Decodes JSON strings from postmeta into arrays before
 *   ACF's default handlers can interpret them as attachment IDs.
 * - update_value (priority 5): Encodes S3 arrays to JSON and suspends ACF's
 *   acf_idval() handler to prevent conversion to integer 0.
 * - format_value (priority 5): Passes decoded S3 arrays through and suspends
 *   ACF's acf_get_attachment() handler that would fail on attachment ID 0.
 */
class H3VT_Tours_S3_Field_Filter {

	/**
	 * Temporarily removed ACF handler callbacks, keyed by hook name.
	 *
	 * @var array
	 */
	private $removed_handlers = array();

	/**
	 * Constructor — registers ACF filters.
	 */
	public function __construct() {
		if ( ! H3VT_Tours_S3::is_configured() ) {
			return;
		}

		foreach ( array( 'image', 'file' ) as $type ) {
			// Save: encode S3 array to JSON, bypass ACF's acf_idval().
			add_filter( "acf/update_value/type={$type}", array( $this, 'update_value' ), 5, 3 );
			add_filter( "acf/update_value/type={$type}", array( $this, 'restore_handler' ), 12, 3 );

			// Load: decode JSON at load stage before ACF can convert it.
			add_filter( "acf/load_value/type={$type}", array( $this, 'load_value' ), 5, 3 );

			// Format: bypass ACF's acf_get_attachment() for S3 values.
			add_filter( "acf/format_value/type={$type}", array( $this, 'format_value' ), 5, 3 );
			add_filter( "acf/format_value/type={$type}", array( $this, 'restore_handler' ), 12, 3 );

			// Render: inject S3 data attribute so the JS uploader can show previews.
			add_action( "acf/render_field/type={$type}", array( $this, 'render_s3_data' ), 20 );
		}
	}

	/**
	 * Check whether a value is S3 media data.
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	private function is_s3_value( $value ) {
		if ( is_array( $value ) ) {
			return isset( $value['id'] )
				&& 0 === (int) $value['id']
				&& ! empty( $value['url'] );
		}

		if ( is_string( $value ) && '' !== $value && '{' === $value[0] ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded )
				&& isset( $decoded['id'] )
				&& 0 === (int) $decoded['id']
				&& ! empty( $decoded['url'] );
		}

		return false;
	}

	/**
	 * Remove ACF's native handler at a given priority for a given hook.
	 *
	 * @param string $hook     Filter hook name.
	 * @param int    $priority Priority to check.
	 * @param array  $methods  Method names to match.
	 */
	private function suspend_acf_handler( $hook, $priority = 10, $methods = array( 'update_value', 'format_value', 'load_value' ) ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ]->callbacks[ $priority ] ) ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks[ $priority ] as $id => $callback ) {
			if ( ! is_array( $callback['function'] )
				|| ! is_object( $callback['function'][0] )
			) {
				continue;
			}

			$method = $callback['function'][1];
			if ( ! in_array( $method, $methods, true ) ) {
				continue;
			}

			$class = get_class( $callback['function'][0] );
			if ( false !== strpos( $class, 'acf_field' ) ) {
				$this->removed_handlers[ $hook ][ $priority ][ $id ] = $callback;
				unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] );
			}
		}
	}

	/**
	 * Restore ACF handlers that were suspended. Runs at priority 12.
	 *
	 * @param mixed $value   Passthrough.
	 * @param int   $post_id Post ID.
	 * @param array $field   Field config.
	 * @return mixed
	 */
	public function restore_handler( $value, $post_id, $field ) {
		$hook = current_filter();

		if ( ! empty( $this->removed_handlers[ $hook ] ) ) {
			global $wp_filter;
			foreach ( $this->removed_handlers[ $hook ] as $priority => $handlers ) {
				foreach ( $handlers as $id => $callback ) {
					$wp_filter[ $hook ]->callbacks[ $priority ][ $id ] = $callback;
				}
			}
			unset( $this->removed_handlers[ $hook ] );
		}

		return $value;
	}

	/**
	 * Save filter: encode S3 arrays to JSON, bypass ACF's acf_idval().
	 *
	 * @param mixed $value   Value to save.
	 * @param int   $post_id Post ID.
	 * @param array $field   Field config.
	 * @return mixed
	 */
	public function update_value( $value, $post_id, $field ) {
		if ( ! $this->is_s3_value( $value ) ) {
			return $value;
		}

		$this->suspend_acf_handler( "acf/update_value/type={$field['type']}" );

		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}

		return $value;
	}

	/**
	 * Load filter: decode S3 JSON at the load stage.
	 *
	 * ACF's load_value pipeline runs before format_value. We intercept here
	 * to decode JSON strings into arrays that will pass through intact.
	 *
	 * @param mixed $value   Raw meta value from the database.
	 * @param int   $post_id Post ID.
	 * @param array $field   Field config.
	 * @return mixed
	 */
	public function load_value( $value, $post_id, $field ) {
		if ( ! is_string( $value ) || '' === $value || '{' !== $value[0] ) {
			return $value;
		}

		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) || empty( $decoded['url'] ) ) {
			return $value;
		}

		return wp_parse_args( $decoded, array(
			'id'        => 0,
			'url'       => '',
			'alt'       => '',
			'title'     => '',
			'filename'  => '',
			'mime_type' => '',
			'width'     => 0,
			'height'    => 0,
		) );
	}

	/**
	 * Format filter: handle S3 values, bypass ACF's acf_get_attachment().
	 *
	 * At this stage, the value may be:
	 * - An array (decoded by load_value) — suspend ACF's handler, return as-is.
	 * - A JSON string (fallback) — decode and return.
	 * - An integer/other — pass through to ACF's default handler.
	 *
	 * @param mixed $value   Value from load stage.
	 * @param int   $post_id Post ID.
	 * @param array $field   Field config.
	 * @return mixed
	 */
	public function format_value( $value, $post_id, $field ) {
		// Already decoded by load_value.
		if ( is_array( $value ) && isset( $value['id'] ) && 0 === (int) $value['id'] && ! empty( $value['url'] ) ) {
			$this->suspend_acf_handler( "acf/format_value/type={$field['type']}" );
			return $value;
		}

		// Fallback: JSON string that wasn't caught by load_value.
		if ( is_string( $value ) && '' !== $value && '{' === $value[0] ) {
			$decoded = json_decode( $value, true );

			if ( is_array( $decoded ) && ! empty( $decoded['url'] ) ) {
				$this->suspend_acf_handler( "acf/format_value/type={$field['type']}" );

				return wp_parse_args( $decoded, array(
					'id'        => 0,
					'url'       => '',
					'alt'       => '',
					'title'     => '',
					'filename'  => '',
					'mime_type' => '',
					'width'     => 0,
					'height'    => 0,
				) );
			}
		}

		return $value;
	}

	/**
	 * Inject a hidden element with S3 data after ACF renders the field.
	 *
	 * ACF's render_field outputs the hidden input with just the attachment ID (0),
	 * so the JS uploader cannot detect S3 values from the input alone. This method
	 * reads the raw postmeta, and if it contains S3 JSON data, outputs a hidden
	 * element that the JS can read to show the preview on page load.
	 *
	 * @param array $field ACF field config.
	 */
	public function render_s3_data( $field ) {
		// Only on admin edit screens.
		if ( ! is_admin() ) {
			return;
		}

		$post_id = 0;
		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $post_id || empty( $field['name'] ) ) {
			return;
		}

		// For repeater sub-fields, get the raw value from the field's value (already loaded).
		$value = $field['value'];

		// Check if the loaded value is an S3 array.
		if ( is_array( $value ) && isset( $value['id'] ) && 0 === (int) $value['id'] && ! empty( $value['url'] ) ) {
			$json = wp_json_encode( $value );
			printf(
				'<div class="h3vt-s3-data" data-s3-value="%s" style="display:none;"></div>',
				esc_attr( $json )
			);
			return;
		}

		// Fallback: check raw postmeta for top-level fields.
		$raw = get_post_meta( $post_id, $field['name'], true );

		if ( ! is_string( $raw ) || '' === $raw || '{' !== $raw[0] ) {
			return;
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded['url'] ) || ! isset( $decoded['id'] ) || 0 !== (int) $decoded['id'] ) {
			return;
		}

		$json = wp_json_encode( $decoded );
		printf(
			'<div class="h3vt-s3-data" data-s3-value="%s" style="display:none;"></div>',
			esc_attr( $json )
		);
	}
}
