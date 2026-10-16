<?php
/**
 * On-demand tour statistics.
 *
 * Adds a "Tour Stats" page under Tours where an administrator can pull GA4
 * numbers for any tour, or every tour at once, for any date range.
 *
 * Tours are matched by URL path rather than page title, because GA4 page
 * titles ("Arden Courts of Palm Harbor") rarely match the names tours are
 * known by ("Arden Palm Harbor"):
 *   - h3vt_tour pages               /tour/{slug}/
 *   - legacy S3 tours with an id    /tours/{tour_id}/
 *   - older S3 tours without one    /h3panos/{folder}/
 * Legacy tours come from the h3tm_tour_metadata table left behind by the
 * h3-tour-management plugin, so their history stays reportable after that
 * plugin is removed.
 *
 * A rebuilt h3vt_tour post lists the old S3 tour(s) it replaces in post meta
 * `_h3vt_tours_legacy_paths` (the same links the client reports use). Those
 * old URLs are counted as part of the rebuilt tour, so its numbers reach back
 * past the day /tour/ pages started reporting.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page and CSV export for GA4 tour statistics.
 */
class H3VT_Tours_Stats {

	const PAGE_SLUG = 'h3vt-tours-stats';
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * First day /tour/ pages reported into the GA4 property (h3vt-tours 1.9.5).
	 */
	const TOUR_PAGES_TRACKED_SINCE = '2026-10-15';

	/**
	 * Matches the tour portion of a GA4 pagePath.
	 */
	const PATH_PATTERN = '#^/(tours|h3panos|tour)/([^/?\#]+)#';

	/**
	 * Whether the last fetch was served from cache, and when it was fetched.
	 */
	private $fetched_at = null;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_h3vt_tours_stats_csv', array( $this, 'download_csv' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=h3vt_tour',
			__( 'Tour Stats', 'h3vt-tours' ),
			__( 'Tour Stats', 'h3vt-tours' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/*
	 * Tour catalog and path resolution
	 */

	/**
	 * Every known tour keyed by its GA4 path prefix.
	 *
	 * A published h3vt_tour also counts the old S3 tour paths linked to it, and
	 * each of those legacy entries names the tour that absorbed it in 'owner'.
	 *
	 * @return array path => array(
	 *     'title' => string,
	 *     'path'  => string,   the tour's own path
	 *     'paths' => string[], every path prefix counted for the tour
	 *     'owner' => string,   legacy entries only: path of the rebuilt tour, or ''
	 * )
	 */
	public static function get_tour_catalog() {
		$tours = array();

		foreach ( H3VT_Tours_Client_Analytics::legacy_catalog() as $path => $name ) {
			$tours[ $path ] = array(
				'title' => $name,
				'path'  => $path,
				'paths' => array( $path ),
				'owner' => '',
			);
		}

		$cpt_tours = get_posts(
			array(
				'post_type'   => 'h3vt_tour',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		foreach ( $cpt_tours as $post ) {
			$path  = '/tour/' . $post->post_name . '/';
			$paths = array( $path );

			foreach ( H3VT_Tours_Client_Analytics::get_legacy_paths( $post->ID ) as $legacy ) {
				// An old URL is counted once, under the first tour that claims it.
				if ( isset( $tours[ $legacy ]['owner'] ) && $tours[ $legacy ]['owner'] !== '' ) {
					continue;
				}
				$paths[] = $legacy;
				if ( isset( $tours[ $legacy ] ) ) {
					$tours[ $legacy ]['owner'] = $path;
				} else {
					// Linked, but missing from the metadata table.
					$tours[ $legacy ] = array(
						'title' => $legacy,
						'path'  => $legacy,
						'paths' => array( $legacy ),
						'owner' => $path,
					);
				}
			}

			$tours[ $path ] = array(
				'title' => H3VT_Tours_Client_Analytics::post_title( $post ),
				'path'  => $path,
				'paths' => $paths,
			);
		}

		uasort(
			$tours,
			function ( $a, $b ) {
				return strnatcasecmp( $a['title'], $b['title'] );
			}
		);

		return $tours;
	}

	/**
	 * GA4 path prefix for a row of the tour metadata table.
	 *
	 * @param object $row Row with tour_id and s3_folder.
	 * @return string|null
	 */
	public static function metadata_path( $row ) {
		if ( ! empty( $row->tour_id ) ) {
			return '/tours/' . $row->tour_id . '/';
		}

		$folder = trim( preg_replace( '#^tours/#', '', (string) $row->s3_folder ), '/' );
		return $folder === '' ? null : '/h3panos/' . $folder . '/';
	}

	/**
	 * Reduce a GA4 pagePath to the tour prefix it belongs to.
	 *
	 * @param string $page_path Full pagePath reported by GA4.
	 * @return string|null e.g. "/tours/20251229_180015_hq5cktvw/"
	 */
	public static function tour_key_from_page_path( $page_path ) {
		if ( preg_match( self::PATH_PATTERN, $page_path, $m ) ) {
			return '/' . $m[1] . '/' . $m[2] . '/';
		}
		return null;
	}

	/**
	 * Every GA4 path prefix counted for a tour: its own path plus, for a rebuilt
	 * tour, the old S3 tour paths linked to it.
	 *
	 * @param array  $catalog Output of get_tour_catalog().
	 * @param string $path    Tour path.
	 * @return string[]
	 */
	public static function counted_paths( $catalog, $path ) {
		return isset( $catalog[ $path ] ) ? $catalog[ $path ]['paths'] : array( $path );
	}

	/**
	 * Map each legacy path that belongs to a rebuilt tour to that tour's path.
	 *
	 * @param array $catalog Output of get_tour_catalog().
	 * @return array legacy path => tour path
	 */
	private static function owner_map( $catalog ) {
		$owners = array();
		foreach ( $catalog as $path => $tour ) {
			if ( ! empty( $tour['owner'] ) ) {
				$owners[ $path ] = $tour['owner'];
			}
		}
		return $owners;
	}

	/**
	 * Whether a requested tour path is safe to use as a GA4 filter value.
	 */
	private static function is_valid_tour_path( $path ) {
		return is_string( $path ) && preg_match( '#^/(tours|h3panos|tour)/[^/?\#]+/$#', $path );
	}

	/*
	 * Date ranges
	 */

	/**
	 * Turn the request parameters into current and previous date ranges.
	 *
	 * @return array
	 */
	private function get_date_ranges( $range, $from, $to ) {
		$today   = new DateTimeImmutable( current_time( 'Y-m-d' ) );
		$presets = array(
			'7'   => 7,
			'30'  => 30,
			'90'  => 90,
			'365' => 365,
		);

		if ( $range === 'custom' ) {
			$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $from );
			$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $to );
			if ( ! $start || ! $end ) {
				$range = '30';
			} else {
				if ( $start > $end ) {
					list($start, $end) = array( $end, $start );
				}
				if ( $end > $today ) {
					$end = $today;
				}
			}
		}

		if ( $range === 'all' ) {
			$start = new DateTimeImmutable( '2020-01-01' );
			$end   = $today;
		} elseif ( $range !== 'custom' ) {
			if ( ! isset( $presets[ $range ] ) ) {
				$range = '30';
			}
			$end   = $today;
			$start = $today->modify( '-' . ( $presets[ $range ] - 1 ) . ' days' );
		}

		$days = (int) $start->diff( $end )->days + 1;

		$previous = null;
		if ( $range !== 'all' ) {
			$prev_end   = $start->modify( '-1 day' );
			$prev_start = $prev_end->modify( '-' . ( $days - 1 ) . ' days' );
			$previous   = array(
				'start' => $prev_start->format( 'Y-m-d' ),
				'end'   => $prev_end->format( 'Y-m-d' ),
			);
		}

		return array(
			'range'    => $range,
			'start'    => $start->format( 'Y-m-d' ),
			'end'      => $end->format( 'Y-m-d' ),
			'days'     => $days,
			'previous' => $previous,
		);
	}

	/*
	 * GA4 access
	 */

	/**
	 * Run up to five report requests in one GA4 call, cached for an hour.
	 *
	 * @param array $requests Report request definitions (arrays).
	 * @param bool  $refresh  Skip the cache.
	 * @return array One array of rows per request; each row is
	 *               array('dims' => string[], 'mets' => float[]).
	 */
	private function run_reports( array $requests, $refresh = false ) {
		$cache_key = 'h3vt_tours_stats_' . md5( H3VT_Tours_GA_Client::get_property_id() . wp_json_encode( $requests ) );

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['reports'] ) ) {
				$this->note_fetched_at( $cached['fetched_at'] );
				return $cached['reports'];
			}
		}

		$client  = new H3VT_Tours_GA_Client();
		$reports = $client->batch_run_reports( $requests );

		$fetched_at = time();
		$this->note_fetched_at( $fetched_at );
		set_transient(
			$cache_key,
			array(
				'reports'    => $reports,
				'fetched_at' => $fetched_at,
			),
			self::CACHE_TTL
		);

		return $reports;
	}

	/**
	 * Remember the oldest fetch time when a page needs more than one batch.
	 */
	private function note_fetched_at( $time ) {
		$this->fetched_at = $this->fetched_at ? min( $this->fetched_at, $time ) : $time;
	}

	/**
	 * GA4 date ranges, named so multi-range rows can be told apart.
	 */
	private static function ga_date_ranges( $dates, $with_previous ) {
		$ranges = array(
			array(
				'startDate' => $dates['start'],
				'endDate'   => $dates['end'],
				'name'      => 'current',
			),
		);
		if ( $with_previous && $dates['previous'] ) {
			$ranges[] = array(
				'startDate' => $dates['previous']['start'],
				'endDate'   => $dates['previous']['end'],
				'name'      => 'previous',
			);
		}
		return $ranges;
	}

	/**
	 * pagePath filter for one tour's paths, or for every tour URL when $paths is null.
	 *
	 * @param string[]|null $paths Path prefixes counted for the tour.
	 */
	private static function path_filter( $paths = null ) {
		return H3VT_Tours_Client_Analytics::path_filter( $paths ? $paths : array( '/tours/', '/h3panos/', '/tour/' ) );
	}

	/**
	 * Headline metrics, in the order used by the totals and table reports.
	 */
	private static $total_metrics = array( 'screenPageViews', 'totalUsers', 'newUsers', 'sessions', 'averageSessionDuration' );

	private static function metric_list( array $names ) {
		return array_map(
			function ( $name ) {
				return array( 'name' => $name );
			},
			$names
		);
	}

	/*
	 * Data assembly
	 */

	/**
	 * Fetch everything shown on the page for one tour or all tours.
	 *
	 * @param string[]|null $paths   Path prefixes counted for one tour (see
	 *                               counted_paths()), or null for all tours.
	 * @param array         $dates   Output of get_date_ranges().
	 * @param bool          $refresh Skip the cache.
	 * @return array
	 */
	private function get_stats( $paths, $dates, $refresh ) {
		$path         = $paths ? $paths[0] : null;
		$filter       = self::path_filter( $paths );
		$current_only = self::ga_date_ranges( $dates, false );

		$requests = array(
			// 0: headline totals for this period and the one before.
			array(
				'dateRanges'      => self::ga_date_ranges( $dates, true ),
				'metrics'         => self::metric_list( self::$total_metrics ),
				'dimensionFilter' => $filter,
			),
			// 1: daily views and visitors for the trend chart.
			array(
				'dateRanges'      => $current_only,
				'dimensions'      => array( array( 'name' => 'date' ) ),
				'metrics'         => self::metric_list( array( 'screenPageViews', 'totalUsers' ) ),
				'dimensionFilter' => $filter,
				'limit'           => 10000,
			),
		);

		if ( $path === null ) {
			// 2: per-page rows, rolled up into tours below.
			$requests[] = array(
				'dateRanges'      => self::ga_date_ranges( $dates, true ),
				'dimensions'      => array( array( 'name' => 'pagePath' ), array( 'name' => 'pageTitle' ) ),
				'metrics'         => self::metric_list( self::$total_metrics ),
				'dimensionFilter' => $filter,
				'limit'           => 10000,
			);
		} else {
			// 2: devices, 3: traffic sources, 4: locations.
			$requests[] = array(
				'dateRanges'      => $current_only,
				'dimensions'      => array( array( 'name' => 'deviceCategory' ) ),
				'metrics'         => self::metric_list( array( 'totalUsers' ) ),
				'dimensionFilter' => $filter,
				'orderBys'        => array(
					array(
						'metric' => array( 'metricName' => 'totalUsers' ),
						'desc'   => true,
					),
				),
			);
			$requests[] = array(
				'dateRanges'      => $current_only,
				'dimensions'      => array( array( 'name' => 'sessionSource' ) ),
				'metrics'         => self::metric_list( array( 'sessions' ) ),
				'dimensionFilter' => $filter,
				'orderBys'        => array(
					array(
						'metric' => array( 'metricName' => 'sessions' ),
						'desc'   => true,
					),
				),
				'limit'           => 10,
			);
			$requests[] = array(
				'dateRanges'      => $current_only,
				'dimensions'      => array( array( 'name' => 'region' ), array( 'name' => 'country' ) ),
				'metrics'         => self::metric_list( array( 'totalUsers' ) ),
				'dimensionFilter' => $filter,
				'orderBys'        => array(
					array(
						'metric' => array( 'metricName' => 'totalUsers' ),
						'desc'   => true,
					),
				),
				'limit'           => 10,
			);
		}

		$reports = $this->run_reports( $requests, $refresh );

		$stats = array(
			'totals' => $this->parse_totals( $reports[0], (bool) $dates['previous'] ),
			'trend'  => $this->parse_trend( $reports[1], $dates ),
		);

		if ( $path === null ) {
			$stats['tours'] = $this->parse_tour_rows( $reports[2] );
		} else {
			$stats['devices']   = $this->parse_simple( $reports[2] );
			$stats['sources']   = $this->parse_simple( $reports[3] );
			$stats['locations'] = $this->parse_locations( $reports[4] );

			// A rebuilt tour also gets its views split by URL. GA4 takes at most
			// five requests per batch, so this one goes in a batch of its own.
			if ( count( $paths ) > 1 ) {
				$by_page       = $this->run_reports(
					array(
						array(
							'dateRanges'      => $current_only,
							'dimensions'      => array( array( 'name' => 'pagePath' ) ),
							'metrics'         => self::metric_list( array( 'screenPageViews' ) ),
							'dimensionFilter' => $filter,
							'limit'           => 10000,
						),
					),
					$refresh
				);
				$stats['urls'] = $this->parse_urls( $by_page[0], $paths );
			}
		}

		return $stats;
	}

	/**
	 * Views per counted path prefix, in the order the paths were given.
	 *
	 * @param array    $rows  pagePath rows.
	 * @param string[] $paths Path prefixes counted for the tour.
	 * @return array[] array('label' => path, 'value' => views, 'percent' => float)
	 */
	private function parse_urls( $rows, $paths ) {
		$views = array_fill_keys( $paths, 0 );
		foreach ( $rows as $row ) {
			$key = self::tour_key_from_page_path( $row['dims'][0] );
			if ( $key && isset( $views[ $key ] ) ) {
				$views[ $key ] += $row['mets'][0];
			}
		}

		$total = array_sum( $views );
		$items = array();
		foreach ( $views as $url => $value ) {
			$items[] = array(
				'label'   => $url,
				'value'   => $value,
				'percent' => $total > 0 ? $value / $total * 100 : 0,
			);
		}
		return $items;
	}

	private static function empty_totals() {
		return array_fill_keys( self::$total_metrics, 0 );
	}

	/**
	 * Totals rows carry a trailing dateRange dimension when two ranges are requested.
	 */
	private function parse_totals( $rows, $has_previous ) {
		$totals = array(
			'current'  => self::empty_totals(),
			'previous' => $has_previous ? self::empty_totals() : null,
		);

		foreach ( $rows as $row ) {
			$range            = isset( $row['dims'][0] ) ? $row['dims'][0] : 'current';
			$totals[ $range ] = array_combine( self::$total_metrics, $row['mets'] );
		}

		return $totals;
	}

	/**
	 * Bucket daily rows into days, weeks or months depending on the range length.
	 */
	private function parse_trend( $rows, $dates ) {
		$by_day = array();
		foreach ( $rows as $row ) {
			$by_day[ $row['dims'][0] ] = $row['mets'];
		}

		if ( $dates['days'] <= 62 ) {
			$unit = 'day';
		} elseif ( $dates['days'] <= 366 ) {
			$unit = 'week';
		} else {
			$unit = 'month';
		}

		$start = new DateTimeImmutable( $dates['start'] );
		$end   = new DateTimeImmutable( $dates['end'] );

		// An open-ended range starts at the first day with data.
		if ( $dates['range'] === 'all' && $by_day ) {
			$start = new DateTimeImmutable( min( array_keys( $by_day ) ) );
		}

		$buckets = array();
		for ( $day = $start; $day <= $end; $day = $day->modify( '+1 day' ) ) {
			if ( $unit === 'day' ) {
				$key   = $day->format( 'Y-m-d' );
				$label = $day->format( 'M j' );
			} elseif ( $unit === 'week' ) {
				$monday = $day->modify( 'monday this week' );
				$key    = $monday->format( 'Y-m-d' );
				$label  = 'Week of ' . $monday->format( 'M j, Y' );
			} else {
				$key   = $day->format( 'Y-m' );
				$label = $day->format( 'M Y' );
			}

			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'label'     => $label,
					'views'     => 0,
					'visitors'  => 0,
					'days'      => 0,
					'full_days' => 1,
				);
				if ( $unit === 'week' ) {
					$buckets[ $key ]['full_days'] = 7;
				} elseif ( $unit === 'month' ) {
					$buckets[ $key ]['full_days'] = (int) $day->format( 't' );
				}
			}
			++$buckets[ $key ]['days'];

			$ymd = $day->format( 'Ymd' );
			if ( isset( $by_day[ $ymd ] ) ) {
				$buckets[ $key ]['views']    += $by_day[ $ymd ][0];
				$buckets[ $key ]['visitors'] += $by_day[ $ymd ][1];
			}
		}

		return array(
			'unit'    => $unit,
			'buckets' => array_values( $buckets ),
		);
	}

	/**
	 * Roll page-level rows up into one row per tour, with the previous period's views.
	 *
	 * Visitor counts are summed across a tour's pages, so a visitor who opened
	 * two pages of the same tour is counted twice. Tours are almost always a
	 * single page, so this is close enough for a per-tour table; the headline
	 * totals come from a separate de-duplicated query. The same goes for a
	 * rebuilt tour, whose old S3 URLs are rolled into its row.
	 */
	private function parse_tour_rows( $rows ) {
		$catalog = self::get_tour_catalog();
		$owners  = self::owner_map( $catalog );
		$tours   = array();

		foreach ( $rows as $row ) {
			$page_key = self::tour_key_from_page_path( $row['dims'][0] );
			if ( ! $page_key ) {
				continue;
			}
			$key   = isset( $owners[ $page_key ] ) ? $owners[ $page_key ] : $page_key;
			$range = isset( $row['dims'][2] ) ? $row['dims'][2] : 'current';

			if ( ! isset( $tours[ $key ] ) ) {
				$tours[ $key ] = array(
					'path'            => $key,
					'title'           => isset( $catalog[ $key ] ) ? $catalog[ $key ]['title'] : $row['dims'][1],
					'ga_title'        => '',
					'legacy_paths'    => array_slice( self::counted_paths( $catalog, $key ), 1 ),
					'known'           => isset( $catalog[ $key ] ),
					'current'         => self::empty_totals(),
					'previous'        => self::empty_totals(),
					'duration_weight' => 0,
				);
			}

			// Prefer the page title reported for the tour's own URL.
			if ( $tours[ $key ]['ga_title'] === '' || $page_key === $key ) {
				$tours[ $key ]['ga_title'] = $row['dims'][1];
			}

			$mets = array_combine( self::$total_metrics, $row['mets'] );
			foreach ( array( 'screenPageViews', 'totalUsers', 'newUsers', 'sessions' ) as $metric ) {
				$tours[ $key ][ $range ][ $metric ] += $mets[ $metric ];
			}

			// Keep a session-weighted average duration across a tour's pages.
			if ( $range === 'current' ) {
				$tours[ $key ]['duration_weight'] += $mets['averageSessionDuration'] * $mets['sessions'];
			}
		}

		foreach ( $tours as &$tour ) {
			$sessions                                  = $tour['current']['sessions'];
			$tour['current']['averageSessionDuration'] = $sessions > 0 ? $tour['duration_weight'] / $sessions : 0;
			unset( $tour['duration_weight'] );
		}
		unset( $tour );

		// Tours with no views this period only appear because of the previous period.
		$tours = array_filter(
			$tours,
			function ( $tour ) {
				return $tour['current']['screenPageViews'] > 0 || $tour['current']['sessions'] > 0;
			}
		);

		uasort(
			$tours,
			function ( $a, $b ) {
				return $b['current']['screenPageViews'] <=> $a['current']['screenPageViews'];
			}
		);

		return array_values( $tours );
	}

	private function parse_simple( $rows ) {
		$items = array();
		$total = 0;
		foreach ( $rows as $row ) {
			$total += $row['mets'][0];
		}
		foreach ( $rows as $row ) {
			$items[] = array(
				'label'   => $row['dims'][0],
				'value'   => $row['mets'][0],
				'percent' => $total > 0 ? $row['mets'][0] / $total * 100 : 0,
			);
		}
		return $items;
	}

	private function parse_locations( $rows ) {
		$items = array();
		foreach ( $rows as $row ) {
			list($region, $country) = $row['dims'];
			$parts                  = array_filter(
				array( $region, $country ),
				function ( $part ) {
					return $part !== '' && $part !== '(not set)';
				}
			);
			$label                  = $parts ? implode( ', ', $parts ) : __( 'Unknown', 'h3vt-tours' );

			// A blank region and "(not set)" both collapse to the bare country.
			if ( ! isset( $items[ $label ] ) ) {
				$items[ $label ] = array(
					'label' => $label,
					'value' => 0,
				);
			}
			$items[ $label ]['value'] += $row['mets'][0];
		}

		uasort(
			$items,
			function ( $a, $b ) {
				return $b['value'] <=> $a['value'];
			}
		);

		return array_values( $items );
	}

	/*
	 * Request handling
	 */

	/**
	 * Read the page's filter parameters.
	 *
	 * These are read-only GET filters on an admin page gated by manage_options,
	 * so no nonce is needed; the CSV export checks its own nonce.
	 *
	 * @return array
	 */
	private function read_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tour = isset( $_GET['tour'] ) ? sanitize_text_field( wp_unslash( $_GET['tour'] ) ) : '';
		if ( ! self::is_valid_tour_path( $tour ) ) {
			$tour = '';
		}

		return array(
			'tour'    => $tour,
			'range'   => isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30',
			'from'    => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'      => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'refresh' => ! empty( $_GET['refresh'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function page_url( array $args ) {
		$args = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		$args = array_filter(
			$args,
			function ( $value ) {
				return $value !== '' && $value !== null;
			}
		);
		return add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Stream the all-tours table as CSV.
	 */
	public function download_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download tour stats.', 'h3vt-tours' ) );
		}
		check_admin_referer( 'h3vt_tours_stats_csv' );

		$request = $this->read_request();
		$dates   = $this->get_date_ranges( $request['range'], $request['from'], $request['to'] );

		try {
			$stats = $this->get_stats( null, $dates, false );
		} catch ( Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}

		$filename = sprintf( 'tour-stats-%s-to-%s.csv', $dates['start'], $dates['end'] );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out    = fopen( 'php://output', 'w' );
		$header = array( 'Tour', 'URL', 'Old tour URLs included', 'Views', 'Visitors', 'New visitors', 'Visits', 'Avg. visit length (seconds)' );
		if ( $dates['previous'] ) {
			$header[] = 'Views, previous period';
		}
		fputcsv( $out, $header );

		foreach ( $stats['tours'] as $tour ) {
			$line = array(
				$tour['title'],
				home_url( $tour['path'] ),
				implode( ' ', array_map( 'home_url', $tour['legacy_paths'] ) ),
				(int) $tour['current']['screenPageViews'],
				(int) $tour['current']['totalUsers'],
				(int) $tour['current']['newUsers'],
				(int) $tour['current']['sessions'],
				(int) round( $tour['current']['averageSessionDuration'] ),
			);
			if ( $dates['previous'] ) {
				$line[] = (int) $tour['previous']['screenPageViews'];
			}
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}

	/*
	 * Rendering
	 */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$request = $this->read_request();
		$dates   = $this->get_date_ranges( $request['range'], $request['from'], $request['to'] );
		$catalog = self::get_tour_catalog();
		$path    = $request['tour'] !== '' ? $request['tour'] : null;
		$paths   = $path ? self::counted_paths( $catalog, $path ) : null;
		$legacy  = $path ? array_slice( $paths, 1 ) : array();

		$error = null;
		$stats = null;
		try {
			$stats = $this->get_stats( $paths, $dates, $request['refresh'] );
		} catch ( Exception $e ) {
			$error = $e->getMessage();
		}

		$base_args = array(
			'range' => $dates['range'],
			'from'  => $dates['range'] === 'custom' ? $dates['start'] : '',
			'to'    => $dates['range'] === 'custom' ? $dates['end'] : '',
		);

		if ( $path ) {
			$title = isset( $catalog[ $path ] ) ? $catalog[ $path ]['title'] : $path;
		} else {
			$title = __( 'All tours', 'h3vt-tours' );
		}

		$this->render_styles();
		?>
		<div class="wrap h3ts">
			<h1><?php esc_html_e( 'Tour Stats', 'h3vt-tours' ); ?></h1>

			<form method="get" class="h3ts-controls" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">

				<label>
					<span><?php esc_html_e( 'Tour', 'h3vt-tours' ); ?></span>
					<select name="tour">
						<option value=""><?php esc_html_e( 'All tours', 'h3vt-tours' ); ?></option>
						<?php foreach ( $this->group_catalog( $catalog, $request['tour'] ) as $group_label => $group ) : ?>
							<optgroup label="<?php echo esc_attr( $group_label ); ?>">
								<?php foreach ( $group as $tour ) : ?>
									<option value="<?php echo esc_attr( $tour['path'] ); ?>" <?php selected( $request['tour'], $tour['path'] ); ?>>
										<?php echo esc_html( $tour['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Date range', 'h3vt-tours' ); ?></span>
					<select name="range" class="h3ts-range">
						<?php
						$ranges = array(
							'7'      => __( 'Last 7 days', 'h3vt-tours' ),
							'30'     => __( 'Last 30 days', 'h3vt-tours' ),
							'90'     => __( 'Last 90 days', 'h3vt-tours' ),
							'365'    => __( 'Last 12 months', 'h3vt-tours' ),
							'all'    => __( 'All time', 'h3vt-tours' ),
							'custom' => __( 'Custom dates', 'h3vt-tours' ),
						);
						foreach ( $ranges as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $dates['range'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<span class="h3ts-custom" <?php echo $dates['range'] === 'custom' ? '' : 'hidden'; ?>>
					<label>
						<span><?php esc_html_e( 'From', 'h3vt-tours' ); ?></span>
						<input type="date" name="from" value="<?php echo esc_attr( $dates['start'] ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
					</label>
					<label>
						<span><?php esc_html_e( 'To', 'h3vt-tours' ); ?></span>
						<input type="date" name="to" value="<?php echo esc_attr( $dates['end'] ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
					</label>
				</span>

				<button type="submit" class="button button-primary"><?php esc_html_e( 'Show stats', 'h3vt-tours' ); ?></button>
			</form>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p>
					<?php
					printf(
						/* translators: %s: error message from Google Analytics */
						esc_html__( 'Could not load stats from Google Analytics: %s', 'h3vt-tours' ),
						esc_html( $error )
					);
					?>
				</p></div>
			<?php else : ?>

				<div class="h3ts-heading">
					<div>
						<?php if ( $path ) : ?>
							<a class="h3ts-back" href="<?php echo esc_url( $this->page_url( $base_args ) ); ?>">&larr; <?php esc_html_e( 'All tours', 'h3vt-tours' ); ?></a>
						<?php endif; ?>
						<h2><?php echo esc_html( $title ); ?></h2>
						<p class="h3ts-sub">
							<?php
							echo esc_html(
								sprintf(
								/* translators: 1: start date, 2: end date */
									__( '%1$s to %2$s', 'h3vt-tours' ),
									date_i18n( get_option( 'date_format' ), strtotime( $dates['start'] ) ),
									date_i18n( get_option( 'date_format' ), strtotime( $dates['end'] ) )
								)
							);
							if ( $dates['previous'] ) {
								echo ' &middot; ' . esc_html__( 'compared with the previous', 'h3vt-tours' ) . ' ' . esc_html( sprintf( _n( '%s day', '%s days', $dates['days'], 'h3vt-tours' ), number_format_i18n( $dates['days'] ) ) );
							}
							if ( $path && strpos( $path, '/tour/' ) === 0 ) {
								echo ' &middot; <a href="' . esc_url( home_url( $path ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open tour', 'h3vt-tours' ) . '</a>';
							}
							?>
						</p>
					</div>
					<p class="h3ts-cache">
						<?php
						echo esc_html(
							sprintf(
							/* translators: %s: time the data was fetched */
								__( 'Data as of %s', 'h3vt-tours' ),
								wp_date( get_option( 'time_format' ), $this->fetched_at )
							)
						);
						?>
						&middot;
						<a href="
						<?php
						echo esc_url(
							$this->page_url(
								array_merge(
									$base_args,
									array(
										'tour'    => $request['tour'],
										'refresh' => '1',
									)
								)
							)
						);
						?>
									"><?php esc_html_e( 'Refresh', 'h3vt-tours' ); ?></a>
					</p>
				</div>

				<?php if ( ( ! $path || strpos( $path, '/tour/' ) === 0 ) && $dates['start'] < self::TOUR_PAGES_TRACKED_SINCE ) : ?>
					<div class="notice notice-info inline"><p>
						<?php
						$tracked_since = date_i18n( get_option( 'date_format' ), strtotime( self::TOUR_PAGES_TRACKED_SINCE ) );
						if ( $path && $legacy ) {
							$tracking_note = sprintf(
								/* translators: 1: date, 2: list of old tour URLs */
								_n(
									'Its /tour/ page has been counted since %1$s. Visits to its old tour URL (%2$s) are added in, so days before that still show visits.',
									'Its /tour/ page has been counted since %1$s. Visits to its old tour URLs (%2$s) are added in, so days before that still show visits.',
									count( $legacy ),
									'h3vt-tours'
								),
								$tracked_since,
								implode( ', ', $legacy )
							);
						} elseif ( $path ) {
							/* translators: %s: date */
							$tracking_note = sprintf( __( 'Visits to this tour are counted from %s, so earlier days show none. It has no old tour URL linked.', 'h3vt-tours' ), $tracked_since );
						} else {
							/* translators: %s: date */
							$tracking_note = sprintf( __( 'Visits to /tour/ pages are counted from %s. Before that, the totals and the previous-period comparison come only from the old S3 tour URLs. Each rebuilt tour\'s row includes its old URLs.', 'h3vt-tours' ), $tracked_since );
						}
						echo esc_html( $tracking_note );
						?>
					</p></div>
				<?php elseif ( $path && ! empty( $catalog[ $path ]['owner'] ) && isset( $catalog[ $catalog[ $path ]['owner'] ] ) ) : ?>
					<div class="notice notice-info inline"><p>
						<?php
						$owner = $catalog[ $catalog[ $path ]['owner'] ];
						printf(
							/* translators: %s: linked tour name */
							esc_html__( 'This old tour URL was replaced by a rebuilt tour. Its visits are also counted under %s.', 'h3vt-tours' ),
							'<a href="' . esc_url( $this->page_url( array_merge( $base_args, array( 'tour' => $owner['path'] ) ) ) ) . '">' . esc_html( $owner['title'] ) . '</a>'
						);
						?>
					</p></div>
				<?php endif; ?>

				<?php $this->render_totals( $stats['totals'] ); ?>
				<?php $this->render_trend( $stats['trend'] ); ?>

				<?php if ( $path ) : ?>
					<div class="h3ts-grid">
						<?php
						$this->render_breakdown( __( 'Devices', 'h3vt-tours' ), 'visitors', $stats['devices'], true );
						$this->render_breakdown( __( 'Traffic sources', 'h3vt-tours' ), 'visits', $stats['sources'], true );
						$this->render_breakdown( __( 'Top locations', 'h3vt-tours' ), 'visitors', $stats['locations'], false );
						if ( isset( $stats['urls'] ) ) {
							$this->render_breakdown( __( 'Views by URL', 'h3vt-tours' ), 'views', $stats['urls'], true );
						}
						?>
					</div>
				<?php else : ?>
					<?php $this->render_tour_table( $stats['tours'], $dates, $base_args ); ?>
				<?php endif; ?>

				<p class="h3ts-note">
					<?php esc_html_e( 'Views count each time a tour page is opened. Visitors are unique people (one person on two devices counts twice). A visit is one session; it ends after 30 minutes of inactivity. Google Analytics can take up to a day to finish processing recent visits, so today\'s numbers may still rise.', 'h3vt-tours' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<script>
		(function () {
			var range = document.querySelector('.h3ts-range');
			var custom = document.querySelector('.h3ts-custom');
			if (range && custom) {
				range.addEventListener('change', function () {
					custom.hidden = range.value !== 'custom';
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Split the catalog into tour pages and legacy S3 tours for the picker, adding
	 * the URL to any name that appears more than once in a group.
	 *
	 * Legacy tours already counted under a rebuilt tour are left out, unless
	 * one is the current selection.
	 */
	private function group_catalog( $catalog, $selected ) {
		$groups = array(
			__( 'Tours', 'h3vt-tours' )             => array(),
			__( 'Legacy tours (S3)', 'h3vt-tours' ) => array(),
		);
		$keys   = array_keys( $groups );

		foreach ( $catalog as $tour ) {
			if ( ! empty( $tour['owner'] ) && $tour['path'] !== $selected ) {
				continue;
			}
			$groups[ strpos( $tour['path'], '/tour/' ) === 0 ? $keys[0] : $keys[1] ][] = $tour;
		}

		foreach ( $groups as &$group ) {
			$counts = array_count_values( array_map( 'strtolower', array_column( $group, 'title' ) ) );
			foreach ( $group as &$tour ) {
				$tour['label'] = $counts[ strtolower( $tour['title'] ) ] > 1
					? $tour['title'] . ' (' . $tour['path'] . ')'
					: $tour['title'];
			}
			unset( $tour );
		}
		unset( $group );

		return array_filter( $groups );
	}

	private function render_totals( $totals ) {
		$cards = array(
			'screenPageViews'        => __( 'Views', 'h3vt-tours' ),
			'totalUsers'             => __( 'Visitors', 'h3vt-tours' ),
			'newUsers'               => __( 'New visitors', 'h3vt-tours' ),
			'sessions'               => __( 'Visits', 'h3vt-tours' ),
			'averageSessionDuration' => __( 'Avg. visit length', 'h3vt-tours' ),
		);
		?>
		<div class="h3ts-cards">
			<?php
			foreach ( $cards as $metric => $label ) :
				$current  = $totals['current'][ $metric ];
				$previous = $totals['previous'] ? $totals['previous'][ $metric ] : null;
				$value    = $metric === 'averageSessionDuration' ? self::format_duration( $current ) : number_format_i18n( $current );
				?>
				<div class="h3ts-card">
					<div class="h3ts-card-label"><?php echo esc_html( $label ); ?></div>
					<div class="h3ts-card-value"><?php echo esc_html( $value ); ?></div>
					<?php if ( $totals['previous'] !== null ) : ?>
						<div class="h3ts-card-delta"><?php echo self::delta_html( $current, $previous ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Inline SVG bar chart of views per day, week or month.
	 */
	private function render_trend( $trend ) {
		$buckets = $trend['buckets'];
		if ( ! $buckets ) {
			return;
		}

		$max    = max( 1, max( array_column( $buckets, 'views' ) ) );
		$count  = count( $buckets );
		$width  = 1000;
		$height = 180;
		$gap    = $count > 60 ? 1 : 3;
		$bar    = max( 1, ( $width - $gap * ( $count - 1 ) ) / $count );

		$headings = array(
			'day'   => __( 'Views per day', 'h3vt-tours' ),
			'week'  => __( 'Views per week', 'h3vt-tours' ),
			'month' => __( 'Views per month', 'h3vt-tours' ),
		);
		?>
		<div class="h3ts-panel">
			<h3><?php echo esc_html( $headings[ $trend['unit'] ] ); ?></h3>
			<div class="h3ts-chart">
				<div class="h3ts-chart-max"><?php echo esc_html( number_format_i18n( $max ) ); ?></div>
				<svg viewBox="0 0 <?php echo (int) $width; ?> <?php echo (int) $height; ?>" preserveAspectRatio="none" role="img"
					aria-label="<?php echo esc_attr( $headings[ $trend['unit'] ] ); ?>">
					<?php
					foreach ( $buckets as $i => $bucket ) :
						$h       = $bucket['views'] > 0 ? max( 2, $bucket['views'] / $max * $height ) : 0;
						$x       = $i * ( $bar + $gap );
						$partial = $bucket['days'] < $bucket['full_days'];
						$tip     = sprintf(
							/* translators: 1: period label, 2: views, 3: visitors */
							__( '%1$s: %2$s views, %3$s visitors', 'h3vt-tours' ),
							$bucket['label'],
							number_format_i18n( $bucket['views'] ),
							number_format_i18n( $bucket['visitors'] )
						);
						if ( $partial ) {
							/* translators: %s: number of days */
							$tip .= ' ' . sprintf( _n( '(partial, %s day)', '(partial, %s days)', $bucket['days'], 'h3vt-tours' ), number_format_i18n( $bucket['days'] ) );
						}
						?>
						<rect<?php echo $partial ? ' class="h3ts-partial"' : ''; ?> x="<?php echo esc_attr( round( $x, 2 ) ); ?>" y="<?php echo esc_attr( round( $height - $h, 2 ) ); ?>"
								width="<?php echo esc_attr( round( $bar, 2 ) ); ?>" height="<?php echo esc_attr( round( $h, 2 ) ); ?>">
							<title><?php echo esc_html( $tip ); ?></title>
						</rect>
					<?php endforeach; ?>
				</svg>
				<div class="h3ts-chart-axis">
					<span><?php echo esc_html( $buckets[0]['label'] ); ?></span>
					<?php if ( $trend['unit'] !== 'day' ) : ?>
						<span><?php esc_html_e( 'Lighter bars are partial periods. Hover a bar for exact numbers.', 'h3vt-tours' ); ?></span>
					<?php else : ?>
						<span><?php esc_html_e( 'Hover a bar for exact numbers.', 'h3vt-tours' ); ?></span>
					<?php endif; ?>
					<span><?php echo esc_html( $buckets[ $count - 1 ]['label'] ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_breakdown( $heading, $unit, $items, $show_percent ) {
		$labels = array(
			'(direct)'  => __( 'Direct (typed or bookmarked link)', 'h3vt-tours' ),
			'(not set)' => __( 'Unknown', 'h3vt-tours' ),
			'desktop'   => __( 'Desktop', 'h3vt-tours' ),
			'mobile'    => __( 'Mobile', 'h3vt-tours' ),
			'tablet'    => __( 'Tablet', 'h3vt-tours' ),
		);
		$max    = $items ? max( array_column( $items, 'value' ) ) : 0;
		?>
		<div class="h3ts-panel">
			<h3><?php echo esc_html( $heading ); ?></h3>
			<?php if ( ! $items ) : ?>
				<p class="h3ts-empty"><?php esc_html_e( 'No data for this period.', 'h3vt-tours' ); ?></p>
			<?php else : ?>
				<ul class="h3ts-bars">
					<?php
					foreach ( $items as $item ) :
						$label = isset( $labels[ $item['label'] ] ) ? $labels[ $item['label'] ] : $item['label'];
						?>
						<li>
							<div class="h3ts-bar-row">
								<span class="h3ts-bar-label"><?php echo esc_html( $label ); ?></span>
								<span class="h3ts-bar-value">
									<?php
									$count = number_format_i18n( $item['value'] );
									if ( $unit === 'views' ) {
										/* translators: %s: number of views */
										$text = sprintf( _n( '%s view', '%s views', (int) $item['value'], 'h3vt-tours' ), $count );
									} elseif ( $unit === 'visits' ) {
										/* translators: %s: number of visits */
										$text = sprintf( _n( '%s visit', '%s visits', (int) $item['value'], 'h3vt-tours' ), $count );
									} else {
										/* translators: %s: number of visitors */
										$text = sprintf( _n( '%s visitor', '%s visitors', (int) $item['value'], 'h3vt-tours' ), $count );
									}
									echo esc_html( $text );
									?>
									<?php if ( $show_percent ) : ?>
										<em><?php echo esc_html( round( $item['percent'] ) . '%' ); ?></em>
									<?php endif; ?>
								</span>
							</div>
							<div class="h3ts-bar-track"><div class="h3ts-bar-fill" style="width: <?php echo esc_attr( $max > 0 ? round( $item['value'] / $max * 100, 1 ) : 0 ); ?>%"></div></div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_tour_table( $tours, $dates, $base_args ) {
		$csv_url = wp_nonce_url(
			add_query_arg( array_map( 'rawurlencode', array_filter( array_merge( $base_args, array( 'action' => 'h3vt_tours_stats_csv' ) ) ) ), admin_url( 'admin-post.php' ) ),
			'h3vt_tours_stats_csv'
		);
		?>
		<div class="h3ts-panel">
			<div class="h3ts-panel-head">
				<h3>
					<?php
					echo esc_html(
						sprintf(
						/* translators: %s: number of tours */
							_n( '%s tour with visits', '%s tours with visits', count( $tours ), 'h3vt-tours' ),
							number_format_i18n( count( $tours ) )
						)
					);
					?>
				</h3>
				<?php if ( $tours ) : ?>
					<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Download CSV', 'h3vt-tours' ); ?></a>
				<?php endif; ?>
			</div>

			<?php if ( ! $tours ) : ?>
				<p class="h3ts-empty"><?php esc_html_e( 'No tour visits were recorded in this period.', 'h3vt-tours' ); ?></p>
			<?php else : ?>
				<table class="widefat striped h3ts-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tour', 'h3vt-tours' ); ?></th>
							<th class="num"><?php esc_html_e( 'Views', 'h3vt-tours' ); ?></th>
							<?php if ( $dates['previous'] ) : ?>
								<th class="num"><?php esc_html_e( 'vs. previous', 'h3vt-tours' ); ?></th>
							<?php endif; ?>
							<th class="num"><?php esc_html_e( 'Visitors', 'h3vt-tours' ); ?></th>
							<th class="num"><?php esc_html_e( 'Visits', 'h3vt-tours' ); ?></th>
							<th class="num"><?php esc_html_e( 'Avg. visit length', 'h3vt-tours' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tours as $tour ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( $this->page_url( array_merge( $base_args, array( 'tour' => $tour['path'] ) ) ) ); ?>"><strong><?php echo esc_html( $tour['title'] ); ?></strong></a>
									<div class="h3ts-path">
										<?php if ( $tour['ga_title'] !== '' && strcasecmp( $tour['ga_title'], $tour['title'] ) !== 0 ) : ?>
											<?php echo esc_html( $tour['ga_title'] ); ?> &middot;
										<?php endif; ?>
										<?php if ( strpos( $tour['path'], '/tour/' ) === 0 ) : ?>
											<a href="<?php echo esc_url( home_url( $tour['path'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $tour['path'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $tour['path'] ); ?>
										<?php endif; ?>
										<?php if ( $tour['legacy_paths'] ) : ?>
											&middot; <?php esc_html_e( 'includes', 'h3vt-tours' ); ?> <?php echo esc_html( implode( ', ', $tour['legacy_paths'] ) ); ?>
										<?php endif; ?>
										<?php if ( ! $tour['known'] ) : ?>
											&middot; <?php esc_html_e( 'not in the tour list', 'h3vt-tours' ); ?>
										<?php endif; ?>
									</div>
								</td>
								<td class="num"><?php echo esc_html( number_format_i18n( $tour['current']['screenPageViews'] ) ); ?></td>
								<?php if ( $dates['previous'] ) : ?>
									<td class="num"><?php echo self::delta_html( $tour['current']['screenPageViews'], $tour['previous']['screenPageViews'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts ?></td>
								<?php endif; ?>
								<td class="num"><?php echo esc_html( number_format_i18n( $tour['current']['totalUsers'] ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $tour['current']['sessions'] ) ); ?></td>
								<td class="num"><?php echo esc_html( self::format_duration( $tour['current']['averageSessionDuration'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/*
	 * Formatting helpers
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

	/**
	 * Percentage change badge. Returns escaped HTML.
	 */
	private static function delta_html( $current, $previous ) {
		if ( $previous === null ) {
			return '';
		}
		if ( $previous <= 0 ) {
			return $current > 0
				? '<span class="h3ts-delta h3ts-flat">' . esc_html__( 'none before', 'h3vt-tours' ) . '</span>'
				: '<span class="h3ts-delta h3ts-flat">0%</span>';
		}

		$change  = ( $current - $previous ) / $previous * 100;
		$rounded = (int) round( $change );
		if ( $rounded === 0 ) {
			return '<span class="h3ts-delta h3ts-flat">0%</span>';
		}

		$class = $rounded > 0 ? 'h3ts-up' : 'h3ts-down';
		$text  = ( $rounded > 0 ? '+' : '&minus;' ) . number_format_i18n( abs( $rounded ) ) . '%';
		$title = sprintf(
			/* translators: %s: value in the previous period */
			__( 'Previous period: %s', 'h3vt-tours' ),
			number_format_i18n( round( $previous, 1 ) )
		);

		return '<span class="h3ts-delta ' . esc_attr( $class ) . '" title="' . esc_attr( $title ) . '">' . $text . '</span>';
	}

	private function render_styles() {
		?>
		<style>
			.h3ts { max-width: 1200px; }
			.h3ts-controls { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 14px 16px; margin: 16px 0 20px; }
			.h3ts-controls label { display: flex; flex-direction: column; gap: 4px; }
			.h3ts-controls label > span { font-size: 12px; font-weight: 600; color: #50575e; }
			.h3ts-controls select { min-width: 220px; max-width: 100%; }
			.h3ts-custom { display: flex; gap: 12px; }
			.h3ts-custom[hidden] { display: none; }
			.h3ts-heading { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
			.h3ts-heading h2 { margin: 4px 0 2px; font-size: 22px; }
			.h3ts-sub, .h3ts-cache { margin: 0; color: #646970; }
			.h3ts-back { text-decoration: none; font-size: 13px; }
			.h3ts-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
			.h3ts-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 14px 16px; }
			.h3ts-card-label { font-size: 12px; font-weight: 600; color: #50575e; text-transform: uppercase; letter-spacing: .03em; }
			.h3ts-card-value { font-size: 28px; font-weight: 600; line-height: 1.3; color: #1d2327; font-variant-numeric: tabular-nums; }
			.h3ts-delta { font-size: 12px; font-weight: 600; white-space: nowrap; }
			.h3ts-up { color: #007a3d; }
			.h3ts-down { color: #b32d2e; }
			.h3ts-flat { color: #646970; font-weight: 400; }
			.h3ts-panel { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 14px 16px; margin-bottom: 16px; }
			.h3ts-panel h3 { margin: 0 0 12px; font-size: 14px; }
			.h3ts-panel-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 12px; }
			.h3ts-panel-head h3 { margin: 0; }
			.h3ts-chart { position: relative; padding-top: 18px; }
			.h3ts-chart svg { display: block; width: 100%; height: 180px; border-bottom: 1px solid #dcdcde; }
			.h3ts-chart rect { fill: #2271b1; }
			.h3ts-chart rect.h3ts-partial { fill: #9ec2e6; }
			.h3ts-chart rect:hover { fill: #135e96; }
			.h3ts-chart-max { position: absolute; top: 0; left: 0; font-size: 11px; color: #646970; }
			.h3ts-chart-axis { display: flex; justify-content: space-between; font-size: 11px; color: #646970; margin-top: 4px; }
			.h3ts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
			.h3ts-grid .h3ts-panel { margin-bottom: 0; }
			.h3ts-bars { margin: 0; }
			.h3ts-bars li { margin-bottom: 10px; }
			.h3ts-bar-row { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
			.h3ts-bar-value { color: #50575e; white-space: nowrap; font-variant-numeric: tabular-nums; }
			.h3ts-bar-value em { font-style: normal; color: #646970; margin-left: 4px; }
			.h3ts-bar-track { height: 6px; background: #f0f0f1; border-radius: 3px; margin-top: 4px; overflow: hidden; }
			.h3ts-bar-fill { height: 100%; background: #2271b1; }
			.h3ts-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
			.h3ts-path { font-size: 12px; color: #646970; margin-top: 2px; }
			.h3ts-path a { color: #646970; }
			.h3ts-empty { color: #646970; margin: 0; }
			.h3ts-note { color: #646970; font-size: 12px; max-width: 760px; margin-top: 16px; }
			@media (max-width: 782px) {
				.h3ts-controls select { min-width: 0; width: 100%; }
				.h3ts-controls label { flex: 1 1 100%; }
				.h3ts-table th:nth-child(4), .h3ts-table td:nth-child(4),
				.h3ts-table th:nth-child(6), .h3ts-table td:nth-child(6) { display: none; }
			}
		</style>
		<?php
	}
}
