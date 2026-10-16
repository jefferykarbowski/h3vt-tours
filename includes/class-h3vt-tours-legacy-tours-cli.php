<?php
/**
 * WP-CLI commands for the legacy /h3panos/ tours.
 *
 *   wp h3vt-tours legacy-tours takeover <on|off|status>
 *   wp h3vt-tours legacy-tours list [--format=<format>]
 *   wp h3vt-tours legacy-tours check [--slug=<slug>] [--deep]
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage serving of the old S3 tours at /h3panos/.
 */
class H3VT_Tours_Legacy_Tours_CLI {

	/**
	 * Choose which plugin serves the /h3panos/ tours while h3tm is active.
	 *
	 * With takeover on, this plugin answers every /h3panos/ request and the
	 * /h3-tour-analytics.js and /h3-analytics.js scripts before h3tm sees
	 * them. `on` also copies h3tm's CloudFront and bucket settings into this
	 * plugin's own option, so serving no longer reads h3tm's options. `off`
	 * hands serving straight back to h3tm. Once h3tm is deactivated this
	 * plugin serves regardless of the setting.
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

		if ( 'on' === $state ) {
			$source = H3VT_Tours_Legacy_Tours::source();
			if ( empty( $source['cdn_domain'] ) && empty( $source['bucket'] ) ) {
				WP_CLI::error( 'No CloudFront domain or S3 bucket found in h3tm\'s settings; nothing to serve from.' );
			}
			update_option( H3VT_Tours_Legacy_Tours::SOURCE_OPTION, $source, true );
			update_option( H3VT_Tours_Legacy_Tours::TAKEOVER_OPTION, current_time( 'mysql' ), true );
		} elseif ( 'off' === $state ) {
			delete_option( H3VT_Tours_Legacy_Tours::TAKEOVER_OPTION );
		}

		$since  = get_option( H3VT_Tours_Legacy_Tours::TAKEOVER_OPTION );
		$legacy = H3VT_Tours_Legacy_Tours::legacy_plugin_active();
		$owner  = H3VT_Tours_Legacy_Tours::is_enabled() ? 'h3vt-tours' : 'h3tm';
		$source = H3VT_Tours_Legacy_Tours::source();

		WP_CLI::log( sprintf( 'Origin: %s', $source['cdn_domain'] ? 'https://' . $source['cdn_domain'] . '/' : 's3://' . $source['bucket'] . '/tours/ (' . $source['region'] . ')' ) );
		WP_CLI::success( sprintf( '/h3panos/ tours are served by %s. h3tm active: %s. Takeover: %s.', $owner, $legacy ? 'yes' : 'no', $since ? 'on since ' . $since : 'off' ) );
	}

	/**
	 * List every tour in h3tm's metadata table with the URL it serves at.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function list_( $args, $assoc_args ) {
		$rows = $this->rows();
		$out  = array();
		foreach ( $rows as $row ) {
			$history = json_decode( (string) $row->url_history, true );
			$out[]   = array(
				'url'        => site_url( '/' . H3VT_Tours_Legacy_Tours::PREFIX . '/' . $row->tour_slug . '/' ),
				'name'       => $row->display_name,
				'tour_id'    => $row->tour_id,
				'folder'     => $this->folder( $row ),
				'entry_file' => $row->entry_file,
				'old_slugs'  => is_array( $history ) ? implode( ' ', $history ) : '',
			);
		}
		WP_CLI\Utils\format_items( $assoc_args['format'], $out, array( 'url', 'name', 'tour_id', 'folder', 'entry_file', 'old_slugs' ) );
	}

	/**
	 * Resolve each tour's URL the way a visitor's request is resolved and
	 * fetch its entry file from the origin. Read-only.
	 *
	 * Tours with a tour_id are checked by slug and by tour_id. Tours without
	 * one are served from the S3 folder named in the URL itself, so they are
	 * checked by their folder name (e.g. /h3panos/Cedar%20Park/), the form
	 * client sites link to.
	 *
	 * Files are fetched with a one-byte range GET, not HEAD: S3 answers HEAD
	 * for objects archived to Glacier, but a GET fails with
	 * InvalidObjectState, which is what a visitor gets.
	 *
	 * ## OPTIONS
	 *
	 * [--slug=<slug>]
	 * : Check one URL segment, e.g. "Arden-Lely%20Community", instead of every row.
	 *
	 * [--deep]
	 * : Also fetch every local script the entry file loads (script.js,
	 * lib/tdvplayer.js, ...). Catches tours whose page loads but whose player
	 * files are archived or missing.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function check( $args, $assoc_args ) {
		$server = new H3VT_Tours_Legacy_Tours();
		$deep   = ! empty( $assoc_args['deep'] );

		if ( isset( $assoc_args['slug'] ) ) {
			$segments = array( $assoc_args['slug'] );
		} else {
			$segments = array();
			foreach ( $this->rows() as $row ) {
				if ( $row->tour_id ) {
					$segments[] = rawurlencode( $row->tour_slug );
					$segments[] = $row->tour_id;
				} else {
					$segments[] = rawurlencode( $this->folder( $row ) );
				}
			}
		}

		$failed = 0;
		$out    = array();
		foreach ( array_unique( $segments ) as $segment ) {
			$name     = urldecode( $segment );
			$resolved = $server->resolve( $name );
			if ( is_array( $resolved ) ) {
				$out[] = array(
					'segment' => $segment,
					'status'  => '301',
					'origin'  => '-> /' . H3VT_Tours_Legacy_Tours::PREFIX . '/' . $resolved['current_slug'] . '/',
				);
				continue;
			}

			$file   = $server->entry_file( $name );
			$status = 'no origin';
			$origin = '';
			$base   = '';
			foreach ( $server->origin_urls( $resolved, $file ) as $url ) {
				$origin = $url;
				$status = $this->fetch_status( $url );
				if ( 'ok' === $status ) {
					$base = substr( $url, 0, strlen( $url ) - strlen( $file ) );
					break;
				}
			}

			if ( 'ok' === $status && $deep ) {
				foreach ( $this->entry_scripts( $origin ) as $script ) {
					$script_status = $this->fetch_status( $base . $script );
					if ( 'ok' !== $script_status ) {
						$status = $script_status . ' (' . $script . ')';
						$origin = $base . $script;
						break;
					}
				}
			}

			if ( 'ok' !== $status ) {
				++$failed;
			}
			$out[] = array(
				'segment' => $segment,
				'status'  => $status,
				'origin'  => $origin,
			);
		}

		WP_CLI\Utils\format_items( 'table', $out, array( 'segment', 'status', 'origin' ) );
		if ( $failed ) {
			WP_CLI::warning( sprintf( '%d of %d failed at the origin.', $failed, count( $out ) ) );
		} else {
			WP_CLI::success( sprintf( 'All %d resolved.', count( $out ) ) );
		}
	}

	/**
	 * "ok", or why a one-byte GET of an origin URL failed.
	 *
	 * @param string $url Origin URL.
	 * @return string
	 */
	private function fetch_status( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Range' => 'bytes=0-0' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code || 206 === $code ) {
			return 'ok';
		}
		if ( preg_match( '#<Code>([^<]+)</Code>#', wp_remote_retrieve_body( $response ), $m ) ) {
			return $code . ' ' . $m[1];
		}
		return (string) $code;
	}

	/**
	 * Relative script paths an entry file loads, without query strings.
	 *
	 * @param string $url Entry file URL at the origin.
	 * @return string[]
	 */
	private function entry_scripts( $url ) {
		$html = wp_remote_retrieve_body( wp_remote_get( $url, array( 'timeout' => 15 ) ) );
		if ( ! preg_match_all( '#<script[^>]+src=["\']([^"\']+)["\']#i', (string) $html, $m ) ) {
			return array();
		}

		$scripts = array();
		foreach ( $m[1] as $src ) {
			if ( preg_match( '#^([a-z]+:)?//#i', $src ) || 0 === strpos( $src, '/' ) ) {
				continue; // Absolute: not part of the tour folder.
			}
			$scripts[] = strtok( $src, '?' );
		}
		return array_values( array_unique( $scripts ) );
	}

	/**
	 * The S3 folder a metadata row is served from.
	 *
	 * @param object $row Metadata row.
	 * @return string
	 */
	private function folder( $row ) {
		if ( $row->tour_id ) {
			return $row->tour_id;
		}
		return trim( preg_replace( '#^tours/#', '', (string) $row->s3_folder ), '/' );
	}

	/**
	 * All metadata rows.
	 *
	 * @return object[]
	 */
	private function rows() {
		global $wpdb;
		$table = H3VT_Tours_Legacy_Tours::table();
		if ( ! H3VT_Tours_Client_Analytics::table_exists( $table ) ) {
			WP_CLI::error( "Table {$table} not found." );
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY tour_slug" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}
