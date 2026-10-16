<?php
/**
 * Client analytics: tour assignments and the GA4 data behind client reports.
 *
 * Takes over from the h3-tour-management (h3tm) plugin, which is being
 * retired. Clients (WordPress users) are assigned tours; they get a scheduled
 * analytics email and can see a live dashboard through the
 * [tour_analytics_display] shortcode.
 *
 * Storage (all owned by this plugin, so it survives h3tm's uninstall):
 *   user meta `h3vt_tours_assigned`         array of assignment keys:
 *                                             "tour:{post_id}"  an h3vt_tour post
 *                                             "legacy:{path}"   an old S3 tour with no
 *                                                               h3vt_tour post, by GA4
 *                                                               path prefix, e.g.
 *                                                               "legacy:/tours/2025..._x/"
 *   user meta `h3vt_tours_email_frequency`  daily|weekly|monthly|never
 *   user meta `h3vt_tours_last_email_sent`  site-local MySQL datetime
 *   post meta `_h3vt_tours_legacy_paths`    on h3vt_tour posts: GA4 path prefixes of
 *                                           the old S3 tour(s) for the same building,
 *                                           so a client's numbers include views of
 *                                           both URLs and keep their history.
 *
 * While h3tm is still active it keeps sending the emails and serving the
 * shortcode; this plugin stays dormant so clients never get two reports.
 * Deactivating h3tm hands both over.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assignment storage, resolution and GA4 queries shared by the client email,
 * the client page and the user profile fields.
 */
class H3VT_Tours_Client_Analytics {

	const META_ASSIGNED     = 'h3vt_tours_assigned';
	const META_FREQUENCY    = 'h3vt_tours_email_frequency';
	const META_LAST_SENT    = 'h3vt_tours_last_email_sent';
	const META_LEGACY_PATHS = '_h3vt_tours_legacy_paths';

	const DEFAULT_FREQUENCY = 'monthly';

	/**
	 * Report results are cached this long so page reloads stay cheap.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Earliest date used for "all time" figures.
	 */
	const ALL_TIME_START = '2020-01-01';

	/**
	 * Valid email frequencies with their admin labels.
	 *
	 * @return array
	 */
	public static function frequencies() {
		return array(
			'daily'   => __( 'Daily', 'h3vt-tours' ),
			'weekly'  => __( 'Weekly (Sundays)', 'h3vt-tours' ),
			'monthly' => __( 'Monthly (1st of the month)', 'h3vt-tours' ),
			'never'   => __( 'Never', 'h3vt-tours' ),
		);
	}

	/**
	 * Whether h3-tour-management is loaded and still owns client analytics.
	 *
	 * @return bool
	 */
	public static function legacy_plugin_active() {
		return defined( 'H3TM_VERSION' );
	}

	/**
	 * Whether this plugin sends the emails and serves the client page.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'h3vt_tours_client_analytics_enabled', ! self::legacy_plugin_active() );
	}

	/*
	 * Assignments
	 */

	/**
	 * A user's assignment keys.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	public static function get_assigned( $user_id ) {
		$assigned = get_user_meta( $user_id, self::META_ASSIGNED, true );
		return is_array( $assigned ) ? array_values( array_filter( $assigned, array( __CLASS__, 'is_valid_key' ) ) ) : array();
	}

	/**
	 * Save a user's assignment keys, dropping anything malformed.
	 *
	 * @param int      $user_id User id.
	 * @param string[] $keys    Assignment keys.
	 */
	public static function set_assigned( $user_id, array $keys ) {
		$keys = array_values( array_unique( array_filter( $keys, array( __CLASS__, 'is_valid_key' ) ) ) );
		if ( $keys ) {
			update_user_meta( $user_id, self::META_ASSIGNED, $keys );
		} else {
			delete_user_meta( $user_id, self::META_ASSIGNED );
		}
	}

	/**
	 * Email frequency, defaulting to monthly as h3tm did.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function get_frequency( $user_id ) {
		$frequency = get_user_meta( $user_id, self::META_FREQUENCY, true );
		return is_string( $frequency ) && array_key_exists( $frequency, self::frequencies() ) ? $frequency : self::DEFAULT_FREQUENCY;
	}

	/**
	 * Store an email frequency.
	 *
	 * @param int    $user_id   User id.
	 * @param string $frequency One of frequencies().
	 */
	public static function set_frequency( $user_id, $frequency ) {
		if ( array_key_exists( $frequency, self::frequencies() ) ) {
			update_user_meta( $user_id, self::META_FREQUENCY, $frequency );
		}
	}

	/**
	 * Assignment key for an h3vt_tour post.
	 */
	public static function tour_key( $post_id ) {
		return 'tour:' . (int) $post_id;
	}

	/**
	 * Assignment key for an old S3 tour path.
	 */
	public static function legacy_key( $path ) {
		return 'legacy:' . $path;
	}

	/**
	 * Whether a string is a well-formed assignment key.
	 *
	 * @param mixed $key Candidate.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		if ( ! is_string( $key ) ) {
			return false;
		}
		if ( preg_match( '/^tour:[1-9]\d*$/', $key ) ) {
			return true;
		}
		return (bool) preg_match( '#^legacy:/(tours|h3panos)/[^/?\#]+/$#', $key );
	}

	/**
	 * Old S3 tour paths linked to an h3vt_tour post.
	 *
	 * @param int $post_id Tour post id.
	 * @return string[]
	 */
	public static function get_legacy_paths( $post_id ) {
		$paths = get_post_meta( $post_id, self::META_LEGACY_PATHS, true );
		if ( ! is_array( $paths ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$paths,
				function ( $path ) {
					return is_string( $path ) && preg_match( '#^/(tours|h3panos)/[^/?\#]+/$#', $path );
				}
			)
		);
	}

	/**
	 * Resolve an assignment key to what a report needs.
	 *
	 * An h3vt_tour post reports its own /tour/{slug}/ page once published, plus
	 * any linked old S3 tour paths. Drafts with linked paths keep reporting the
	 * old tour, so a client loses nothing while the new one is being built.
	 *
	 * @param string $key Assignment key.
	 * @return array|null array( 'key', 'title', 'paths' => string[] ), or null
	 *                    when the tour no longer exists.
	 */
	public static function resolve( $key ) {
		if ( ! self::is_valid_key( $key ) ) {
			return null;
		}

		if ( strpos( $key, 'tour:' ) === 0 ) {
			$post = get_post( (int) substr( $key, 5 ) );
			if ( ! $post || $post->post_type !== 'h3vt_tour' || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				return null;
			}

			$paths = array();
			if ( $post->post_status === 'publish' && $post->post_name !== '' ) {
				$paths[] = '/tour/' . $post->post_name . '/';
			}
			$paths = array_merge( $paths, self::get_legacy_paths( $post->ID ) );

			return array(
				'key'   => $key,
				'title' => self::post_title( $post ),
				'paths' => array_values( array_unique( $paths ) ),
			);
		}

		$path    = substr( $key, 7 );
		$catalog = self::legacy_catalog();

		return array(
			'key'   => $key,
			'title' => isset( $catalog[ $path ] ) ? $catalog[ $path ] : $path,
			'paths' => array( $path ),
		);
	}

	/**
	 * A tour post's title as plain text (get_the_title() returns HTML entities,
	 * which would show up literally in email subjects).
	 *
	 * @param int|WP_Post $post Tour post.
	 * @return string
	 */
	public static function post_title( $post ) {
		return trim( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Resolve a user's assignments, skipping tours that no longer exist.
	 *
	 * @param int $user_id User id.
	 * @return array[] Resolved tours, in assignment order.
	 */
	public static function get_user_tours( $user_id ) {
		$tours = array();
		foreach ( self::get_assigned( $user_id ) as $key ) {
			$tour = self::resolve( $key );
			if ( $tour ) {
				$tours[] = $tour;
			}
		}
		return $tours;
	}

	/**
	 * Old S3 tours from the h3tm_tour_metadata table, by GA4 path.
	 *
	 * The table is left behind when h3tm is uninstalled, so these titles
	 * survive the plugin's removal.
	 *
	 * @return array path => display name
	 */
	public static function legacy_catalog() {
		static $catalog = null;
		if ( $catalog !== null ) {
			return $catalog;
		}

		global $wpdb;
		$catalog = array();
		$table   = $wpdb->prefix . 'h3tm_tour_metadata';

		if ( ! self::table_exists( $table ) ) {
			return $catalog;
		}

		// Table name is the site prefix plus a fixed suffix; there is no user input.
		$rows = $wpdb->get_results( "SELECT tour_id, display_name, s3_folder FROM {$table} ORDER BY display_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			$path = H3VT_Tours_Stats::metadata_path( $row );
			if ( $path ) {
				$catalog[ $path ] = trim( $row->display_name );
			}
		}

		return $catalog;
	}

	/**
	 * Whether a database table exists.
	 *
	 * Compared case-insensitively: MySQL on Windows lowercases table names,
	 * and the live site's table prefix has capitals.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_string( $found ) && strcasecmp( $found, $table ) === 0;
	}

	/*
	 * GA4 reports
	 */

	/**
	 * pagePath filter matching any of the given tour path prefixes.
	 *
	 * @param string[] $paths Path prefixes.
	 * @return array|null Null when there is nothing to match, so callers never
	 *                    run an unfiltered (whole-property) query.
	 */
	public static function path_filter( array $paths ) {
		$expressions = array();
		foreach ( array_unique( $paths ) as $path ) {
			$expressions[] = array(
				'filter' => array(
					'fieldName'    => 'pagePath',
					'stringFilter' => array(
						'matchType' => 'BEGINS_WITH',
						'value'     => $path,
					),
				),
			);
		}

		if ( ! $expressions ) {
			return null;
		}

		return count( $expressions ) === 1 ? $expressions[0] : array( 'orGroup' => array( 'expressions' => $expressions ) );
	}

	/**
	 * Run report requests through the GA4 client, cached for an hour.
	 *
	 * @param array $requests Up to five RunReportRequest bodies.
	 * @param bool  $refresh  Skip the cache.
	 * @return array One array of rows per request.
	 * @throws Exception When Google Analytics cannot be queried.
	 */
	public static function run_reports( array $requests, $refresh = false ) {
		$cache_key = 'h3vt_tours_client_' . md5( H3VT_Tours_GA_Client::get_property_id() . wp_json_encode( $requests ) );

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$client  = new H3VT_Tours_GA_Client();
		$reports = $client->batch_run_reports( $requests );
		set_transient( $cache_key, $reports, self::CACHE_TTL );

		return $reports;
	}

	/**
	 * Visits, new and returning visitors and average visit length for one
	 * tour over the four email periods.
	 *
	 * @param string[] $paths Tour path prefixes.
	 * @param string   $today Site-local Y-m-d the report is for.
	 * @return array period => array( 'sessions', 'new_users', 'returning_users', 'avg_time' )
	 * @throws Exception When Google Analytics cannot be queried.
	 */
	public static function get_email_stats( array $paths, $today ) {
		$periods = self::email_periods( $today );
		$empty   = array(
			'sessions'        => 0,
			'new_users'       => 0,
			'returning_users' => 0,
			'avg_time'        => 0,
		);
		$stats   = array_fill_keys( array_keys( $periods ), $empty );

		$filter = self::path_filter( $paths );
		if ( ! $filter ) {
			return $stats;
		}

		$ranges = array();
		foreach ( $periods as $name => $period ) {
			$ranges[] = array(
				'startDate' => $period['start'],
				'endDate'   => $period['end'],
				'name'      => $name,
			);
		}

		$reports = self::run_reports(
			array(
				array(
					'dateRanges'      => $ranges,
					'metrics'         => array( array( 'name' => 'sessions' ), array( 'name' => 'averageSessionDuration' ) ),
					'dimensionFilter' => $filter,
				),
				array(
					'dateRanges'      => $ranges,
					'dimensions'      => array( array( 'name' => 'newVsReturning' ) ),
					'metrics'         => array( array( 'name' => 'totalUsers' ) ),
					'dimensionFilter' => $filter,
				),
			)
		);

		// With several date ranges GA4 appends the range name as the last dimension.
		foreach ( $reports[0] as $row ) {
			$period = end( $row['dims'] );
			if ( isset( $stats[ $period ] ) ) {
				$stats[ $period ]['sessions'] = (int) $row['mets'][0];
				$stats[ $period ]['avg_time'] = $row['mets'][1];
			}
		}
		foreach ( $reports[1] as $row ) {
			$period = end( $row['dims'] );
			if ( ! isset( $stats[ $period ] ) ) {
				continue;
			}
			if ( $row['dims'][0] === 'new' ) {
				$stats[ $period ]['new_users'] = (int) $row['mets'][0];
			} elseif ( $row['dims'][0] === 'returning' ) {
				$stats[ $period ]['returning_users'] = (int) $row['mets'][0];
			}
		}

		return $stats;
	}

	/**
	 * The four periods in each email, all ending yesterday so every day counted
	 * is complete (the report goes out just after midnight).
	 *
	 * @param string $today Site-local Y-m-d.
	 * @return array name => array( 'label', 'start', 'end' )
	 */
	public static function email_periods( $today ) {
		$end = ( new DateTimeImmutable( $today ) )->modify( '-1 day' );

		return array(
			'week'    => array(
				'label' => __( 'Last 7 Days', 'h3vt-tours' ),
				'start' => $end->modify( '-6 days' )->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			),
			'month'   => array(
				'label' => __( 'Last 30 Days', 'h3vt-tours' ),
				'start' => $end->modify( '-29 days' )->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			),
			'quarter' => array(
				'label' => __( 'Last 90 Days', 'h3vt-tours' ),
				'start' => $end->modify( '-89 days' )->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			),
			'all'     => array(
				'label' => __( 'All Time', 'h3vt-tours' ),
				'start' => self::ALL_TIME_START,
				'end'   => $end->format( 'Y-m-d' ),
			),
		);
	}

	/**
	 * Everything on the client dashboard for a set of tours and one date range.
	 *
	 * @param string[] $paths   Tour path prefixes.
	 * @param string   $start   Y-m-d.
	 * @param string   $end     Y-m-d.
	 * @param bool     $refresh Skip the cache.
	 * @return array
	 * @throws Exception When Google Analytics cannot be queried.
	 */
	public static function get_dashboard_stats( array $paths, $start, $end, $refresh = false ) {
		$data = array(
			'metrics'   => array(
				'sessions'                  => 0,
				'newUsers'                  => 0,
				'returningUsers'            => 0,
				'avgSessionDuration'        => 0,
				'bounceRate'                => 0,
				'screenPageViewsPerSession' => 0,
			),
			'devices'   => array(),
			'referrals' => array(),
			'states'    => array(),
		);

		$filter = self::path_filter( $paths );
		if ( ! $filter ) {
			return $data;
		}

		$range    = array(
			array(
				'startDate' => $start,
				'endDate'   => $end,
			),
		);
		$by_users = array(
			array(
				'metric' => array( 'metricName' => 'totalUsers' ),
				'desc'   => true,
			),
		);

		$reports = self::run_reports(
			array(
				array(
					'dateRanges'      => $range,
					'metrics'         => array(
						array( 'name' => 'sessions' ),
						array( 'name' => 'newUsers' ),
						array( 'name' => 'totalUsers' ),
						array( 'name' => 'averageSessionDuration' ),
						array( 'name' => 'bounceRate' ),
						array( 'name' => 'screenPageViewsPerSession' ),
					),
					'dimensionFilter' => $filter,
				),
				array(
					'dateRanges'      => $range,
					'dimensions'      => array( array( 'name' => 'deviceCategory' ) ),
					'metrics'         => array( array( 'name' => 'totalUsers' ) ),
					'dimensionFilter' => $filter,
					'orderBys'        => $by_users,
				),
				array(
					'dateRanges'      => $range,
					'dimensions'      => array( array( 'name' => 'sessionSource' ) ),
					'metrics'         => array( array( 'name' => 'totalUsers' ) ),
					'dimensionFilter' => $filter,
					'orderBys'        => $by_users,
					'limit'           => 10,
				),
				array(
					'dateRanges'      => $range,
					'dimensions'      => array( array( 'name' => 'region' ) ),
					'metrics'         => array( array( 'name' => 'totalUsers' ), array( 'name' => 'sessions' ) ),
					'dimensionFilter' => array(
						'andGroup' => array(
							'expressions' => array(
								$filter,
								array(
									'filter' => array(
										'fieldName'    => 'country',
										'stringFilter' => array(
											'matchType' => 'EXACT',
											'value'     => 'United States',
										),
									),
								),
							),
						),
					),
					'orderBys'        => $by_users,
					'limit'           => 15,
				),
			),
			$refresh
		);

		if ( ! empty( $reports[0][0]['mets'] ) ) {
			$mets            = $reports[0][0]['mets'];
			$data['metrics'] = array(
				'sessions'                  => (int) $mets[0],
				'newUsers'                  => (int) $mets[1],
				// totalUsers counts everyone, so returning visitors are the ones who were not new.
				'returningUsers'            => max( 0, (int) $mets[2] - (int) $mets[1] ),
				'avgSessionDuration'        => $mets[3],
				'bounceRate'                => $mets[4],
				'screenPageViewsPerSession' => $mets[5],
			);
		}

		$data['devices']   = self::shares( $reports[1], 'name' );
		$data['referrals'] = self::shares( $reports[2], 'source' );
		foreach ( $data['referrals'] as &$referral ) {
			if ( $referral['source'] === '' || $referral['source'] === '(not set)' || $referral['source'] === '(direct)' ) {
				$referral['source'] = __( 'Direct', 'h3vt-tours' );
			}
		}
		unset( $referral );

		$total_users = $data['metrics']['newUsers'] + $data['metrics']['returningUsers'];
		foreach ( $reports[3] as $row ) {
			$name             = $row['dims'][0];
			$data['states'][] = array(
				'name'     => ( $name === '' || $name === '(not set)' ) ? __( 'Unknown', 'h3vt-tours' ) : $name,
				'users'    => (int) $row['mets'][0],
				'sessions' => (int) $row['mets'][1],
				'percent'  => $total_users > 0 ? $row['mets'][0] / $total_users * 100 : 0,
			);
		}

		return $data;
	}

	/**
	 * One-dimension rows as label, user count and share of the total.
	 *
	 * @param array  $rows  Report rows.
	 * @param string $label Key to store the dimension value under.
	 * @return array
	 */
	private static function shares( $rows, $label ) {
		$total = 0;
		foreach ( $rows as $row ) {
			$total += $row['mets'][0];
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				$label    => $row['dims'][0],
				'users'   => (int) $row['mets'][0],
				'percent' => $total > 0 ? $row['mets'][0] / $total * 100 : 0,
			);
		}
		return $items;
	}
}
