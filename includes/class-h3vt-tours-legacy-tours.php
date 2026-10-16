<?php
/**
 * Legacy tour serving: the old 3DVista tours at /h3panos/{slug}/.
 *
 * Takes over from the h3-tour-management (h3tm) plugin, which is being
 * retired. Every tour uploaded through h3tm lives in the S3 bucket
 * (h3-tour-files-h3vt, under tours/) behind CloudFront, and is served as if it
 * were local at https://h3vt.com/h3panos/{slug}/... by fetching each file from
 * the origin in PHP. Client sites embed those URLs, so this must reproduce
 * h3tm's behaviour exactly:
 *
 *   - /h3panos/{slug}           301 to /h3panos/{slug}/?_redirected=1, so the
 *                               tour's relative asset paths resolve. Any query
 *                               string other than _redirected also gets this
 *                               redirect (and is dropped), as before.
 *   - /h3panos/{old-slug}/...   301 to the tour's current slug (from the
 *                               url_history column of h3tm's metadata table).
 *   - /h3panos/{slug}/{file}    fetched from the origin and passed through with
 *                               Cache-Control: public, max-age=3600.
 *
 * Slugs resolve to S3 folders through h3tm's `{prefix}h3tm_tour_metadata`
 * table, which h3tm's uninstall keeps. A slug with a tour_id row maps to
 * tours/{tour_id}/; anything else is used as the folder name itself (legacy
 * named uploads, stored with spaces).
 *
 * Google Analytics for these tours is not added here: the tour's index.htm on
 * S3 already carries a script tag for /h3-tour-analytics.js (injected at
 * upload), or an inline gtag snippet for the oldest tours. This class serves
 * that script, plus the older /h3-analytics.js, with h3tm's exact output.
 *
 * Nothing is fetched with AWS credentials: the files are public through
 * CloudFront, so h3tm's AWS keys are not needed for serving.
 *
 * While h3tm is active it keeps serving, unless `wp h3vt-tours legacy-tours
 * takeover on` is set; then this class answers the requests first (at init
 * priority 0, h3tm's handler runs at 999) and h3tm never sees them.
 * `takeover off` hands serving back. Deactivating h3tm also hands it over.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves /h3panos/ tours and their analytics script.
 */
class H3VT_Tours_Legacy_Tours {

	/**
	 * Option set by `legacy-tours takeover on`: serve here even though h3tm is active.
	 */
	const TAKEOVER_OPTION = 'h3vt_tours_legacy_tours_takeover';

	/**
	 * Option holding this plugin's own copy of the origin settings
	 * (cdn_domain, bucket, region), so serving does not depend on h3tm's options.
	 */
	const SOURCE_OPTION = 'h3vt_tours_legacy_tours_source';

	/**
	 * URL prefix the tours are served under.
	 */
	const PREFIX = 'h3panos';

	/**
	 * Tour id format: 20250114_173045_8k3j9d2m.
	 */
	const TOUR_ID_PATTERN = '/^\d{8}_\d{6}_[a-z0-9]{8}$/';

	/**
	 * Default entry file when the metadata row names none.
	 */
	const DEFAULT_ENTRY_FILE = 'index.htm';

	/**
	 * Browser cache lifetime for every tour file, in seconds.
	 */
	const CACHE_TTL = 3600;

	/**
	 * User agent sent to the origin (kept from h3tm for log continuity).
	 */
	const USER_AGENT = 'H3TM S3 Proxy/2.2';

	/**
	 * Metadata rows looked up during this request, keyed by "column:value".
	 *
	 * @var array
	 */
	private $rows = array();

	/**
	 * Hook in ahead of h3tm's own handler.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_serve' ), 0 );
	}

	/**
	 * Whether h3-tour-management is loaded.
	 *
	 * @return bool
	 */
	public static function legacy_plugin_active() {
		return defined( 'H3TM_VERSION' );
	}

	/**
	 * Whether this plugin serves the /h3panos/ tours.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$enabled = ! self::legacy_plugin_active() || (bool) get_option( self::TAKEOVER_OPTION );
		return (bool) apply_filters( 'h3vt_tours_legacy_tours_enabled', $enabled );
	}

	/**
	 * Origin settings: this plugin's own copy, else h3tm's options.
	 *
	 * @return array array( 'cdn_domain' => string, 'bucket' => string, 'region' => string )
	 */
	public static function source() {
		$own = get_option( self::SOURCE_OPTION );
		if ( is_array( $own ) && ( ! empty( $own['cdn_domain'] ) || ! empty( $own['bucket'] ) ) ) {
			$source = wp_parse_args( $own, array( 'cdn_domain' => '', 'bucket' => '', 'region' => 'us-east-1' ) );
		} else {
			$source = self::h3tm_source();
		}
		return apply_filters( 'h3vt_tours_legacy_tours_source', $source );
	}

	/**
	 * Origin settings as h3tm stores them.
	 *
	 * @return array
	 */
	public static function h3tm_source() {
		$cdn_domain = '';
		if ( get_option( 'h3tm_cloudfront_enabled', false ) ) {
			$cdn_domain = preg_replace( '#^https?://#', '', (string) get_option( 'h3tm_cloudfront_domain', '' ) );
		}

		return array(
			'cdn_domain' => $cdn_domain,
			'bucket'     => defined( 'H3_S3_BUCKET' ) ? H3_S3_BUCKET : (string) get_option( 'h3tm_s3_bucket', '' ),
			'region'     => defined( 'H3_S3_REGION' ) ? H3_S3_REGION : (string) get_option( 'h3tm_s3_region', 'us-east-1' ),
		);
	}

	/**
	 * Answer /h3panos/ and analytics script requests, then exit.
	 */
	public function maybe_serve() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! self::is_enabled() ) {
			return;
		}

		$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against fixed patterns only.
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$home_path   = trailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		if ( 0 !== strpos( $path, $home_path ) ) {
			return;
		}
		$relative = substr( $path, strlen( $home_path ) );

		if ( preg_match( '#^h3-tour-analytics\.js/?$#', $relative ) ) {
			$this->serve_tour_analytics_script();
		}
		if ( preg_match( '#^h3-analytics\.js/?$#', $relative ) ) {
			$this->serve_analytics_script();
		}
		if ( 0 === strpos( $relative, self::PREFIX . '/' ) ) {
			$this->serve_tour( $path, $request_uri );
		}
	}

	/*
	 * Tour files
	 */

	/**
	 * Serve one /h3panos/ request: redirect, file, or 404.
	 *
	 * @param string $path        Request path, still URL-encoded.
	 * @param string $request_uri Full request URI, including the query string.
	 */
	private function serve_tour( $path, $request_uri ) {
		if ( ! preg_match( '#/' . self::PREFIX . '/([^/?\#]+)(/([^?\#]*))?#', $path, $matches ) ) {
			return; // Bare /h3panos/: let WordPress show its 404 page, as before.
		}

		$tour_name      = urldecode( $matches[1] );
		$requested_file = isset( $matches[3] ) && '' !== $matches[3] ? $matches[3] : null;

		$resolved = $this->resolve( $tour_name );

		// An old slug: send it to the tour's current one.
		if ( is_array( $resolved ) ) {
			$this->redirect( site_url( '/' . self::PREFIX . '/' . $resolved['current_slug'] ) . '/' . ( $requested_file ? $requested_file : '' ) );
		}

		$entry_file = $this->entry_file( $tour_name );
		$file_path  = $requested_file ? $requested_file : $entry_file;

		// The entry file must load from the directory URL so its relative paths resolve.
		if ( $file_path === $entry_file
			&& ! preg_match( '#/' . self::PREFIX . '/[^/]+/$#', $request_uri )
			&& ! isset( $_GET['_redirected'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->redirect( add_query_arg( '_redirected', '1', site_url( '/' . self::PREFIX . '/' . rawurlencode( $tour_name ) . '/' ) ) );
		}

		$urls = $this->origin_urls( $resolved, $file_path );
		if ( empty( $urls ) ) {
			wp_die( 'S3 not configured for tour delivery', 'Configuration Error', array( 'response' => 503 ) );
		}

		foreach ( $urls as $url ) {
			if ( $this->pass_through( $url, $file_path ) ) {
				exit;
			}
		}

		wp_die( 'Tour file not found', 'Tour Not Found', array( 'response' => 404 ) );
	}

	/**
	 * 301 to a URL and stop.
	 *
	 * @param string $url Destination.
	 */
	private function redirect( $url ) {
		wp_redirect( $url, 301 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- same-site tour URLs.
		exit;
	}

	/**
	 * Resolve a URL slug to the S3 folder name.
	 *
	 * @param string $identifier Decoded first path segment after /h3panos/.
	 * @return string|array The folder (tour_id or legacy name), or
	 *                      array( 'current_slug' => ... ) for an old slug.
	 */
	public function resolve( $identifier ) {
		if ( self::is_tour_id( $identifier ) ) {
			return $identifier;
		}

		$slug = sanitize_title( urldecode( $identifier ) );

		$row = $this->row( 'tour_slug', $slug );
		if ( $row && ! empty( $row->tour_id ) ) {
			return $row->tour_id;
		}

		$row = $this->row_by_old_slug( $slug );
		if ( $row && ! empty( $row->tour_slug ) ) {
			return array( 'current_slug' => $row->tour_slug );
		}

		return $identifier;
	}

	/**
	 * The file a tour's directory URL serves (index.htm unless the row says otherwise).
	 *
	 * Looked up by the slug as requested, not the resolved folder, as h3tm did.
	 *
	 * @param string $identifier Decoded first path segment after /h3panos/.
	 * @return string
	 */
	public function entry_file( $identifier ) {
		if ( self::is_tour_id( $identifier ) ) {
			$row = $this->row( 'tour_id', $identifier );
		} else {
			$row = $this->row( 'tour_slug', sanitize_title( urldecode( $identifier ) ) );
		}

		return ( $row && ! empty( $row->entry_file ) ) ? $row->entry_file : self::DEFAULT_ENTRY_FILE;
	}

	/**
	 * Origin URLs to try for a file, in order.
	 *
	 * @param string $folder    S3 folder under tours/ (tour_id or legacy name).
	 * @param string $file_path Path inside the tour, still URL-encoded.
	 * @return string[] Empty when no origin is configured.
	 */
	public function origin_urls( $folder, $file_path ) {
		$source = self::source();

		if ( ! empty( $source['cdn_domain'] ) ) {
			// The CloudFront origin path is /tours, so it is not repeated here.
			$base = 'https://' . $source['cdn_domain'] . '/';
		} elseif ( ! empty( $source['bucket'] ) ) {
			$base = 'https://' . $source['bucket'] . '.s3.' . $source['region'] . '.amazonaws.com/tours/';
		} else {
			return array();
		}

		// Legacy uploads are stored with spaces; a few were uploaded with dashes.
		$urls = array( $base . rawurlencode( $folder ) . '/' . $file_path );
		if ( false !== strpos( $folder, ' ' ) ) {
			$urls[] = $base . str_replace( ' ', '-', $folder ) . '/' . $file_path;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Fetch one origin URL and, if it answers 200, send it to the browser.
	 *
	 * GET bodies are streamed through a temp file rather than held in memory,
	 * since tour videos can be large.
	 *
	 * @param string $url       Origin URL.
	 * @param string $file_path Path inside the tour (for the content type).
	 * @return bool True when the file was sent.
	 */
	private function pass_through( $url, $file_path ) {
		$is_head = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'];
		$args    = array(
			'timeout' => $is_head ? 10 : 30,
			'headers' => array(
				'Accept'     => '*/*',
				'User-Agent' => self::USER_AGENT,
			),
		);

		$temp = null;
		if ( $is_head ) {
			$response = wp_remote_head( $url, $args );
		} else {
			$temp = tempnam( get_temp_dir(), 'h3vt' );
			if ( $temp ) {
				$args['stream']   = true;
				$args['filename'] = $temp;
			}
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			if ( $temp ) {
				@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return false;
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		if ( $is_head ) {
			$length = wp_remote_retrieve_header( $response, 'content-length' );
		} elseif ( $temp ) {
			$length = filesize( $temp );
		} else {
			$body   = wp_remote_retrieve_body( $response );
			$length = strlen( $body );
		}

		status_header( 200 );
		header( 'Content-Type: ' . self::content_type( $file_path ) );
		if ( $length || ! $is_head ) {
			header( 'Content-Length: ' . $length );
		}
		header( 'Cache-Control: public, max-age=' . self::CACHE_TTL );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s \G\M\T', time() + self::CACHE_TTL ) );
		header( 'X-Content-Type-Options: nosniff' );

		if ( ! $is_head ) {
			if ( $temp ) {
				readfile( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			} else {
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw tour file.
			}
		}
		if ( $temp ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		return true;
	}

	/**
	 * Content type by extension. Deliberately the same short list h3tm used;
	 * everything else goes out as application/octet-stream.
	 *
	 * @param string $file_path Path inside the tour.
	 * @return string
	 */
	private static function content_type( $file_path ) {
		$types = array(
			'html' => 'text/html',
			'htm'  => 'text/html',
			'js'   => 'application/javascript',
			'css'  => 'text/css',
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'mp4'  => 'video/mp4',
			'json' => 'application/json',
			'txt'  => 'text/plain',
		);
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		return isset( $types[ $ext ] ) ? $types[ $ext ] : 'application/octet-stream';
	}

	/**
	 * Whether a string is a tour id.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function is_tour_id( $value ) {
		return 1 === preg_match( self::TOUR_ID_PATTERN, (string) $value );
	}

	/*
	 * h3tm's metadata table
	 */

	/**
	 * The metadata table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'h3tm_tour_metadata';
	}

	/**
	 * One metadata row by tour_slug or tour_id.
	 *
	 * @param string $column tour_slug or tour_id.
	 * @param string $value  Value to match.
	 * @return object|null
	 */
	private function row( $column, $value ) {
		global $wpdb;

		$key = $column . ':' . $value;
		if ( ! array_key_exists( $key, $this->rows ) ) {
			$column             = ( 'tour_id' === $column ) ? 'tour_id' : 'tour_slug';
			$table              = self::table();
			$this->rows[ $key ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s", $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		return $this->rows[ $key ];
	}

	/**
	 * The row whose url_history lists an old slug.
	 *
	 * @param string $slug Old slug.
	 * @return object|null
	 */
	private function row_by_old_slug( $slug ) {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE url_history IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			$history = json_decode( $row->url_history, true );
			if ( is_array( $history ) && in_array( $slug, $history, true ) ) {
				return $row;
			}
		}

		return null;
	}

	/*
	 * Analytics scripts. The JavaScript is h3tm's, unchanged, so tracking and
	 * the GA4 reports built on it carry on exactly as before.
	 */

	/**
	 * Reporting-property measurement id ('' disables the scripts' GA load).
	 *
	 * @return string
	 */
	private static function measurement_id() {
		$id = get_option( 'h3tm_ga_measurement_id', H3VT_Tours_Analytics::DEFAULT_MEASUREMENT_ID );
		return (string) apply_filters( 'h3vt_tours_ga_measurement_id', $id );
	}

	/**
	 * Whether h3tm's "analytics enabled" setting is on (it defaults to on).
	 *
	 * @return bool
	 */
	private static function analytics_enabled() {
		return '1' === (string) get_option( 'h3tm_analytics_enabled', '1' );
	}

	/**
	 * /h3-tour-analytics.js — referenced from the index.htm of every tour
	 * uploaded through h3tm's Lambda pipeline, with tour, tour_id, title and
	 * path query args.
	 */
	private function serve_tour_analytics_script() {
		// Not unslashed: h3tm never did, so a title such as "Mary's" has always
		// reached GA as "Mary\'s". Unslashing would split those tours' history.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$tour_name   = isset( $_GET['tour'] ) ? sanitize_text_field( $_GET['tour'] ) : 'Unknown Tour';
		$tour_folder = isset( $_GET['folder'] ) ? sanitize_text_field( $_GET['folder'] ) : '';
		$page_title  = isset( $_GET['title'] ) ? sanitize_text_field( $_GET['title'] ) : $tour_name;
		$page_path   = isset( $_GET['path'] ) ? sanitize_text_field( $_GET['path'] ) : '/tours/' . $tour_folder . '/';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		$ga_measurement_id = self::measurement_id();
		$ga_enabled        = self::analytics_enabled();

		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Access-Control-Allow-Origin: *' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded values in a JS response.
		?>
// H3 Tour Analytics Script v2.0
// Centrally managed analytics for S3-hosted tours
(function() {
    'use strict';

    // Tour information from URL parameters
    const tourName = <?php echo wp_json_encode( $tour_name ); ?>;
    const tourFolder = <?php echo wp_json_encode( $tour_folder ); ?>;
    const pageTitle = <?php echo wp_json_encode( $page_title ); ?>;
    const pagePath = <?php echo wp_json_encode( $page_path ); ?>;

    console.log('H3 Tour Analytics: Initializing for', tourName);
    console.log('Page Title:', pageTitle);
    console.log('Page Path:', pagePath);

    <?php if ( $ga_enabled && ! empty( $ga_measurement_id ) ) : ?>
    // Load Google Analytics 4
    (function() {
        // Check if GA is already loaded
        if (window.h3AnalyticsLoaded) {
            console.log('H3 Analytics: Already loaded, skipping');
            return;
        }
        window.h3AnalyticsLoaded = true;

        // Load GA script
        const script = document.createElement('script');
        script.src = 'https://www.googletagmanager.com/gtag/js?id=<?php echo esc_js( $ga_measurement_id ); ?>';
        script.async = true;
        document.head.appendChild(script);

        script.onload = function() {
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            window.gtag = gtag;

            gtag('js', new Date());

            // Configure GA4 with tour information
            gtag('config', '<?php echo esc_js( $ga_measurement_id ); ?>', {
                'page_title': pageTitle,
                'page_path': pagePath,
                'custom_map': {
                    'dimension1': 'tour_name',
                    'dimension2': 'tour_folder',
                    'dimension3': 'page_title'
                },
                'tour_name': tourName,
                'tour_folder': tourFolder,
                'original_title': pageTitle
            });

            // Track tour view event
            gtag('event', 'tour_view', {
                'tour_name': tourName,
                'tour_folder': tourFolder,
                'event_category': 'Tour Engagement',
                'event_label': tourName,
                'source': 's3_hosted'
            });

            console.log('H3 Analytics: Google Analytics 4 configured for', tourName);
        };
    })();

    // Enhanced engagement tracking
    document.addEventListener('DOMContentLoaded', function() {
        const startTime = Date.now();
        let interactionCount = 0;

        // Track clicks within the tour
        document.addEventListener('click', function(e) {
            interactionCount++;

            // Track specific interactions
            if (window.gtag) {
                const target = e.target.closest('a, button, [role="button"]');
                if (target) {
                    gtag('event', 'tour_interaction', {
                        'tour_name': tourName,
                        'interaction_type': 'click',
                        'interaction_target': target.tagName.toLowerCase(),
                        'event_category': 'Tour Engagement'
                    });
                }
            }
        });

        // Track time spent and interactions on unload
        window.addEventListener('beforeunload', function() {
            const timeSpent = Math.round((Date.now() - startTime) / 1000);

            if (window.gtag) {
                // Send timing event
                gtag('event', 'timing_complete', {
                    'name': 'tour_session_duration',
                    'value': timeSpent,
                    'event_category': 'Tour Engagement',
                    'event_label': tourName
                });

                // Send interaction count
                gtag('event', 'tour_engagement', {
                    'tour_name': tourName,
                    'time_spent_seconds': timeSpent,
                    'interaction_count': interactionCount,
                    'event_category': 'Tour Engagement'
                });
            }
        });

        console.log('H3 Analytics: Engagement tracking initialized');
    });

    // Track if tour fails to load properly
    window.addEventListener('error', function(e) {
        if (window.gtag) {
            gtag('event', 'exception', {
                'description': 'Tour loading error: ' + e.message,
                'fatal': false,
                'tour_name': tourName
            });
        }
    });

    <?php else : ?>
    console.log('H3 Analytics: Analytics disabled or not configured');
    <?php endif; ?>

})();

// Expose tour info globally for debugging
window.h3TourInfo = {
    name: <?php echo wp_json_encode( $tour_name ); ?>,
    folder: <?php echo wp_json_encode( $tour_folder ); ?>,
    title: <?php echo wp_json_encode( $page_title ); ?>,
    path: <?php echo wp_json_encode( $page_path ); ?>,
    analyticsVersion: '2.0',
    gaEnabled: <?php echo wp_json_encode( $ga_enabled ); ?>,
    gaMeasurementId: <?php echo wp_json_encode( $ga_enabled ? $ga_measurement_id : null ); ?>
};
<?php
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * /h3-analytics.js — the older script, which reads the tour name from a
	 * data-tour-name attribute on its own script tag.
	 */
	private function serve_analytics_script() {
		$ga_measurement_id = self::measurement_id();

		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
// H3 Tour Analytics Script
// Dynamically loaded from WordPress for all S3-hosted tours
(function() {
    'use strict';

    // Get tour name from script tag data attribute
    const scriptTag = document.querySelector('script[data-tour-name]');
    const tourName = scriptTag ? scriptTag.getAttribute('data-tour-name') : 'Unknown Tour';

    console.log('H3 Analytics: Loading for tour:', tourName);

    <?php if ( self::analytics_enabled() && ! empty( $ga_measurement_id ) ) : ?>
    // Load Google Analytics 4
    (function() {
        const script = document.createElement('script');
        script.src = 'https://www.googletagmanager.com/gtag/js?id=<?php echo esc_js( $ga_measurement_id ); ?>';
        script.async = true;
        document.head.appendChild(script);

        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());

        gtag('config', '<?php echo esc_js( $ga_measurement_id ); ?>', {
            'page_title': tourName,
            'page_path': '/h3panos/' + tourName + '/',
            'custom_map': {'dimension1': 'tour_name'}
        });

        // Track tour view event
        gtag('event', 'tour_view', {
            'tour_name': tourName,
            'event_category': 'Tour Engagement',
            'event_label': tourName
        });

        console.log('H3 Analytics: Google Analytics loaded for tour:', tourName);
    })();

    // Track tour engagement
    document.addEventListener('DOMContentLoaded', function() {
        // Track time spent on tour
        const startTime = Date.now();

        window.addEventListener('beforeunload', function() {
            const timeSpent = Math.round((Date.now() - startTime) / 1000);
            if (typeof gtag !== 'undefined') {
                gtag('event', 'timing_complete', {
                    'name': 'tour_session',
                    'value': timeSpent,
                    'event_category': 'Tour Engagement',
                    'event_label': tourName
                });
            }
        });

        console.log('H3 Analytics: Time tracking initialized');
    });
    <?php else : ?>
    console.log('H3 Analytics: Analytics disabled in WordPress settings');
    <?php endif; ?>

})();
<?php
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
