<?php
/**
 * Abstract Provider Configuration Controller.
 *
 * Base controller for stream provider configuration management.
 * Provides common functionality for saving configuration, testing connections,
 * and managing enabled status across all stream providers (Cloudflare, Bunny.net, etc.).
 *
 * @package FCHub_Stream
 * @subpackage Http\Controllers\Base
 * @since 1.0.0
 */

namespace FCHubStream\App\Http\Controllers\Base;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FCHubStream\App\Services\StreamConfigService;
use FCHubStream\App\Http\Controllers\Traits\ParsesJsonRequest;
use FCHubStream\App\Models\StreamConfig;

/**
 * Abstract Provider Configuration Controller Class.
 *
 * Implements Template Method pattern to provide common REST API functionality
 * for stream provider configuration while allowing child classes to customize
 * provider-specific operations through abstract methods.
 *
 * Child classes must implement:
 * - get_provider_name(): Return provider identifier (e.g., 'cloudflare', 'bunny')
 * - get_credential_fields(): Return array of required credential field names
 * - get_missing_credentials_message(): Return error message for missing credentials
 * - get_service_instance(): Return provider-specific service instance
 *
 * @since 1.0.0
 */
abstract class ProviderConfigController {

	use ParsesJsonRequest;

	/**
	 * Get provider name.
	 *
	 * Returns the unique identifier for the stream provider.
	 * Used in configuration keys, validation, and error messages.
	 *
	 * @since 1.0.0
	 *
	 * @return string Provider name (e.g., 'cloudflare', 'bunny').
	 */
	abstract protected function get_provider_name();

	/**
	 * Get credential fields.
	 *
	 * Returns array of credential field names required for the provider.
	 * Used to extract and validate credentials from request data.
	 *
	 * @since 1.0.0
	 *
	 * @return array Credential field names.
	 *               Example for Cloudflare: array( 'account_id', 'api_token' )
	 *               Example for Bunny: array( 'library_id', 'api_key' )
	 */
	abstract protected function get_credential_fields();

	/**
	 * Get missing credentials error message.
	 *
	 * Returns translated error message shown when required credentials are missing.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated error message.
	 */
	abstract protected function get_missing_credentials_message();

	/**
	 * Get provider service instance.
	 *
	 * Returns the provider-specific service instance used for connection testing.
	 * Service instance should implement a test_connection() method.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Optional. Configuration data for service initialization.
	 *
	 * @return object Provider service instance.
	 */
	abstract protected function get_service_instance( $config = array() );

	/**
	 * Get provider enabled success message.
	 *
	 * Returns translated success message shown when provider is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated success message.
	 */
	abstract protected function get_enabled_message();

	/**
	 * Get provider disabled success message.
	 *
	 * Returns translated success message shown when provider is disabled.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated success message.
	 */
	abstract protected function get_disabled_message();

	/**
	 * Save provider configuration.
	 *
	 * Handles POST request to save provider API credentials and settings.
	 * Validates input data and optionally tests API connection before saving.
	 *
	 * Template Method: Implements common save logic while delegating
	 * provider-specific operations to child classes.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request object containing provider configuration.
	 *
	 * @return WP_REST_Response|WP_Error Response with save result, or error on validation/save failure.
	 *                                   Response structure: {
	 *                                       @type bool   $success     Whether save was successful.
	 *                                       @type string $message     Success or error message.
	 *                                       @type array  $test_result Optional. Connection test results.
	 *                                   }
	 *
	 * @throws WP_Error If request data is invalid, validation fails, or exception occurs.
	 */
	public function save( WP_REST_Request $request ) {
		try {
			$data = $this->parse_json_request( $request );

			if ( empty( $data ) ) {
				return new WP_Error(
					'invalid_data',
					__( 'Invalid request data. No data received.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}

			$provider = $this->get_provider_name();

			// Ensure provider data exists.
			if ( ! isset( $data[ $provider ] ) ) {
				$data[ $provider ] = array();
			}

			// Set provider.
			$data['provider'] = $provider;

			// Validate data.
			$validation = StreamConfigService::validate( $data );
			if ( ! $validation['valid'] ) {
				return new WP_Error(
					'validation_error',
					implode( ' ', $validation['errors'] ),
					array( 'status' => 400 )
				);
			}

			// Check if test connection is requested.
			$test_connection = isset( $data['test_connection'] ) && true === $data['test_connection'];

			// Save configuration.
			$result = StreamConfigService::save( $data, $test_connection );

			if ( ! $result['success'] ) {
				return new WP_Error(
					'save_failed',
					$result['message'],
					array( 'status' => 500 )
				);
			}

			$response_data = array(
				'success' => true,
				'message' => $result['message'],
			);

			if ( isset( $result['test_result'] ) ) {
				$response_data['test_result'] = $result['test_result'];
			}

			return new WP_REST_Response( $response_data, 200 );
		} catch ( \Exception $e ) {
			error_log( '[FCHub Stream] Exception in save: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'save_exception',
				__( 'An error occurred while saving configuration.', 'fchub-stream' ) . ' ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Test provider API connection.
	 *
	 * Handles POST request to verify provider API credentials.
	 * Tests connection using provided credentials or saved configuration.
	 * Makes API call to provider to verify account access.
	 *
	 * Template Method: Implements common test logic while delegating
	 * credential extraction and service instantiation to child classes.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with test results, or error on failure.
	 *                                   Response structure: {
	 *                                       @type bool   $success Whether test was successful.
	 *                                       @type string $status  Test status ('success' or 'error').
	 *                                       @type string $message Test result message.
	 *                                   }
	 *
	 * @throws WP_Error If credentials are missing or exception occurs.
	 */
	public function test_connection( WP_REST_Request $request ) {
		try {
			$data     = $this->parse_json_request( $request );
			$provider = $this->get_provider_name();

			if ( empty( $data ) ) {
				// Try to use saved config.
				$saved_config = StreamConfigService::get_private();
				$credentials  = $this->extract_credentials_from_config( $saved_config );

				if ( $credentials ) {
					$service     = $this->get_service_instance();
					$test_result = $service->test_connection_instance();

					return new WP_REST_Response(
						array(
							'success' => true,
							'status'  => $test_result['status'],
							'message' => $test_result['message'],
						),
						200
					);
				} else {
					return new WP_Error(
						'invalid_data',
						$this->get_missing_credentials_message(),
						array( 'status' => 400 )
					);
				}
			}

			// Prepare config for testing.
			$credentials = $this->extract_credentials_from_request( $data );

			// Always merge with saved config to fill in missing credential fields.
			$saved_config = StreamConfigService::get_private();
			$credentials  = $this->extract_credentials_from_config( $saved_config, $credentials );

			// Validate merged credentials.
			if ( ! $this->validate_credentials( $credentials ) ) {
				return new WP_Error(
					'missing_credentials',
					$this->get_missing_credentials_message(),
					array( 'status' => 400 )
				);
			}

			// Test connection.
			$test_config = array(
				$provider => $credentials,
			);

			$service     = $this->get_service_instance( $test_config );
			$test_result = $service->test_connection_instance( $test_config );

			return new WP_REST_Response(
				array(
					'success' => true,
					'status'  => $test_result['status'],
					'message' => $test_result['message'],
				),
				200
			);
		} catch ( \Exception $e ) {
			error_log( '[FCHub Stream] Exception in test_connection: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'test_exception',
				__( 'An error occurred while testing connection.', 'fchub-stream' ) . ' ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Update enabled status for provider.
	 *
	 * Handles POST request to enable or disable stream provider.
	 * Updates the enabled flag in configuration without modifying credentials.
	 *
	 * Template Method: Implements common enable/disable logic while delegating
	 * provider name and success messages to child classes.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with success message, or error on failure.
	 *                                   Response structure: {
	 *                                       @type bool   $success Whether update was successful.
	 *                                       @type string $message Success or error message.
	 *                                   }
	 *
	 * @throws WP_Error If enabled status is missing, save fails, or exception occurs.
	 */
	public function update_enabled( WP_REST_Request $request ) {
		try {
			$data = $this->parse_json_request( $request );

			if ( empty( $data ) || ! isset( $data['enabled'] ) ) {
				return new WP_Error(
					'invalid_data',
					__( 'Enabled status is required.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}

			$enabled  = (bool) $data['enabled'];
			$provider = $this->get_provider_name();

			// Get current config.
			$config = StreamConfig::get();

			// Update enabled status.
			$config[ $provider ]['enabled'] = $enabled;

			// Save updated config.
			$saved = StreamConfig::save( $config );

			if ( ! $saved ) {
				return new WP_Error(
					'save_failed',
					__( 'Failed to update enabled status.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => $enabled
						? $this->get_enabled_message()
						: $this->get_disabled_message(),
				),
				200
			);
		} catch ( \Exception $e ) {
			error_log( '[FCHub Stream] Exception in update_enabled: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'update_exception',
				__( 'An error occurred while updating enabled status.', 'fchub-stream' ) . ' ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Extract credentials from request data.
	 *
	 * Extracts and sanitizes credential values from request data.
	 * Uses credential field names from get_credential_fields().
	 * Supports both formats:
	 * - Direct: { library_id: '123', api_key: 'key' }
	 * - Nested: { bunny: { library_id: '123', api_key: 'key' } }
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Request data.
	 *
	 * @return array|null Credentials array, or null if any credential is missing.
	 */
	protected function extract_credentials_from_request( $data ) {
		$provider    = $this->get_provider_name();
		$fields      = $this->get_credential_fields();
		$credentials = array();

		// Try provider-nested format first (e.g., { bunny: { library_id: '123' } }).
		if ( isset( $data[ $provider ] ) && is_array( $data[ $provider ] ) ) {
			foreach ( $fields as $field ) {
				if ( isset( $data[ $provider ][ $field ] ) ) {
					$credentials[ $field ] = sanitize_text_field( $data[ $provider ][ $field ] );
				}
			}
		} else {
			// Try direct format (e.g., { library_id: '123', api_key: 'key' }).
			foreach ( $fields as $field ) {
				if ( isset( $data[ $field ] ) ) {
					$credentials[ $field ] = sanitize_text_field( $data[ $field ] );
				}
			}
		}

		return ! empty( $credentials ) ? $credentials : null;
	}

	/**
	 * Extract credentials from saved config.
	 *
	 * Extracts credential values from saved configuration.
	 * Merges with existing credentials if provided.
	 *
	 * @since 1.0.0
	 *
	 * @param array      $saved_config Saved configuration data.
	 * @param array|null $credentials  Optional. Existing credentials to merge with.
	 *
	 * @return array|null Credentials array, or null if provider config doesn't exist.
	 */
	protected function extract_credentials_from_config( $saved_config, $credentials = null ) {
		$provider = $this->get_provider_name();

		if ( empty( $saved_config[ $provider ] ) ) {
			return $credentials;
		}

		if ( null === $credentials ) {
			$credentials = array();
		}

		$fields = $this->get_credential_fields();

		foreach ( $fields as $field ) {
			if ( empty( $credentials[ $field ] ) && ! empty( $saved_config[ $provider ][ $field ] ) ) {
				$credentials[ $field ] = $saved_config[ $provider ][ $field ];
			}
		}

		return $this->validate_credentials( $credentials ) ? $credentials : null;
	}

	/**
	 * Validate credentials.
	 *
	 * Checks if all required credential fields are present and not empty.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $credentials Credentials array to validate.
	 *
	 * @return bool True if all required credentials are present, false otherwise.
	 */
	protected function validate_credentials( $credentials ) {
		if ( null === $credentials ) {
			return false;
		}

		$fields = $this->get_credential_fields();

		foreach ( $fields as $field ) {
			if ( empty( $credentials[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove provider configuration.
	 *
	 * Handles DELETE request to remove configuration for this specific provider only.
	 * Removes provider credentials and settings while preserving other providers' configuration.
	 *
	 * Template Method: Implements common remove logic while delegating
	 * provider name to child classes.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with success message, or error on failure.
	 *                                   Response structure: {
	 *                                       @type bool   $success Whether removal was successful.
	 *                                       @type string $message Success or error message.
	 *                                   }
	 *
	 * @throws WP_Error If deletion fails or exception occurs.
	 */
	public function remove( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API interface.
		try {
			$provider = $this->get_provider_name();

			// Get current config.
			$config = StreamConfig::get();

			if ( ! is_array( $config ) ) {
				return new WP_Error(
					'invalid_config',
					__( 'Invalid configuration retrieved.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			// Remove only this provider's configuration.
			if ( isset( $config[ $provider ] ) ) {
				unset( $config[ $provider ] );
			}

			// If this was the active provider, reset to default or first available.
			if ( isset( $config['provider'] ) && $provider === $config['provider'] ) {
				// Check if other provider is configured.
				$other_provider = 'cloudflare' === $provider ? 'bunny' : 'cloudflare';
				if ( ! empty( $config[ $other_provider ] ) && ! empty( $config[ $other_provider ]['enabled'] ) ) {
					$config['provider'] = $other_provider;
				} else {
					// No other provider configured, reset to default.
					$config['provider'] = 'cloudflare';
				}
			}

			// Save updated config.
			$saved = StreamConfig::save( $config );

			if ( ! $saved ) {
				return new WP_Error(
					'delete_failed',
					__( 'Failed to remove configuration.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			$provider_name = 'cloudflare' === $provider ? 'Cloudflare Stream' : 'Bunny.net Stream';

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf(
						/* translators: %s: Provider name */
						__( '%s configuration removed successfully.', 'fchub-stream' ),
						$provider_name
					),
				),
				200
			);
		} catch ( \Exception $e ) {
			error_log( '[FCHub Stream] Exception in remove: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'remove_exception',
				__( 'An error occurred while removing configuration.', 'fchub-stream' ) . ' ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}
