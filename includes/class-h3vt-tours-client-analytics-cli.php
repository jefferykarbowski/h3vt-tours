<?php
/**
 * WP-CLI commands for client analytics, including the one-off migration
 * from h3-tour-management.
 *
 *   wp h3vt-tours client-analytics migrate [--apply] [--ga] [--map=<pairs>] [--force]
 *   wp h3vt-tours client-analytics due [--date=<Y-m-d>]
 *   wp h3vt-tours client-analytics preview <user_id> [--date=<Y-m-d>]
 *   wp h3vt-tours client-analytics send <user_id>
 *   wp h3vt-tours client-analytics run
 *   wp h3vt-tours client-analytics takeover <on|off|status>
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage client tour analytics (assignments, emails, migration from h3tm).
 */
class H3VT_Tours_Client_Analytics_CLI {

	/**
	 * Option recording that a migration has been applied.
	 */
	const MIGRATED_OPTION = 'h3vt_tours_client_analytics_migrated';

	/**
	 * Copy client tour assignments, email frequencies and send dates from
	 * h3-tour-management into this plugin. Dry run unless --apply is given.
	 *
	 * h3tm's uninstall drops its user settings table and deletes every
	 * `h3tm_tours` user meta row, so run this (with --apply) before deleting
	 * that plugin, and again right before deactivating it to pick up any
	 * late edits. Each run rebuilds the assignments from h3tm's data.
	 *
	 * Each old S3 tour a client has is matched to the h3vt_tour post for the
	 * same building by name. A match becomes a "tour:{id}" assignment and the
	 * old tour's URL is linked to that post, so reports count both URLs and
	 * keep their history. Unmatched tours stay assigned as old tours.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write the changes. Without it nothing is changed.
	 *
	 * [--ga]
	 * : Check each match against the page title Google Analytics recorded for
	 * the old tour, and suggest matches for tours that found none. A match
	 * whose GA title points at a different building is not applied.
	 *
	 * [--map=<pairs>]
	 * : Comma-separated overrides, "Old tour name=post_id", or
	 * "Old tour name=legacy" to keep it as an old tour.
	 *
	 * [--force]
	 * : Allow --apply after client analytics have moved here (takeover on, or
	 * h3tm deactivated) and a migration already ran. This overwrites any
	 * assignment edits made in this plugin since.
	 *
	 * ## EXAMPLES
	 *
	 *     wp h3vt-tours client-analytics migrate --ga
	 *     wp h3vt-tours client-analytics migrate --ga --map="Arden Anderson=legacy" --apply
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function migrate( $args, $assoc_args ) {
		global $wpdb;

		$apply = ! empty( $assoc_args['apply'] );

		if ( $apply && get_option( self::MIGRATED_OPTION ) && H3VT_Tours_Client_Analytics::is_enabled() && empty( $assoc_args['force'] ) ) {
			WP_CLI::error( 'A migration was already applied and client analytics now run here, so assignments may have been edited since. Re-run with --force to overwrite them from h3tm\'s data.' );
		}

		$overrides = $this->parse_map( isset( $assoc_args['map'] ) ? $assoc_args['map'] : '' );
		$ga_titles = ! empty( $assoc_args['ga'] ) ? $this->ga_titles() : null;

		$metadata = $this->metadata_rows();
		$posts    = get_posts(
			array(
				'post_type'   => 'h3vt_tour',
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => -1,
			)
		);

		// 1. Assignments.
		$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id", 'h3tm_tours' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows      = array();
		$plan      = array();
		$links     = array();
		$problems  = 0;

		foreach ( $meta_rows as $meta_row ) {
			$user_id = (int) $meta_row->user_id;
			$values  = maybe_unserialize( $meta_row->meta_value );
			$keys    = array();

			foreach ( (array) $values as $value ) {
				$result = $this->map_value( (string) $value, $metadata, $posts, $overrides, $ga_titles );

				if ( $result['key'] ) {
					$keys[] = $result['key'];
				}
				if ( $result['link'] ) {
					$links[ $result['link'][0] ][] = $result['link'][1];
				}
				if ( preg_match( '/^(UNRESOLVED|AMBIGUOUS|GA-CONFLICT|UNMATCHED)/', $result['status'] ) ) {
					++$problems;
				}

				$rows[] = array(
					'user'     => $user_id,
					'h3tm'     => $value,
					'status'   => $result['status'],
					'assigned' => $result['key'] ? $result['key'] : '-',
					'title'    => $result['title'],
					'ga_title' => $result['ga_title'],
				);
			}

			$plan[ $user_id ] = array_values( array_unique( $keys ) );
		}

		WP_CLI::log( 'Tour assignments (h3tm value -> this plugin):' );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'user', 'h3tm', 'status', 'assigned', 'title', 'ga_title' ) );

		// 2. Old tour URLs linked to tour posts.
		$link_rows = array();
		foreach ( $links as $post_id => $paths ) {
			$existing = H3VT_Tours_Client_Analytics::get_legacy_paths( $post_id );
			$merged   = array_values( array_unique( array_merge( $existing, $paths ) ) );
			$links[ $post_id ] = $merged;
			$link_rows[]       = array(
				'post'  => $post_id,
				'title' => H3VT_Tours_Client_Analytics::post_title( $post_id ),
				'paths' => implode( ' ', $merged ),
				'new'   => count( $merged ) - count( $existing ),
			);
		}
		WP_CLI::log( '' );
		WP_CLI::log( 'Old tour URLs counted with each tour post:' );
		WP_CLI\Utils\format_items( 'table', $link_rows, array( 'post', 'title', 'paths', 'new' ) );

		// 3. Frequencies and last-sent dates.
		$settings_table = $wpdb->prefix . 'h3tm_user_settings';
		$settings       = array();
		if ( H3VT_Tours_Client_Analytics::table_exists( $settings_table ) ) {
			$settings = $wpdb->get_results( "SELECT user_id, email_frequency, last_email_sent FROM {$settings_table} ORDER BY user_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		} else {
			WP_CLI::warning( "Table {$settings_table} not found; email frequencies default to monthly." );
		}

		$setting_rows = array();
		foreach ( $settings as $setting ) {
			$user_id   = (int) $setting->user_id;
			$frequency = array_key_exists( (string) $setting->email_frequency, H3VT_Tours_Client_Analytics::frequencies() ) ? $setting->email_frequency : H3VT_Tours_Client_Analytics::DEFAULT_FREQUENCY;
			$ours      = (string) get_user_meta( $user_id, H3VT_Tours_Client_Analytics::META_LAST_SENT, true );
			$last      = (string) $setting->last_email_sent > $ours ? (string) $setting->last_email_sent : $ours;
			$user      = get_user_by( 'id', $user_id );

			$setting_rows[] = array(
				'user'      => $user_id,
				'email'     => $user ? $user->user_email : '(user deleted)',
				'frequency' => $frequency,
				'last_sent' => $last ? $last : '-',
				'tours'     => isset( $plan[ $user_id ] ) ? count( $plan[ $user_id ] ) : 0,
			);

			if ( $apply && $user ) {
				H3VT_Tours_Client_Analytics::set_frequency( $user_id, $frequency );
				if ( $last ) {
					update_user_meta( $user_id, H3VT_Tours_Client_Analytics::META_LAST_SENT, $last );
				}
			}
		}
		WP_CLI::log( '' );
		WP_CLI::log( 'Email settings:' );
		WP_CLI\Utils\format_items( 'table', $setting_rows, array( 'user', 'email', 'frequency', 'last_sent', 'tours' ) );

		// 4. Sender name and address.
		$from_name    = get_option( 'h3tm_email_from_name' );
		$from_address = get_option( 'h3tm_email_from_address' );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Sender: %s <%s>', $from_name ? $from_name : '(default)', $from_address ? $from_address : '(default)' ) );

		if ( ! $apply ) {
			WP_CLI::log( '' );
			if ( $problems ) {
				WP_CLI::warning( sprintf( '%d assignment(s) need a look (UNRESOLVED, AMBIGUOUS, GA-CONFLICT or UNMATCHED). Use --map to settle them.', $problems ) );
			}
			WP_CLI::success( 'Dry run only. Nothing was changed. Add --apply to write it.' );
			return;
		}

		foreach ( $plan as $user_id => $keys ) {
			if ( get_user_by( 'id', $user_id ) ) {
				H3VT_Tours_Client_Analytics::set_assigned( $user_id, $keys );
			}
		}
		foreach ( $links as $post_id => $paths ) {
			update_post_meta( $post_id, H3VT_Tours_Client_Analytics::META_LEGACY_PATHS, $paths );
		}
		if ( $from_name ) {
			update_option( 'h3vt_tours_email_from_name', $from_name );
		}
		if ( $from_address ) {
			update_option( 'h3vt_tours_email_from_address', $from_address );
		}
		update_option(
			self::MIGRATED_OPTION,
			array(
				'at'    => current_time( 'mysql' ),
				'users' => array_keys( $plan ),
			),
			false
		);

		WP_CLI::success( sprintf( 'Migrated %d user(s) and linked old URLs to %d tour post(s).', count( $plan ), count( $links ) ) );
	}

	/**
	 * List who would get a report on a given day, and why.
	 *
	 * ## OPTIONS
	 *
	 * [--date=<date>]
	 * : Site-local Y-m-d. Defaults to today.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function due( $args, $assoc_args ) {
		$date  = $this->date_arg( $assoc_args );
		$items = array();
		foreach ( H3VT_Tours_Client_Reports::users_with_tours() as $user_id ) {
			$user    = get_user_by( 'id', $user_id );
			$items[] = array(
				'user'      => $user_id,
				'email'     => $user ? $user->user_email : '?',
				'frequency' => H3VT_Tours_Client_Analytics::get_frequency( $user_id ),
				'last_sent' => get_user_meta( $user_id, H3VT_Tours_Client_Analytics::META_LAST_SENT, true ),
				'tours'     => count( H3VT_Tours_Client_Analytics::get_user_tours( $user_id ) ),
				'due'       => H3VT_Tours_Client_Reports::is_due( $user_id, $date ) ? 'yes' : 'no',
			);
		}
		WP_CLI::log( 'Sending is ' . ( H3VT_Tours_Client_Analytics::is_enabled() ? 'ON' : 'OFF (h3tm sends them, see: takeover on)' ) . '. Date: ' . $date->format( 'Y-m-d' ) );
		WP_CLI\Utils\format_items( 'table', $items, array( 'user', 'email', 'frequency', 'last_sent', 'tours', 'due' ) );
	}

	/**
	 * Print a user's report HTML without sending it.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : User id.
	 *
	 * [--date=<date>]
	 * : Site-local Y-m-d the report is for. Defaults to today.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function preview( $args, $assoc_args ) {
		try {
			$email = H3VT_Tours_Client_Reports::build( (int) $args[0], $this->date_arg( $assoc_args ) );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::log( 'To: ' . $email['to'] );
		WP_CLI::log( 'Subject: ' . $email['subject'] );
		WP_CLI::log( '' );
		WP_CLI::log( $email['body'] );
	}

	/**
	 * Send a user's report now, whatever their frequency.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : User id.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function send( $args, $assoc_args ) {
		try {
			H3VT_Tours_Client_Reports::send( (int) $args[0], H3VT_Tours_Client_Reports::today() );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( 'Report sent.' );
	}

	/**
	 * Run the daily report job now (sends only to users who are due).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function run( $args, $assoc_args ) {
		if ( ! H3VT_Tours_Client_Analytics::is_enabled() ) {
			WP_CLI::error( 'h3tm still sends the reports (see: takeover on). Nothing to do.' );
		}
		( new H3VT_Tours_Client_Reports() )->run();
		WP_CLI::log( wp_json_encode( get_option( H3VT_Tours_Client_Reports::LAST_RUN ), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Hand client analytics over from an active h3tm, or back to it.
	 *
	 * h3tm also serves the old /h3panos/ tours, so it has to stay active. With
	 * takeover on, this plugin unhooks h3tm's report cron, the
	 * [tour_analytics_display] shortcode and h3tm's "3D Tour Settings" profile
	 * fields, and runs its own. h3tm's data is not touched, so `off` restores
	 * its reports exactly as they were.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on, off or status.
	 * ---
	 * options:
	 *   - on
	 *   - off
	 *   - status
	 * ---
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function takeover( $args, $assoc_args ) {
		$state = $args[0];

		if ( $state === 'on' ) {
			if ( ! get_option( self::MIGRATED_OPTION ) ) {
				WP_CLI::error( 'Run `migrate --apply` first, or clients would have no tours assigned here.' );
			}
			update_option( H3VT_Tours_Client_Analytics::TAKEOVER_OPTION, current_time( 'mysql' ), true );
		} elseif ( $state === 'off' ) {
			delete_option( H3VT_Tours_Client_Analytics::TAKEOVER_OPTION );
		}

		$since  = get_option( H3VT_Tours_Client_Analytics::TAKEOVER_OPTION );
		$legacy = H3VT_Tours_Client_Analytics::legacy_plugin_active();
		$owner  = ( ! $legacy || $since ) ? 'h3vt-tours' : 'h3tm';
		WP_CLI::success( sprintf( 'Client analytics run from %s. h3tm active: %s. Takeover: %s.', $owner, $legacy ? 'yes' : 'no', $since ? 'on since ' . $since : 'off' ) );
	}

	/*
	 * Matching
	 */

	/**
	 * Map one h3tm assignment value to an assignment key here.
	 *
	 * @return array array( 'key', 'status', 'title', 'ga_title', 'link' => array( post_id, path )|null )
	 */
	private function map_value( $value, array $metadata, array $posts, array $overrides, $ga_titles ) {
		$result = array(
			'key'      => null,
			'status'   => '',
			'title'    => '',
			'ga_title' => '',
			'link'     => null,
		);

		// Already a tour post (h3tm 2.9 stored these as "cpt:{id}").
		if ( strpos( $value, 'cpt:' ) === 0 ) {
			$post = get_post( (int) substr( $value, 4 ) );
			if ( $post && $post->post_type === 'h3vt_tour' && $post->post_status !== 'trash' ) {
				$result['key']    = H3VT_Tours_Client_Analytics::tour_key( $post->ID );
				$result['status'] = 'tour-post';
				$result['title']  = H3VT_Tours_Client_Analytics::post_title( $post );
			} else {
				$result['status'] = 'UNRESOLVED';
			}
			return $result;
		}

		$row = $this->find_metadata( $value, $metadata );
		if ( ! $row ) {
			// h3tm could not report on these either.
			$result['status'] = 'UNRESOLVED';
			return $result;
		}

		$path               = H3VT_Tours_Stats::metadata_path( $row );
		$result['ga_title'] = ( $ga_titles && isset( $ga_titles[ $path ] ) ) ? $ga_titles[ $path ] : '';
		$legacy             = array(
			'key'      => H3VT_Tours_Client_Analytics::legacy_key( $path ),
			'title'    => trim( $row->display_name ),
			'ga_title' => $result['ga_title'],
			'link'     => null,
		);

		// Manual override.
		if ( isset( $overrides[ $value ] ) ) {
			if ( $overrides[ $value ] === 'legacy' ) {
				return array_merge( $legacy, array( 'status' => 'kept (--map)' ) );
			}
			$post = get_post( $overrides[ $value ] );
			if ( ! $post || $post->post_type !== 'h3vt_tour' ) {
				WP_CLI::error( sprintf( '--map: %s is not a tour post.', $overrides[ $value ] ) );
			}
			return array(
				'key'      => H3VT_Tours_Client_Analytics::tour_key( $post->ID ),
				'status'   => 'matched (--map)',
				'title'    => H3VT_Tours_Client_Analytics::post_title( $post ),
				'ga_title' => $result['ga_title'],
				'link'     => array( $post->ID, $path ),
			);
		}

		$candidates = $this->candidates( $row->display_name, $posts );

		if ( count( $candidates ) > 1 ) {
			return array_merge(
				$legacy,
				array(
					'status' => 'AMBIGUOUS',
					'title'  => implode(
						' | ',
						array_map(
							function ( $post ) {
								return $post->ID . ' ' . $post->post_title;
							},
							$candidates
						)
					),
				)
			);
		}

		if ( ! $candidates ) {
			$status = 'UNMATCHED';
			if ( $result['ga_title'] ) {
				$ga_candidates = $this->candidates( $result['ga_title'], $posts );
				if ( count( $ga_candidates ) === 1 ) {
					$status = sprintf( 'UNMATCHED (GA suggests %d)', $ga_candidates[0]->ID );
				}
			}
			return array_merge( $legacy, array( 'status' => $status ) );
		}

		$post = $candidates[0];

		// The h3tm display name is sometimes wrong; GA's title comes from the tour itself.
		$ga_check = 'no GA data';
		if ( $result['ga_title'] ) {
			$ga_candidates = $this->candidates( $result['ga_title'], $posts );
			$ga_check      = in_array( $post, $ga_candidates, true ) ? 'GA agrees' : 'GA title inconclusive';
			if ( $ga_candidates && ! in_array( $post, $ga_candidates, true ) ) {
				return array_merge(
					$legacy,
					array(
						'status' => 'GA-CONFLICT',
						'title'  => sprintf( 'name says %d %s', $post->ID, $post->post_title ),
					)
				);
			}
		}

		return array(
			'key'      => H3VT_Tours_Client_Analytics::tour_key( $post->ID ),
			'status'   => $ga_titles === null ? 'matched' : 'matched, ' . $ga_check,
			'title'    => H3VT_Tours_Client_Analytics::post_title( $post ) . ( $post->post_status === 'publish' ? '' : ' (' . $post->post_status . ')' ),
			'ga_title' => $result['ga_title'],
			'link'     => array( $post->ID, $path ),
		);
	}

	/**
	 * Tour posts whose name matches: exact location first, then one name
	 * being the start of the other ("Vintage" / "Vintage Lake").
	 *
	 * @param string    $name  Old tour name or GA title.
	 * @param WP_Post[] $posts Tour posts.
	 * @return WP_Post[]
	 */
	private function candidates( $name, array $posts ) {
		$location = self::location( $name );
		$brand    = self::brand( $name );
		if ( strlen( $location ) < 4 ) {
			return array();
		}

		$exact  = array();
		$prefix = array();
		foreach ( $posts as $post ) {
			$post_brand = self::brand( $post->post_title );
			if ( $brand && $post_brand && $brand !== $post_brand ) {
				continue;
			}
			$post_location = self::location( $post->post_title );
			if ( $post_location === $location ) {
				$exact[] = $post;
			} elseif ( strlen( $post_location ) >= 5 && ( strpos( $post_location, $location ) === 0 || strpos( $location, $post_location ) === 0 ) ) {
				$prefix[] = $post;
			}
		}

		return $exact ? $exact : $prefix;
	}

	/**
	 * Operator brand in a tour name, if any.
	 */
	private static function brand( $name ) {
		$name = strtolower( $name );
		foreach ( array( 'auberge', 'arden', 'promedica' ) as $brand ) {
			if ( strpos( $name, $brand ) !== false ) {
				return $brand;
			}
		}
		return '';
	}

	/**
	 * Reduce a tour name to its location: "Arden Courts of Ft. Myers",
	 * "Arden Ft Myers" and "Arden-FtMyers" all become "ftmyers".
	 */
	private static function location( $name ) {
		$name = strtolower( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) );
		$name = preg_replace( '/[\s\-–—_|]+/u', ' ', $name );
		$name = preg_replace(
			array(
				'/^(the )?auberge( at)? /',
				'/^arden courts?( of)? /',
				'/^arden /',
				'/^promedica( total rehab)? /',
				'/^the /',
				'/ skilled$/',
				'/ virtual tour$/',
			),
			'',
			trim( $name )
		);
		return preg_replace( '/[^a-z0-9]/', '', $name );
	}

	/**
	 * Find a metadata row the way h3tm did: by tour_id, slug, folder or name.
	 */
	private function find_metadata( $value, array $metadata ) {
		foreach ( array( 'tour_id', 'tour_slug', 's3_folder', 'display_name' ) as $field ) {
			foreach ( $metadata as $row ) {
				if ( (string) $row->$field !== '' && (string) $row->$field === $value ) {
					return $row;
				}
			}
		}
		return null;
	}

	/**
	 * Rows of the h3tm_tour_metadata table.
	 *
	 * @return object[]
	 */
	private function metadata_rows() {
		global $wpdb;
		$table = $wpdb->prefix . 'h3tm_tour_metadata';
		if ( ! H3VT_Tours_Client_Analytics::table_exists( $table ) ) {
			WP_CLI::warning( "Table {$table} not found; old tours cannot be resolved." );
			return array();
		}
		return $wpdb->get_results( "SELECT tour_id, tour_slug, display_name, s3_folder FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Most-viewed GA4 page title for each old tour path.
	 *
	 * @return array path => title
	 */
	private function ga_titles() {
		try {
			$reports = H3VT_Tours_Client_Analytics::run_reports(
				array(
					array(
						'dateRanges'      => array(
							array(
								'startDate' => H3VT_Tours_Client_Analytics::ALL_TIME_START,
								'endDate'   => 'today',
							),
						),
						'dimensions'      => array( array( 'name' => 'pagePath' ), array( 'name' => 'pageTitle' ) ),
						'metrics'         => array( array( 'name' => 'screenPageViews' ) ),
						'dimensionFilter' => H3VT_Tours_Client_Analytics::path_filter( array( '/tours/', '/h3panos/' ) ),
						'limit'           => 10000,
					),
				)
			);
		} catch ( Exception $e ) {
			WP_CLI::warning( 'Google Analytics check skipped: ' . $e->getMessage() );
			return null;
		}

		$views = array();
		foreach ( $reports[0] as $row ) {
			$key = H3VT_Tours_Stats::tour_key_from_page_path( $row['dims'][0] );
			if ( $key && $row['dims'][1] !== '' && $row['dims'][1] !== '(not set)' ) {
				$title = $row['dims'][1];
				if ( ! isset( $views[ $key ][ $title ] ) ) {
					$views[ $key ][ $title ] = 0;
				}
				$views[ $key ][ $title ] += $row['mets'][0];
			}
		}

		$titles = array();
		foreach ( $views as $key => $by_title ) {
			arsort( $by_title );
			$titles[ $key ] = key( $by_title );
		}
		return $titles;
	}

	/**
	 * Parse --map="Old name=123,Other name=legacy".
	 */
	private function parse_map( $raw ) {
		$map = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $pair ) {
			$parts = array_map( 'trim', explode( '=', $pair, 2 ) );
			if ( count( $parts ) !== 2 || $parts[0] === '' || ( $parts[1] !== 'legacy' && ! ctype_digit( $parts[1] ) ) ) {
				WP_CLI::error( sprintf( '--map entry "%s" should look like "Old tour name=123" or "Old tour name=legacy".', $pair ) );
			}
			$map[ $parts[0] ] = $parts[1] === 'legacy' ? 'legacy' : (int) $parts[1];
		}
		return $map;
	}

	/**
	 * --date as a site-local date, defaulting to today.
	 */
	private function date_arg( $assoc_args ) {
		if ( empty( $assoc_args['date'] ) ) {
			return H3VT_Tours_Client_Reports::today();
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $assoc_args['date'], wp_timezone() );
		if ( ! $date ) {
			WP_CLI::error( '--date must be Y-m-d.' );
		}
		return $date;
	}
}
