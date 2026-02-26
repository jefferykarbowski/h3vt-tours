<?php
/**
 * Settings page for H3VT Tours Embed.
 *
 * @package H3VT_Tours_Embed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a Settings → H3VT Tours page for configuring the host URL and cache.
 */
class H3VT_Embed_Settings {

	/** Option key stored in wp_options. */
	const OPTION = 'h3vt_embed_settings';

	/**
	 * Constructor — hooks admin menu and settings registration.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Add settings page under the Settings menu.
	 */
	public function add_menu() {
		add_options_page(
			__( 'H3VT Tours Embed', 'h3vt-tours-embed' ),
			__( 'H3VT Tours', 'h3vt-tours-embed' ),
			'manage_options',
			'h3vt-tours-embed',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings, section, and fields.
	 */
	public function register_settings() {
		register_setting( 'h3vt_embed', self::OPTION, array(
			'sanitize_callback' => array( $this, 'sanitize' ),
		) );

		add_settings_section(
			'h3vt_embed_main',
			__( 'Connection Settings', 'h3vt-tours-embed' ),
			'__return_null',
			'h3vt-tours-embed'
		);

		add_settings_field(
			'host_url',
			__( 'Tour Host URL', 'h3vt-tours-embed' ),
			array( $this, 'field_host_url' ),
			'h3vt-tours-embed',
			'h3vt_embed_main'
		);

		add_settings_field(
			'cache_duration',
			__( 'Cache Duration', 'h3vt-tours-embed' ),
			array( $this, 'field_cache_duration' ),
			'h3vt-tours-embed',
			'h3vt_embed_main'
		);
	}

	/**
	 * Sanitize the settings array before saving.
	 *
	 * @param array $input Raw form input.
	 * @return array Sanitized values.
	 */
	public function sanitize( $input ) {
		$clean = array();

		$clean['host_url'] = isset( $input['host_url'] )
			? esc_url_raw( untrailingslashit( trim( $input['host_url'] ) ) )
			: '';

		$valid_durations        = array( 3600, 21600, 86400, 604800, 0 );
		$clean['cache_duration'] = isset( $input['cache_duration'] ) && in_array( (int) $input['cache_duration'], $valid_durations, true )
			? (int) $input['cache_duration']
			: 86400;

		return $clean;
	}

	/**
	 * Render the host URL field.
	 */
	public function field_host_url() {
		$opts = get_option( self::OPTION, array() );
		$val  = isset( $opts['host_url'] ) ? $opts['host_url'] : '';
		printf(
			'<input type="url" name="%s[host_url]" value="%s" class="regular-text" placeholder="https://tours.example.com" />',
			esc_attr( self::OPTION ),
			esc_attr( $val )
		);
		echo '<p class="description">' . esc_html__( 'The WordPress site running the full H3VT Tours plugin.', 'h3vt-tours-embed' ) . '</p>';
	}

	/**
	 * Render the cache duration field.
	 */
	public function field_cache_duration() {
		$opts     = get_option( self::OPTION, array() );
		$current  = isset( $opts['cache_duration'] ) ? (int) $opts['cache_duration'] : 86400;
		$choices  = array(
			3600   => __( '1 Hour', 'h3vt-tours-embed' ),
			21600  => __( '6 Hours', 'h3vt-tours-embed' ),
			86400  => __( '24 Hours', 'h3vt-tours-embed' ),
			604800 => __( '1 Week', 'h3vt-tours-embed' ),
			0      => __( 'No Cache', 'h3vt-tours-embed' ),
		);

		printf( '<select name="%s[cache_duration]">', esc_attr( self::OPTION ) );
		foreach ( $choices as $val => $label ) {
			printf(
				'<option value="%d"%s>%s</option>',
				$val,
				selected( $current, $val, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Handle Clear Cache and Test Connection actions.
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Clear Cache.
		if ( isset( $_POST['h3vt_embed_clear_cache'] ) && check_admin_referer( 'h3vt_embed_clear_cache' ) ) {
			$this->clear_transients();
			add_settings_error( 'h3vt_embed', 'cache_cleared', __( 'Tour cache cleared.', 'h3vt-tours-embed' ), 'success' );
		}

		// Test Connection.
		if ( isset( $_POST['h3vt_embed_test_connection'] ) && check_admin_referer( 'h3vt_embed_test_connection' ) ) {
			$this->test_connection();
		}
	}

	/**
	 * Delete all h3vt_embed transients.
	 */
	private function clear_transients() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_h3vt_embed_%',
				'_transient_timeout_h3vt_embed_%'
			)
		);
	}

	/**
	 * Test the connection to the host site REST API.
	 */
	private function test_connection() {
		$opts     = get_option( self::OPTION, array() );
		$host_url = isset( $opts['host_url'] ) ? $opts['host_url'] : '';

		if ( empty( $host_url ) ) {
			add_settings_error( 'h3vt_embed', 'no_host', __( 'Please save a Tour Host URL first.', 'h3vt-tours-embed' ), 'error' );
			return;
		}

		$response = wp_remote_get( $host_url . '/wp-json/h3vt-tours/v1/', array(
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			add_settings_error(
				'h3vt_embed',
				'connection_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Connection failed: %s', 'h3vt-tours-embed' ),
					$response->get_error_message()
				),
				'error'
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			add_settings_error( 'h3vt_embed', 'connection_ok', __( 'Connection successful!', 'h3vt-tours-embed' ), 'success' );
		} else {
			add_settings_error(
				'h3vt_embed',
				'connection_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Unexpected response (HTTP %d). Verify the host URL.', 'h3vt-tours-embed' ),
					$code
				),
				'error'
			);
		}
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'H3VT Tours Embed Settings', 'h3vt-tours-embed' ); ?></h1>

			<?php settings_errors( 'h3vt_embed' ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'h3vt_embed' );
				do_settings_sections( 'h3vt-tours-embed' );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Tools', 'h3vt-tours-embed' ); ?></h2>

			<form method="post" style="display:inline-block;margin-right:10px;">
				<?php wp_nonce_field( 'h3vt_embed_test_connection' ); ?>
				<?php submit_button( __( 'Test Connection', 'h3vt-tours-embed' ), 'secondary', 'h3vt_embed_test_connection', false ); ?>
			</form>

			<form method="post" style="display:inline-block;">
				<?php wp_nonce_field( 'h3vt_embed_clear_cache' ); ?>
				<?php submit_button( __( 'Clear Cache', 'h3vt-tours-embed' ), 'secondary', 'h3vt_embed_clear_cache', false ); ?>
			</form>
		</div>
		<?php
	}
}
