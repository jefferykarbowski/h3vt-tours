<?php
/**
 * Scheduled client analytics emails.
 *
 * A daily cron works out which clients are due a report (by their chosen
 * frequency) and emails each one a table per assigned tour. Replaces the
 * h3tm_analytics_cron job from h3-tour-management, with two fixes:
 *   - a report missed because the site was down on the day (the 1st, or a
 *     Sunday) still goes out within the catch-up window;
 *   - a client is only marked as sent when wp_mail() reports success.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron, due-date logic, email rendering and sending.
 */
class H3VT_Tours_Client_Reports {

	const CRON_HOOK  = 'h3vt_tours_client_report_cron';
	const LOCK       = 'h3vt_tours_client_report_lock';
	const LAST_RUN   = 'h3vt_tours_client_reports_last_run';
	const LOCK_TTL   = HOUR_IN_SECONDS;
	const SEND_NONCE = 'h3vt_tours_send_client_report';

	/**
	 * Days after the 1st (monthly) or Sunday (weekly) a missed report may still go out.
	 */
	const MONTHLY_CATCH_UP_DAYS = 7;
	const WEEKLY_CATCH_UP_DAYS  = 2;

	public function __construct() {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'wp_ajax_h3vt_tours_send_client_report', array( $this, 'ajax_send' ) );
		add_action( 'admin_post_h3vt_tours_client_report_preview', array( $this, 'preview' ) );
	}

	/**
	 * Keep the daily job scheduled for just after midnight, site time.
	 */
	public function schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		$first = new DateTimeImmutable( 'tomorrow 00:05', wp_timezone() );
		wp_schedule_event( $first->getTimestamp(), 'daily', self::CRON_HOOK );
	}

	/**
	 * Remove the job (plugin deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/*
	 * Who is due
	 */

	/**
	 * Every user with at least one assigned tour.
	 *
	 * @return int[]
	 */
	public static function users_with_tours() {
		return array_map(
			'intval',
			get_users(
				array(
					'meta_key'     => H3VT_Tours_Client_Analytics::META_ASSIGNED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare' => 'EXISTS',
					'fields'       => 'ID',
					'orderby'      => 'ID',
				)
			)
		);
	}

	/**
	 * Whether a user is due a report on a given day.
	 *
	 * @param int               $user_id User id.
	 * @param DateTimeImmutable $today   Site-local date (time ignored).
	 * @return bool
	 */
	public static function is_due( $user_id, DateTimeImmutable $today ) {
		$frequency = H3VT_Tours_Client_Analytics::get_frequency( $user_id );
		$last_raw  = get_user_meta( $user_id, H3VT_Tours_Client_Analytics::META_LAST_SENT, true );
		$last      = $last_raw ? substr( $last_raw, 0, 10 ) : '';
		$today_ymd = $today->format( 'Y-m-d' );

		switch ( $frequency ) {
			case 'daily':
				return $last < $today_ymd;

			case 'weekly':
				$since_sunday = (int) $today->format( 'w' );
				if ( $since_sunday > self::WEEKLY_CATCH_UP_DAYS ) {
					return false;
				}
				return $last < $today->modify( '-' . $since_sunday . ' days' )->format( 'Y-m-d' );

			case 'monthly':
				if ( (int) $today->format( 'j' ) > self::MONTHLY_CATCH_UP_DAYS ) {
					return false;
				}
				return $last < $today->format( 'Y-m-01' );
		}

		return false;
	}

	/**
	 * Users due a report today.
	 *
	 * @param DateTimeImmutable $today Site-local date.
	 * @return int[]
	 */
	public static function due_users( DateTimeImmutable $today ) {
		return array_values(
			array_filter(
				self::users_with_tours(),
				function ( $user_id ) use ( $today ) {
					return self::is_due( $user_id, $today );
				}
			)
		);
	}

	/**
	 * Today's date in the site timezone.
	 *
	 * @return DateTimeImmutable
	 */
	public static function today() {
		return new DateTimeImmutable( current_time( 'Y-m-d' ), wp_timezone() );
	}

	/*
	 * Cron run
	 */

	/**
	 * Send every report due today.
	 */
	public function run() {
		if ( ! H3VT_Tours_Client_Analytics::is_enabled() ) {
			return;
		}

		// One run at a time, in case WP-Cron and a system cron fire together.
		$locked_at = (int) get_option( self::LOCK, 0 );
		if ( $locked_at && ( time() - $locked_at ) < self::LOCK_TTL ) {
			return;
		}
		delete_option( self::LOCK );
		if ( ! add_option( self::LOCK, time(), '', 'no' ) ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$today = self::today();
		$users = self::due_users( $today );
		$log   = array(
			'started'  => current_time( 'mysql' ),
			'finished' => null,
			'queued'   => $users,
			'results'  => array(),
		);
		update_option( self::LAST_RUN, $log, false );

		foreach ( $users as $user_id ) {
			try {
				self::send( $user_id, $today );
				$log['results'][ $user_id ] = 'sent';
			} catch ( Exception $e ) {
				$log['results'][ $user_id ] = 'error: ' . $e->getMessage();
				error_log( sprintf( 'H3VT Tours client report for user %d failed: %s', $user_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			update_option( self::LAST_RUN, $log, false );
		}

		$log['finished'] = current_time( 'mysql' );
		update_option( self::LAST_RUN, $log, false );
		delete_option( self::LOCK );
	}

	/*
	 * Building and sending
	 */

	/**
	 * Build a user's report.
	 *
	 * @param int               $user_id User id.
	 * @param DateTimeImmutable $today   Site-local date the report is for.
	 * @return array array( 'to', 'subject', 'body', 'headers' )
	 * @throws Exception When the user or their tours cannot be found, or GA fails.
	 */
	public static function build( $user_id, DateTimeImmutable $today ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			throw new Exception( 'User not found.' );
		}

		$tours = H3VT_Tours_Client_Analytics::get_user_tours( $user_id );
		if ( ! $tours ) {
			throw new Exception( 'User has no assigned tours.' );
		}

		$today_ymd = $today->format( 'Y-m-d' );
		foreach ( $tours as &$tour ) {
			$tour['stats'] = H3VT_Tours_Client_Analytics::get_email_stats( $tour['paths'], $today_ymd );
		}
		unset( $tour );

		if ( count( $tours ) === 1 ) {
			/* translators: %s: tour name */
			$subject = sprintf( __( 'Tour Analytics for %s', 'h3vt-tours' ), $tours[0]['title'] );
		} else {
			/* translators: %d: number of tours */
			$subject = sprintf( __( 'Tour Analytics: %d Tours', 'h3vt-tours' ), count( $tours ) );
		}

		$periods       = H3VT_Tours_Client_Analytics::email_periods( $today_ymd );
		$analytics_url = (string) apply_filters( 'h3vt_tours_client_analytics_url', home_url( '/tour-analytics/' ) );
		$logo_url      = (string) apply_filters( 'h3vt_tours_client_report_logo', home_url( '/wp-content/uploads/2021/07/H3-Logo.png' ) );
		$brand         = (string) get_option( 'h3vt_tours_email_from_name', 'H3 Photography' );

		ob_start();
		include H3VT_TOURS_PATH . 'templates/client-report-email.php';
		$body = ob_get_clean();

		$from_email = (string) get_option( 'h3vt_tours_email_from_address', get_option( 'admin_email' ) );

		return array(
			'to'      => $user->user_email,
			'subject' => $subject,
			'body'    => $body,
			'headers' => array(
				'Content-Type: text/html; charset=UTF-8',
				sprintf( 'From: %s <%s>', $brand, $from_email ),
			),
		);
	}

	/**
	 * Build and email a user's report, then record when it went out.
	 *
	 * @param int               $user_id User id.
	 * @param DateTimeImmutable $today   Site-local date the report is for.
	 * @throws Exception When building fails or wp_mail() does not accept the message.
	 */
	public static function send( $user_id, DateTimeImmutable $today ) {
		$email = self::build( $user_id, $today );

		$mail_error = null;
		$capture    = function ( $error ) use ( &$mail_error ) {
			$mail_error = $error;
		};
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] );
		remove_action( 'wp_mail_failed', $capture );

		if ( ! $sent ) {
			$detail = ( $mail_error instanceof WP_Error ) ? $mail_error->get_error_message() : 'wp_mail() returned false';
			throw new Exception( 'Email was not sent: ' . $detail );
		}

		update_user_meta( $user_id, H3VT_Tours_Client_Analytics::META_LAST_SENT, current_time( 'mysql' ) );
	}

	/*
	 * Admin actions (user profile)
	 */

	/**
	 * "Send report now" button on the user profile.
	 */
	public function ajax_send() {
		check_ajax_referer( self::SEND_NONCE, 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'list_users' ) ) {
			wp_send_json_error( __( 'You do not have permission to send this report.', 'h3vt-tours' ) );
		}
		if ( ! H3VT_Tours_Client_Analytics::is_enabled() ) {
			wp_send_json_error( __( 'H3 Tour Management still sends client reports. Deactivate it first.', 'h3vt-tours' ) );
		}

		try {
			self::send( $user_id, self::today() );
			$user = get_user_by( 'id', $user_id );
			/* translators: %s: email address */
			wp_send_json_success( sprintf( __( 'Report sent to %s.', 'h3vt-tours' ), $user->user_email ) );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Show a user's report in the browser without sending it.
	 */
	public function preview() {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( 'h3vt_tours_client_report_preview_' . $user_id );

		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview this report.', 'h3vt-tours' ) );
		}

		try {
			$email = self::build( $user_id, self::today() );
		} catch ( Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $email['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped template output.
		exit;
	}

	/**
	 * Preview URL for a user's report.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function preview_url( $user_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'h3vt_tours_client_report_preview',
					'user_id' => (int) $user_id,
				),
				admin_url( 'admin-post.php' )
			),
			'h3vt_tours_client_report_preview_' . (int) $user_id
		);
	}

	/**
	 * Format seconds the way h3tm's reports did: 45s, 3m 12s, 1h 5m.
	 *
	 * @param float $seconds Duration.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = (int) round( $seconds );
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		if ( $seconds < 3600 ) {
			return floor( $seconds / 60 ) . 'm ' . ( $seconds % 60 ) . 's';
		}
		return floor( $seconds / 3600 ) . 'h ' . floor( ( $seconds % 3600 ) / 60 ) . 'm';
	}
}
