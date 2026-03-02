<?php
/**
 * Abstract API Client.
 *
 * Base class for HTTP API communication with external stream providers.
 * Provides common HTTP request handling, error management, and response parsing
 * functionality shared across all provider API clients.
 *
 * @package FCHub_Stream
 * @subpackage Services\Http
 * @since 1.0.0
 */

namespace FCHubStream\App\Services\Http;

use WP_Error;
use function FCHubStream\App\Utils\log_error;

/**
 * Abstract API Client Class.
 *
 * Implements Template Method pattern to provide common HTTP functionality
 * for API communication while allowing child classes to customize
 * provider-specific operations through abstract methods.
 *
 * Child classes must implement:
 * - get_base_url(): Return API base URL
 * - get_auth_headers(): Return authentication headers array
 * - get_error_code_prefix(): Return provider-specific error code prefix
 *
 * @since 1.0.0
 */
abstract class AbstractApiClient {

	/**
	 * Default request timeout in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Get API base URL.
	 *
	 * Returns the base URL for the provider's API.
	 * Used as prefix for all API endpoint requests.
	 *
	 * @since 1.0.0
	 *
	 * @return string API base URL (e.g., 'https://api.cloudflare.com/client/v4').
	 */
	abstract protected function get_base_url();

	/**
	 * Get authentication headers.
	 *
	 * Returns provider-specific authentication headers.
	 * These headers will be merged with common headers in make_request().
	 *
	 * @since 1.0.0
	 *
	 * @return array Authentication headers.
	 *               Example for Cloudflare: array( 'Authorization' => 'Bearer TOKEN' )
	 *               Example for Bunny: array( 'AccessKey' => 'API_KEY' )
	 */
	abstract protected function get_auth_headers();

	/**
	 * Get error code prefix.
	 *
	 * Returns provider-specific prefix for WP_Error codes.
	 * Used to namespace error codes by provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string Error code prefix (e.g., 'cloudflare_api_error', 'bunny_api_error').
	 */
	abstract protected function get_error_code_prefix();

	/**
	 * Make HTTP request to API.
	 *
	 * Template Method: Performs authenticated HTTP request with common
	 * error handling while using child-specific authentication headers.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @param string      $method   HTTP method (GET, POST, PUT, PATCH, DELETE).
	 * @param string      $endpoint API endpoint path (without base URL).
	 * @param array       $body     Optional. Request body for POST/PUT/PATCH. Default empty array.
	 * @param string|null $base_url Optional. Override base URL. Default null (uses get_base_url()).
	 *
	 * @return array|WP_Error Response array on success, WP_Error on failure.
	 */
	protected function make_request( $method, $endpoint, $body = array(), $base_url = null ) {
		$url = ( $base_url ?? $this->get_base_url() ) . $endpoint;

		$headers = array_merge(
			array(
				'Content-Type' => 'application/json',
			),
			$this->get_auth_headers()
		);

		$args = array(
			'method'    => $method,
			'headers'   => $headers,
			'timeout'   => self::DEFAULT_TIMEOUT,
			'sslverify' => true,
		);

		// Add request body for POST/PUT/PATCH requests.
		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'HTTP request failed: ' . $response->get_error_message() );
			return $response;
		}

		return $response;
	}

	/**
	 * Parse JSON response.
	 *
	 * Extracts status code and decodes JSON body from HTTP response.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @param array|WP_Error $response HTTP response from wp_remote_request().
	 *
	 * @return array {
	 *     Parsed response data.
	 *
	 *     @type int   $status_code HTTP status code.
	 *     @type array $data        Decoded JSON data (empty array if decode fails).
	 * }
	 */
	protected function parse_response( $response ) {
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		return array(
			'status_code' => $status_code,
			'data'        => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Extract error message from response data.
	 *
	 * Template Method: Attempts to extract error message from response
	 * using common provider patterns. Can be overridden for provider-specific logic.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @param array  $data           Response data array.
	 * @param string $default_message Default message if extraction fails.
	 *
	 * @return string Extracted or default error message.
	 */
	protected function extract_error_message( $data, $default_message ) {
		// Try Cloudflare format: errors[0].message.
		if ( isset( $data['errors'][0]['message'] ) ) {
			return $data['errors'][0]['message'];
		}

		// Try Bunny format: Message or ErrorKey.
		if ( isset( $data['Message'] ) ) {
			return $data['Message'];
		}

		if ( isset( $data['ErrorKey'] ) ) {
			return $data['message'] ?? $data['ErrorKey'];
		}

		// Try generic message field.
		if ( isset( $data['message'] ) ) {
			return $data['message'];
		}

		return $default_message;
	}

	/**
	 * Create WP_Error from response.
	 *
	 * Helper method to create standardized WP_Error objects from API responses.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @param array  $data           Response data array.
	 * @param int    $status_code    HTTP status code.
	 * @param string $default_message Default error message.
	 *
	 * @return WP_Error Error object with extracted message and status code.
	 */
	protected function create_error( $data, $status_code, $default_message ) {
		$error_message = $this->extract_error_message( $data, $default_message );

		return new WP_Error(
			$this->get_error_code_prefix(),
			$error_message,
			array( 'status' => $status_code )
		);
	}

	/**
	 * Create test connection result array.
	 *
	 * Helper method to create standardized connection test result arrays.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @param bool   $success Whether connection test succeeded.
	 * @param string $message Result message.
	 *
	 * @return array {
	 *     Test result.
	 *
	 *     @type string $status  Status: 'success' or 'error'.
	 *     @type string $message Result message.
	 * }
	 */
	protected function create_test_result( $success, $message ) {
		return array(
			'status'  => $success ? 'success' : 'error',
			'message' => $message,
		);
	}

	/**
	 * Log API error.
	 *
	 * Logs API errors using WordPress error_log.
	 * Can be overridden by child classes for custom logging.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @param string $message Error message to log.
	 *
	 * @return void
	 */
	protected function log_error( $message ) {
		log_error( $message );
	}
}
