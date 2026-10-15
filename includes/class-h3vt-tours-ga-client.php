<?php
/**
 * Minimal Google Analytics Data API (GA4) client.
 *
 * Authenticates with a service-account key and calls the REST API directly,
 * so the plugin needs no Composer dependencies. Access tokens are cached in a
 * transient until shortly before they expire.
 *
 * Key file lookup order:
 *   1. the H3VT_TOURS_GA_CREDENTIALS constant (absolute path)
 *   2. the `h3vt_tours_ga_credentials_path` filter
 *   3. {web root parent}/private/ga-service-account.json
 *   4. {web root}/service-account-credentials.json (legacy h3-tour-management location)
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Exception messages are escaped where they are displayed (H3VT_Tours_Stats::render_page()).
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Runs GA4 reports for the configured property.
 */
class H3VT_Tours_GA_Client {

	/**
	 * GA4 property that tour pages report into.
	 */
	const DEFAULT_PROPERTY_ID = '491286260';

	/**
	 * Transient holding the current access token.
	 */
	const TOKEN_TRANSIENT = 'h3vt_tours_ga_token';

	/**
	 * Numeric GA4 property id.
	 *
	 * Filterable via `h3vt_tours_ga_property_id`.
	 *
	 * @return string
	 */
	public static function get_property_id() {
		$id = get_option( 'h3vt_tours_ga_property_id', self::DEFAULT_PROPERTY_ID );
		if ( empty( $id ) ) {
			$id = self::DEFAULT_PROPERTY_ID;
		}
		return preg_replace( '/\D/', '', (string) apply_filters( 'h3vt_tours_ga_property_id', $id ) );
	}

	/**
	 * Locate the service-account key file.
	 *
	 * @return string|null Absolute path, or null when none is readable.
	 */
	public static function get_credentials_path() {
		$candidates = array();

		if ( defined( 'H3VT_TOURS_GA_CREDENTIALS' ) ) {
			$candidates[] = H3VT_TOURS_GA_CREDENTIALS;
		}

		$filtered = apply_filters( 'h3vt_tours_ga_credentials_path', '' );
		if ( $filtered ) {
			$candidates[] = $filtered;
		}

		$candidates[] = dirname( untrailingslashit( ABSPATH ) ) . '/private/ga-service-account.json';
		$candidates[] = ABSPATH . 'service-account-credentials.json';

		foreach ( $candidates as $path ) {
			if ( is_string( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Run up to five reports in one call.
	 *
	 * @param array $requests RunReportRequest bodies as arrays.
	 * @return array One array of rows per request; each row is
	 *               array( 'dims' => string[], 'mets' => float[] ).
	 * @throws Exception When authentication or the API call fails.
	 */
	public function batch_run_reports( array $requests ) {
		$response = $this->api_post(
			'https://analyticsdata.googleapis.com/v1beta/properties/' . self::get_property_id() . ':batchRunReports',
			array( 'requests' => $requests )
		);

		$reports = array();
		$raw     = isset( $response['reports'] ) ? $response['reports'] : array();

		// The API omits empty reports' rows, but always returns one report per request.
		foreach ( $requests as $index => $unused ) {
			$rows = array();
			$data = isset( $raw[ $index ]['rows'] ) ? $raw[ $index ]['rows'] : array();

			foreach ( $data as $row ) {
				$dims = array();
				foreach ( isset( $row['dimensionValues'] ) ? $row['dimensionValues'] : array() as $value ) {
					$dims[] = isset( $value['value'] ) ? (string) $value['value'] : '';
				}
				$mets = array();
				foreach ( isset( $row['metricValues'] ) ? $row['metricValues'] : array() as $value ) {
					$mets[] = isset( $value['value'] ) ? (float) $value['value'] : 0.0;
				}
				$rows[] = array(
					'dims' => $dims,
					'mets' => $mets,
				);
			}

			$reports[] = $rows;
		}

		return $reports;
	}

	/**
	 * POST JSON to the API with a bearer token.
	 *
	 * @param string $url  Endpoint.
	 * @param array  $body Request body.
	 * @return array Decoded response.
	 * @throws Exception On transport or API errors.
	 */
	private function api_post( $url, array $body ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->get_access_token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Google Analytics could not be reached: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code ) {
			delete_transient( self::TOKEN_TRANSIENT );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			throw new Exception( 'Google Analytics returned an error: ' . $message );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Exchange a signed JWT for an access token (cached).
	 *
	 * @return string
	 * @throws Exception When the key is missing or the exchange fails.
	 */
	private function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$path = self::get_credentials_path();
		if ( ! $path ) {
			throw new Exception( 'The Google Analytics service account key file was not found.' );
		}

		$key = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			throw new Exception( 'The Google Analytics service account key file is not valid.' );
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			throw new Exception( 'The PHP OpenSSL extension is required for Google Analytics.' );
		}

		$token_uri = ! empty( $key['token_uri'] ) ? $key['token_uri'] : 'https://oauth2.googleapis.com/token';
		$now       = time();
		$segments  = array(
			self::base64url(
				wp_json_encode(
					array(
						'alg' => 'RS256',
						'typ' => 'JWT',
					)
				)
			),
			self::base64url(
				wp_json_encode(
					array(
						'iss'   => $key['client_email'],
						'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
						'aud'   => $token_uri,
						'iat'   => $now,
						'exp'   => $now + 3600,
					)
				)
			),
		);

		$signature = '';
		if ( ! openssl_sign( implode( '.', $segments ), $signature, $key['private_key'], 'sha256WithRSAEncryption' ) ) {
			throw new Exception( 'Could not sign the Google Analytics token request.' );
		}
		$segments[] = self::base64url( $signature );

		$response = wp_remote_post(
			$token_uri,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => implode( '.', $segments ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Google sign-in could not be reached: ' . $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$message = isset( $data['error_description'] ) ? $data['error_description'] : ( isset( $data['error'] ) ? $data['error'] : 'no token returned' );
			throw new Exception( 'Google sign-in failed: ' . $message );
		}

		$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] - 300 : 3000;
		set_transient( self::TOKEN_TRANSIENT, $data['access_token'], max( 60, $ttl ) );

		return $data['access_token'];
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding.
	}
}
