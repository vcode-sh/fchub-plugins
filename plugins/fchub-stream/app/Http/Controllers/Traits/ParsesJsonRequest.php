<?php
/**
 * Trait for parsing JSON requests.
 *
 * Provides common methods for parsing JSON data from WordPress REST API requests.
 * Implements multiple fallback strategies to ensure reliable JSON parsing across
 * different request configurations and WordPress environments.
 *
 * @package FCHubStream
 * @subpackage Http\Controllers\Traits
 * @since 1.0.0
 */

namespace FCHubStream\App\Http\Controllers\Traits;

/**
 * JSON request parsing functionality.
 *
 * Provides methods to parse and validate JSON request bodies
 * in REST API endpoints with multiple fallback strategies.
 *
 * @package FCHubStream
 * @subpackage Http\Controllers\Traits
 * @since 1.0.0
 */
trait ParsesJsonRequest {

	/**
	 * Parse JSON data from WordPress REST API request.
	 *
	 * Tries multiple methods to retrieve JSON data in order of preference:
	 * 1. get_json_params() - WordPress automatic JSON parsing
	 * 2. php://input - Direct raw request body reading
	 * 3. get_body() - WP_REST_Request body accessor method
	 * 4. get_params() - Generic parameter fallback
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request WordPress REST API request object.
	 *
	 * @return array|null Parsed JSON data array, or null if parsing fails.
	 */
	protected function parse_json_request( \WP_REST_Request $request ): ?array {
		// Method 1: get_json_params() - WordPress should parse JSON automatically.
		$json_params = $request->get_json_params();
		if ( ! empty( $json_params ) ) {
			return $json_params;
		}

		// Method 2: Read raw body directly from php://input.
		$raw_body = file_get_contents( 'php://input' );
		if ( ! empty( $raw_body ) ) {
			$decoded = json_decode( $raw_body, true );
			if ( json_last_error() === JSON_ERROR_NONE && ! empty( $decoded ) ) {
				return $decoded;
			}
		}

		// Method 3: get_body() method.
		$body = $request->get_body();
		if ( ! empty( $body ) ) {
			$decoded = json_decode( $body, true );
			if ( json_last_error() === JSON_ERROR_NONE && ! empty( $decoded ) ) {
				return $decoded;
			}
		}

		// Method 4: get_params() as last resort.
		$params = $request->get_params();
		unset( $params['_method'], $params['rest_route'] );
		if ( ! empty( $params ) ) {
			return $params;
		}

		return null;
	}
}
