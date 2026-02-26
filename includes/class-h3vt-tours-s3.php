<?php
/**
 * AWS S3 presigned URL generation and helpers.
 *
 * Uses lightweight Signature V4 signing — no AWS SDK required.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility class for S3 presigned URL generation and settings access.
 */
class H3VT_Tours_S3 {

	/**
	 * Option key for stored settings.
	 */
	const OPTION_KEY = 'h3vt_tours_s3_settings';

	/**
	 * Encryption method for secret key storage.
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Get the current S3 settings.
	 *
	 * @return array {
	 *     @type string $access_key  AWS Access Key ID.
	 *     @type string $secret_key  Decrypted AWS Secret Access Key.
	 *     @type string $region      AWS region (e.g. us-east-1).
	 *     @type string $bucket      S3 bucket name.
	 *     @type string $cloudfront  Optional CloudFront domain.
	 * }
	 */
	public static function get_settings() {
		$defaults = array(
			'access_key' => '',
			'secret_key' => '',
			'region'     => 'us-east-1',
			'bucket'     => '',
			'cloudfront' => '',
		);

		$settings = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( $settings, $defaults );

		// Decrypt secret key if present.
		if ( ! empty( $settings['secret_key'] ) ) {
			$decrypted = self::decrypt( $settings['secret_key'] );
			if ( false !== $decrypted ) {
				$settings['secret_key'] = $decrypted;
			} else {
				$settings['secret_key'] = '';
			}
		}

		return $settings;
	}

	/**
	 * Check whether S3 is fully configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = self::get_settings();

		return ! empty( $settings['access_key'] )
			&& ! empty( $settings['secret_key'] )
			&& ! empty( $settings['region'] )
			&& ! empty( $settings['bucket'] );
	}

	/**
	 * Generate a presigned PUT URL for direct upload.
	 *
	 * @param string $object_key   S3 object key.
	 * @param string $content_type MIME type.
	 * @param int    $expires      Signature validity in seconds.
	 * @return string|WP_Error Presigned URL or error.
	 */
	public static function generate_presigned_put_url( $object_key, $content_type, $expires = 3600 ) {
		$settings = self::get_settings();

		if ( empty( $settings['access_key'] ) || empty( $settings['secret_key'] ) ) {
			return new WP_Error( 'h3vt_s3_not_configured', __( 'S3 credentials are not configured.', 'h3vt-tours' ) );
		}

		$region  = $settings['region'];
		$bucket  = $settings['bucket'];
		$service = 's3';
		$method  = 'PUT';

		$now       = gmdate( 'Ymd\THis\Z' );
		$datestamp = gmdate( 'Ymd' );
		$host      = "{$bucket}.s3.{$region}.amazonaws.com";

		$credential_scope = "{$datestamp}/{$region}/{$service}/aws4_request";
		$credential       = $settings['access_key'] . '/' . $credential_scope;

		// Query parameters for presigned URL.
		$params = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $credential,
			'X-Amz-Date'          => $now,
			'X-Amz-Expires'       => $expires,
			'X-Amz-SignedHeaders' => 'content-type;host',
		);
		ksort( $params );

		$canonical_querystring = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		// Canonical headers.
		$canonical_headers = "content-type:{$content_type}\nhost:{$host}\n";
		$signed_headers    = 'content-type;host';

		// Canonical request.
		$canonical_request = implode( "\n", array(
			$method,
			'/' . rawurlencode( $object_key ),
			$canonical_querystring,
			$canonical_headers,
			$signed_headers,
			'UNSIGNED-PAYLOAD',
		) );

		// Fix double-encoding of slashes in object key.
		$encoded_key       = implode( '/', array_map( 'rawurlencode', explode( '/', $object_key ) ) );
		$canonical_request = implode( "\n", array(
			$method,
			'/' . $encoded_key,
			$canonical_querystring,
			$canonical_headers,
			$signed_headers,
			'UNSIGNED-PAYLOAD',
		) );

		// String to sign.
		$string_to_sign = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$now,
			$credential_scope,
			hash( 'sha256', $canonical_request ),
		) );

		// Signing key via HMAC chain.
		$signing_key = self::get_signing_key( $settings['secret_key'], $datestamp, $region, $service );

		// Signature.
		$signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		// Assemble presigned URL.
		$url = sprintf(
			'https://%s/%s?%s&X-Amz-Signature=%s',
			$host,
			$encoded_key,
			$canonical_querystring,
			$signature
		);

		return $url;
	}

	/**
	 * Get the public URL for an S3 object.
	 *
	 * Returns CloudFront URL if configured, otherwise direct S3 URL.
	 *
	 * @param string $object_key S3 object key.
	 * @return string
	 */
	public static function get_object_url( $object_key ) {
		$settings = self::get_settings();

		if ( ! empty( $settings['cloudfront'] ) ) {
			$domain = rtrim( $settings['cloudfront'], '/' );
			return "https://{$domain}/{$object_key}";
		}

		return sprintf(
			'https://%s.s3.%s.amazonaws.com/%s',
			$settings['bucket'],
			$settings['region'],
			$object_key
		);
	}

	/**
	 * Generate an S3 object key for a file upload.
	 *
	 * @param string $filename Sanitized filename.
	 * @param int    $post_id  Post ID.
	 * @return string
	 */
	public static function generate_object_key( $filename, $post_id ) {
		$sanitized = sanitize_file_name( $filename );
		$timestamp = time();

		return sprintf( 'h3vt-tours/%d/%d-%s', $post_id, $timestamp, $sanitized );
	}

	/**
	 * Derive the AWS Signature V4 signing key.
	 *
	 * @param string $secret_key AWS secret key.
	 * @param string $datestamp  Date in Ymd format.
	 * @param string $region     AWS region.
	 * @param string $service    AWS service name.
	 * @return string Binary signing key.
	 */
	private static function get_signing_key( $secret_key, $datestamp, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

		return $k_signing;
	}

	/**
	 * Encrypt a value using the WordPress auth salt.
	 *
	 * @param string $value Plain text.
	 * @return string Base64-encoded IV + ciphertext.
	 */
	public static function encrypt( $value ) {
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = openssl_random_pseudo_bytes( $iv_len );

		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a value using the WordPress auth salt.
	 *
	 * @param string $value Base64-encoded IV + ciphertext.
	 * @return string|false Decrypted value or false on failure.
	 */
	public static function decrypt( $value ) {
		$key  = hash( 'sha256', wp_salt( 'auth' ), true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data = base64_decode( $value, true );

		if ( false === $data ) {
			return false;
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $data ) <= $iv_len ) {
			return false;
		}

		$iv         = substr( $data, 0, $iv_len );
		$ciphertext = substr( $data, $iv_len );

		return openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
	}
}
