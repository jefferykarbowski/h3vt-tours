<?php
/**
 * "Tour Analytics" section on the WordPress user profile.
 *
 * Lets an administrator assign tours to a client, pick how often the client
 * gets the analytics email, preview it, and send one immediately. Replaces
 * the "3D Tour Settings" section from h3-tour-management. Only users who can
 * manage other users see or change it, so clients cannot edit their own
 * assignments.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profile fields for client tour assignments and report frequency.
 */
class H3VT_Tours_User_Fields {

	const NONCE = 'h3vt_tours_user_fields';

	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the current user may manage a client's analytics settings.
	 *
	 * @param int $user_id Profile being edited.
	 * @return bool
	 */
	private static function can_manage( $user_id ) {
		return current_user_can( 'list_users' ) && current_user_can( 'edit_user', $user_id );
	}

	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) || ! H3VT_Tours_Client_Analytics::is_enabled() ) {
			return;
		}
		wp_enqueue_script( 'h3vt-tours-select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array( 'jquery' ), '4.1.0-rc.0', true );
		wp_enqueue_style( 'h3vt-tours-select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0-rc.0' );
	}

	/**
	 * Choices for the tours field.
	 *
	 * @param string[] $assigned Currently assigned keys, kept selectable even if
	 *                           they would otherwise be hidden.
	 * @return array group label => array( key => label )
	 */
	public static function tour_options( array $assigned = array() ) {
		$tours   = array();
		$claimed = array();
		$posts   = get_posts(
			array(
				'post_type'   => 'h3vt_tour',
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$label = H3VT_Tours_Client_Analytics::post_title( $post );
			if ( $post->post_status !== 'publish' ) {
				$label .= ' (' . get_post_status_object( $post->post_status )->label . ')';
			}
			$tours[ H3VT_Tours_Client_Analytics::tour_key( $post->ID ) ] = $label;
			$claimed = array_merge( $claimed, H3VT_Tours_Client_Analytics::get_legacy_paths( $post->ID ) );
		}

		// Old S3 tours that have no tour post of their own yet.
		$legacy = array();
		foreach ( H3VT_Tours_Client_Analytics::legacy_catalog() as $path => $name ) {
			$key = H3VT_Tours_Client_Analytics::legacy_key( $path );
			if ( ! in_array( $path, $claimed, true ) || in_array( $key, $assigned, true ) ) {
				$legacy[ $key ] = $name;
			}
		}

		return array(
			__( 'Tours', 'h3vt-tours' )                      => $tours,
			__( 'Older tours (not rebuilt)', 'h3vt-tours' ) => $legacy,
		);
	}

	/**
	 * Output the profile section.
	 *
	 * @param WP_User $user Profile being shown.
	 */
	public function render( $user ) {
		if ( ! self::can_manage( $user->ID ) ) {
			return;
		}

		$enabled   = H3VT_Tours_Client_Analytics::is_enabled();
		$assigned  = H3VT_Tours_Client_Analytics::get_assigned( $user->ID );
		$frequency = H3VT_Tours_Client_Analytics::get_frequency( $user->ID );
		$last_sent = get_user_meta( $user->ID, H3VT_Tours_Client_Analytics::META_LAST_SENT, true );
		$disabled  = $enabled ? '' : ' disabled';
		?>
		<h2 id="h3vt-tours-analytics"><?php esc_html_e( 'Tour Analytics', 'h3vt-tours' ); ?></h2>

		<?php if ( ! $enabled ) : ?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'H3 Tour Management is still active, so it keeps sending client reports and its own "3D Tour Settings" fields still apply. These settings take over once it is deactivated. Copy its assignments here first with: wp h3vt-tours client-analytics migrate', 'h3vt-tours' ); ?>
			</p></div>
		<?php endif; ?>

		<?php wp_nonce_field( self::NONCE, 'h3vt_tours_user_fields_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="h3vt-tours-assigned"><?php esc_html_e( 'Assigned tours', 'h3vt-tours' ); ?></label></th>
				<td>
					<select name="h3vt_tours_assigned[]" id="h3vt-tours-assigned" multiple="multiple" style="width: 100%; max-width: 480px; min-height: 160px;"<?php echo esc_attr( $disabled ); ?>>
						<?php foreach ( self::tour_options( $assigned ) as $group => $options ) : ?>
							<?php if ( $options ) : ?>
								<optgroup label="<?php echo esc_attr( $group ); ?>">
									<?php foreach ( $options as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( in_array( $key, $assigned, true ) ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Tours this client sees on the Tour Analytics page and in their emails. A rebuilt tour also counts views of its older version.', 'h3vt-tours' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="h3vt-tours-frequency"><?php esc_html_e( 'Email frequency', 'h3vt-tours' ); ?></label></th>
				<td>
					<select name="h3vt_tours_email_frequency" id="h3vt-tours-frequency"<?php echo esc_attr( $disabled ); ?>>
						<?php foreach ( H3VT_Tours_Client_Analytics::frequencies() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $frequency, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php
						if ( $last_sent ) {
							/* translators: %s: date and time */
							echo esc_html( sprintf( __( 'Last report sent %s.', 'h3vt-tours' ), mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sent ) ) );
						} else {
							esc_html_e( 'No report sent yet.', 'h3vt-tours' );
						}
						?>
					</p>
				</td>
			</tr>
			<?php if ( $assigned ) : ?>
			<tr>
				<th><?php esc_html_e( 'Analytics email', 'h3vt-tours' ); ?></th>
				<td>
					<a class="button" href="<?php echo esc_url( H3VT_Tours_Client_Reports::preview_url( $user->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'h3vt-tours' ); ?></a>
					<?php if ( $enabled ) : ?>
						<button type="button" class="button button-primary" id="h3vt-tours-send-report"><?php esc_html_e( 'Send now', 'h3vt-tours' ); ?></button>
						<span class="spinner" id="h3vt-tours-send-spinner" style="float: none;"></span>
					<?php endif; ?>
					<p class="description" id="h3vt-tours-send-result" aria-live="polite"><?php esc_html_e( 'Preview uses the saved assignments. Save changes first.', 'h3vt-tours' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
		</table>

		<?php if ( $enabled ) : ?>
		<script>
		jQuery(function ($) {
			if ($.fn.select2) {
				$('#h3vt-tours-assigned').select2({ placeholder: <?php echo wp_json_encode( __( 'Select tours', 'h3vt-tours' ) ); ?> });
			}
			$('#h3vt-tours-send-report').on('click', function () {
				var $button = $(this), $spinner = $('#h3vt-tours-send-spinner'), $result = $('#h3vt-tours-send-result');
				$button.prop('disabled', true);
				$spinner.addClass('is-active');
				$.post(ajaxurl, {
					action: 'h3vt_tours_send_client_report',
					user_id: <?php echo (int) $user->ID; ?>,
					nonce: <?php echo wp_json_encode( wp_create_nonce( H3VT_Tours_Client_Reports::SEND_NONCE ) ); ?>
				}).done(function (response) {
					$result.text(response.data).css('color', response.success ? '#008a20' : '#d63638');
				}).fail(function () {
					$result.text(<?php echo wp_json_encode( __( 'The report could not be sent.', 'h3vt-tours' ) ); ?>).css('color', '#d63638');
				}).always(function () {
					$button.prop('disabled', false);
					$spinner.removeClass('is-active');
				});
			});
		});
		</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the profile section.
	 *
	 * @param int $user_id Profile being saved.
	 */
	public function save( $user_id ) {
		if ( ! H3VT_Tours_Client_Analytics::is_enabled() || ! self::can_manage( $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['h3vt_tours_user_fields_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['h3vt_tours_user_fields_nonce'] ), self::NONCE ) ) {
			return;
		}

		$keys = isset( $_POST['h3vt_tours_assigned'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['h3vt_tours_assigned'] ) ) : array();
		H3VT_Tours_Client_Analytics::set_assigned( $user_id, $keys );

		if ( isset( $_POST['h3vt_tours_email_frequency'] ) ) {
			H3VT_Tours_Client_Analytics::set_frequency( $user_id, sanitize_key( wp_unslash( $_POST['h3vt_tours_email_frequency'] ) ) );
		}
	}
}
