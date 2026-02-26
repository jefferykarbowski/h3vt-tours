<?php
/**
 * Admin settings page for S3 configuration.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a submenu page under H3VT Tours for S3 settings.
 */
class H3VT_Tours_Settings {

	/**
	 * AWS regions available for selection.
	 *
	 * @var array
	 */
	private $regions = array(
		'us-east-1'      => 'US East (N. Virginia)',
		'us-east-2'      => 'US East (Ohio)',
		'us-west-1'      => 'US West (N. California)',
		'us-west-2'      => 'US West (Oregon)',
		'af-south-1'     => 'Africa (Cape Town)',
		'ap-east-1'      => 'Asia Pacific (Hong Kong)',
		'ap-south-1'     => 'Asia Pacific (Mumbai)',
		'ap-northeast-1' => 'Asia Pacific (Tokyo)',
		'ap-northeast-2' => 'Asia Pacific (Seoul)',
		'ap-northeast-3' => 'Asia Pacific (Osaka)',
		'ap-southeast-1' => 'Asia Pacific (Singapore)',
		'ap-southeast-2' => 'Asia Pacific (Sydney)',
		'ca-central-1'   => 'Canada (Central)',
		'eu-central-1'   => 'Europe (Frankfurt)',
		'eu-west-1'      => 'Europe (Ireland)',
		'eu-west-2'      => 'Europe (London)',
		'eu-west-3'      => 'Europe (Paris)',
		'eu-north-1'     => 'Europe (Stockholm)',
		'eu-south-1'     => 'Europe (Milan)',
		'me-south-1'     => 'Middle East (Bahrain)',
		'sa-east-1'      => 'South America (Sao Paulo)',
	);

	/**
	 * Constructor — hooks admin menu and settings registration.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the S3 Settings submenu under the H3VT Tours CPT menu.
	 */
	public function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=h3vt_tour',
			__( 'S3 Settings', 'h3vt-tours' ),
			__( 'S3 Settings', 'h3vt-tours' ),
			'manage_options',
			'h3vt-tours-s3-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings group.
	 */
	public function register_settings() {
		register_setting(
			'h3vt_tours_s3',
			H3VT_Tours_S3::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);

		add_settings_section(
			'h3vt_s3_credentials',
			__( 'AWS Credentials', 'h3vt-tours' ),
			'__return_null',
			'h3vt-tours-s3-settings'
		);

		add_settings_field(
			'access_key',
			__( 'Access Key ID', 'h3vt-tours' ),
			array( $this, 'render_text_field' ),
			'h3vt-tours-s3-settings',
			'h3vt_s3_credentials',
			array(
				'field'       => 'access_key',
				'type'        => 'text',
				'placeholder' => 'AKIA...',
			)
		);

		add_settings_field(
			'secret_key',
			__( 'Secret Access Key', 'h3vt-tours' ),
			array( $this, 'render_text_field' ),
			'h3vt-tours-s3-settings',
			'h3vt_s3_credentials',
			array(
				'field'       => 'secret_key',
				'type'        => 'password',
				'placeholder' => __( 'Leave blank to keep current value', 'h3vt-tours' ),
			)
		);

		add_settings_field(
			'region',
			__( 'Region', 'h3vt-tours' ),
			array( $this, 'render_region_field' ),
			'h3vt-tours-s3-settings',
			'h3vt_s3_credentials'
		);

		add_settings_field(
			'bucket',
			__( 'Bucket Name', 'h3vt-tours' ),
			array( $this, 'render_text_field' ),
			'h3vt-tours-s3-settings',
			'h3vt_s3_credentials',
			array(
				'field'       => 'bucket',
				'type'        => 'text',
				'placeholder' => 'my-bucket-name',
			)
		);

		add_settings_field(
			'cloudfront',
			__( 'CloudFront Domain', 'h3vt-tours' ),
			array( $this, 'render_text_field' ),
			'h3vt-tours-s3-settings',
			'h3vt_s3_credentials',
			array(
				'field'       => 'cloudfront',
				'type'        => 'text',
				'placeholder' => 'd1234abcdef.cloudfront.net',
				'description' => __( 'Optional. If set, media URLs will use this domain instead of the S3 bucket URL.', 'h3vt-tours' ),
			)
		);
	}

	/**
	 * Sanitize and process settings before saving.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$existing = get_option( H3VT_Tours_S3::OPTION_KEY, array() );
		$output   = array();

		$output['access_key'] = sanitize_text_field( $input['access_key'] ?? '' );
		$output['region']     = sanitize_text_field( $input['region'] ?? 'us-east-1' );
		$output['bucket']     = sanitize_text_field( $input['bucket'] ?? '' );
		$output['cloudfront'] = sanitize_text_field( $input['cloudfront'] ?? '' );

		// Strip protocol from CloudFront domain if present.
		$output['cloudfront'] = preg_replace( '#^https?://#', '', $output['cloudfront'] );
		$output['cloudfront'] = rtrim( $output['cloudfront'], '/' );

		// Encrypt secret key, or preserve existing if field left blank.
		$raw_secret = $input['secret_key'] ?? '';
		if ( ! empty( $raw_secret ) ) {
			$output['secret_key'] = H3VT_Tours_S3::encrypt( $raw_secret );
		} else {
			$output['secret_key'] = $existing['secret_key'] ?? '';
		}

		return $output;
	}

	/**
	 * Render a text or password input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_text_field( $args ) {
		$settings = get_option( H3VT_Tours_S3::OPTION_KEY, array() );
		$field    = $args['field'];
		$type     = $args['type'] ?? 'text';

		// Don't show the encrypted secret key value.
		$value = ( 'secret_key' === $field ) ? '' : ( $settings[ $field ] ?? '' );

		printf(
			'<input type="%s" name="%s[%s]" value="%s" class="regular-text" placeholder="%s">',
			esc_attr( $type ),
			esc_attr( H3VT_Tours_S3::OPTION_KEY ),
			esc_attr( $field ),
			esc_attr( $value ),
			esc_attr( $args['placeholder'] ?? '' )
		);

		if ( 'secret_key' === $field && ! empty( $settings['secret_key'] ) ) {
			echo '<p class="description">' . esc_html__( 'A secret key is currently stored. Leave blank to keep it unchanged.', 'h3vt-tours' ) . '</p>';
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render the region select field.
	 */
	public function render_region_field() {
		$settings = get_option( H3VT_Tours_S3::OPTION_KEY, array() );
		$current  = $settings['region'] ?? 'us-east-1';

		echo '<select name="' . esc_attr( H3VT_Tours_S3::OPTION_KEY ) . '[region]">';
		foreach ( $this->regions as $code => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $code ),
				selected( $current, $code, false ),
				esc_html( $label . ' — ' . $code )
			);
		}
		echo '</select>';
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'H3VT Tours — S3 Settings', 'h3vt-tours' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'h3vt_tours_s3' );
				do_settings_sections( 'h3vt-tours-s3-settings' );
				submit_button();
				?>
			</form>

			<?php if ( H3VT_Tours_S3::is_configured() ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Connection Test', 'h3vt-tours' ); ?></h2>
				<p>
					<button type="button" id="h3vt-s3-test-btn" class="button button-secondary">
						<?php esc_html_e( 'Test Connection', 'h3vt-tours' ); ?>
					</button>
					<span id="h3vt-s3-test-result" style="margin-left:10px;"></span>
				</p>
				<script>
				(function(){
					document.getElementById('h3vt-s3-test-btn').addEventListener('click', function(){
						var btn = this;
						var result = document.getElementById('h3vt-s3-test-result');
						btn.disabled = true;
						result.textContent = '<?php echo esc_js( __( 'Testing...', 'h3vt-tours' ) ); ?>';
						result.style.color = '';

						var xhr = new XMLHttpRequest();
						xhr.open('POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>');
						xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
						xhr.onload = function(){
							btn.disabled = false;
							try {
								var resp = JSON.parse(xhr.responseText);
								if (resp.success) {
									result.textContent = resp.data.message || 'Connection successful!';
									result.style.color = 'green';
								} else {
									result.textContent = (resp.data && resp.data.message) || 'Connection failed.';
									result.style.color = 'red';
								}
							} catch(e) {
								result.textContent = 'Unexpected response.';
								result.style.color = 'red';
							}
						};
						xhr.onerror = function(){
							btn.disabled = false;
							result.textContent = 'Request failed.';
							result.style.color = 'red';
						};
						xhr.send('action=h3vt_tours_s3_test&_ajax_nonce=<?php echo esc_js( wp_create_nonce( 'h3vt_s3_test' ) ); ?>');
					});
				})();
				</script>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'S3 Bucket Configuration Guide', 'h3vt-tours' ); ?></h2>
			<?php $this->render_config_guide(); ?>
		</div>
		<?php
	}

	/**
	 * Render the collapsible configuration guide.
	 */
	private function render_config_guide() {
		$settings = get_option( H3VT_Tours_S3::OPTION_KEY, array() );
		$bucket   = ! empty( $settings['bucket'] ) ? $settings['bucket'] : 'YOUR-BUCKET-NAME';
		$site_url = home_url();
		?>
		<details style="margin-top:10px;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Bucket Policy (public read for tour media)', 'h3vt-tours' ); ?></summary>
			<pre style="background:#f0f0f1;padding:12px;overflow-x:auto;">{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "H3VTPublicRead",
            "Effect": "Allow",
            "Principal": "*",
            "Action": "s3:GetObject",
            "Resource": "arn:aws:s3:::<?php echo esc_html( $bucket ); ?>/h3vt-tours/*"
        }
    ]
}</pre>
		</details>

		<details style="margin-top:10px;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'CORS Configuration', 'h3vt-tours' ); ?></summary>
			<pre style="background:#f0f0f1;padding:12px;overflow-x:auto;">[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["PUT", "HEAD"],
        "AllowedOrigins": ["<?php echo esc_html( $site_url ); ?>"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]</pre>
		</details>

		<details style="margin-top:10px;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'IAM Policy (minimum permissions)', 'h3vt-tours' ); ?></summary>
			<pre style="background:#f0f0f1;padding:12px;overflow-x:auto;">{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::<?php echo esc_html( $bucket ); ?>/h3vt-tours/*"
        }
    ]
}</pre>
		</details>
		<?php
	}
}
