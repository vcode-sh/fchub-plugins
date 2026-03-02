<?php
/**
 * Abstract Provider Configuration Service.
 *
 * Base service for stream provider configuration management.
 * Provides common functionality for credential encryption, masking, validation,
 * and connection testing across all stream providers.
 *
 * @package FCHub_Stream
 * @subpackage Services\Base
 * @since 1.0.0
 */

namespace FCHubStream\App\Services\Base;

use FCHubStream\App\Models\StreamConfig;
use FCHubStream\App\Utils\EncryptionService;

/**
 * Abstract Provider Configuration Service Class.
 *
 * Implements Template Method pattern to provide common configuration
 * management functionality while allowing child classes to customize
 * provider-specific operations.
 *
 * Child classes must implement:
 * - get_provider_name(): Return provider identifier
 * - get_encrypted_fields(): Return fields requiring encryption
 * - get_masked_fields(): Return field masking configuration
 * - get_api_service_class(): Return API service class name
 * - get_required_fields(): Return required validation fields
 *
 * @since 1.0.0
 */
abstract class ProviderConfigService {

	/**
	 * Get provider name.
	 *
	 * Returns the unique identifier for the stream provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string Provider name (e.g., 'cloudflare', 'bunny').
	 */
	abstract protected static function get_provider_name();

	/**
	 * Get encrypted field names.
	 *
	 * Returns array of field names that require encryption before storage.
	 *
	 * @since 1.0.0
	 *
	 * @return array Encrypted field names.
	 *               Example: array( 'api_token', 'webhook_secret' )
	 */
	abstract protected static function get_encrypted_fields();

	/**
	 * Get masked field configuration.
	 *
	 * Returns array of fields to mask for public display with masking rules.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Masked field configuration.
	 *
	 *     @type array $field_name {
	 *         Field masking configuration.
	 *
	 *         @type int    $visible_chars Number of characters to show at end.
	 *         @type string $has_flag      Flag name for "has_X" indicator.
	 *         @type string $masked_key    Key name for masked value.
	 *     }
	 * }
	 *               Example: array(
	 *                   'api_token' => array(
	 *                       'visible_chars' => 6,
	 *                       'has_flag' => 'has_api_token',
	 *                       'masked_key' => 'api_token_masked'
	 *                   )
	 *               )
	 */
	abstract protected static function get_masked_fields();

	/**
	 * Get API service class name.
	 *
	 * Returns the fully-qualified class name of the provider's API service.
	 *
	 * @since 1.0.0
	 *
	 * @return string API service class name.
	 *                Example: 'FCHubStream\\App\\Services\\CloudflareApiService'
	 */
	abstract protected static function get_api_service_class();

	/**
	 * Get required validation fields.
	 *
	 * Returns array of field names required for initial configuration.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Required field configuration.
	 *
	 *     @type array $field_name {
	 *         Field validation configuration.
	 *
	 *         @type string $label         Field label for error messages.
	 *         @type bool   $allow_existing Whether existing value satisfies requirement.
	 *     }
	 * }
	 *               Example: array(
	 *                   'api_token' => array(
	 *                       'label' => 'API Token',
	 *                       'allow_existing' => true
	 *                   )
	 *               )
	 */
	abstract protected static function get_required_fields();

	/**
	 * Mask sensitive data for public display.
	 *
	 * Template Method: Masks sensitive credentials showing only last N characters.
	 * Uses child class configuration for field-specific masking rules.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Configuration array with provider settings.
	 *
	 * @return array Configuration with masked sensitive data.
	 */
	public static function mask_sensitive_data( array $config ): array {
		$provider      = static::get_provider_name();
		$masked_fields = static::get_masked_fields();

		foreach ( $masked_fields as $field => $mask_config ) {
			if ( ! empty( $config[ $provider ][ $field ] ) ) {
				$config[ $provider ][ $mask_config['has_flag'] ] = true;
				$decrypted                                       = EncryptionService::decrypt( $config[ $provider ][ $field ] );

				if ( false !== $decrypted && strlen( $decrypted ) > $mask_config['visible_chars'] ) {
					$config[ $provider ][ $mask_config['masked_key'] ] = str_repeat(
						'*',
						strlen( $decrypted ) - $mask_config['visible_chars']
					) . substr( $decrypted, -$mask_config['visible_chars'] );
				} else {
					$config[ $provider ][ $mask_config['masked_key'] ] = str_repeat( '*', $mask_config['visible_chars'] );
				}

				$config[ $provider ][ $field ] = ''; // Never expose full credential.
			} else {
				$config[ $provider ][ $mask_config['has_flag'] ]   = false;
				$config[ $provider ][ $mask_config['masked_key'] ] = '';
			}
		}

		return $config;
	}

	/**
	 * Decrypt sensitive data.
	 *
	 * Template Method: Decrypts encrypted credentials for internal use.
	 * WARNING: Only use internally - never expose to frontend!
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Configuration array with encrypted credentials.
	 *
	 * @return array Configuration with decrypted sensitive data.
	 */
	public static function decrypt_sensitive_data( array $config ): array {
		$provider         = static::get_provider_name();
		$encrypted_fields = static::get_encrypted_fields();

		foreach ( $encrypted_fields as $field ) {
			if ( ! empty( $config[ $provider ][ $field ] ) ) {
				$decrypted = EncryptionService::decrypt( $config[ $provider ][ $field ] );
				if ( false !== $decrypted ) {
					$config[ $provider ][ $field ] = $decrypted;
				}
			}
		}

		return $config;
	}

	/**
	 * Save provider configuration.
	 *
	 * Template Method: Validates and encrypts credentials before saving.
	 * Encrypts sensitive fields and updates configuration timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @param array $current_config Current configuration array.
	 * @param array $provider_data  Provider-specific data to save.
	 *
	 * @return array {
	 *     Save operation result.
	 *
	 *     @type bool   $success Whether save succeeded.
	 *     @type string $message Success or error message.
	 *     @type array  $config  Updated configuration (only on success).
	 * }
	 */
	public static function save( array $current_config, array $provider_data ): array {
		$provider         = static::get_provider_name();
		$encrypted_fields = static::get_encrypted_fields();

		error_log( '[FCHub Stream] ProviderConfigService::save() - provider: ' . $provider . ', incoming fields: ' . implode( ', ', array_keys( $provider_data ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Process all fields from provider_data.
		foreach ( $provider_data as $field => $value ) {
			// Skip encryption for encrypted fields (handle separately).
			if ( in_array( $field, $encrypted_fields, true ) ) {
				continue;
			}

			// For non-encrypted fields, sanitize and save directly.
			if ( 'enabled' === $field ) {
				$current_config[ $provider ][ $field ] = (bool) $value;
			} elseif ( 'customer_subdomain' === $field && 'cloudflare' === $provider ) {
				// Normalize customer_subdomain for Cloudflare.
				$normalized                            = static::normalize_customer_subdomain( $value );
				$current_config[ $provider ][ $field ] = $normalized;
				error_log( '[FCHub Stream] ProviderConfigService::save() - Saved normalized customer_subdomain: ' . substr( $normalized, 0, 50 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				$current_config[ $provider ][ $field ] = sanitize_text_field( $value );
				error_log( '[FCHub Stream] ProviderConfigService::save() - Saved field: ' . $field . ' = ' . substr( sanitize_text_field( $value ), 0, 50 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// Handle encrypted fields.
		foreach ( $encrypted_fields as $field ) {
			if ( isset( $provider_data[ $field ] ) && ! empty( trim( $provider_data[ $field ] ) ) ) {
				$encrypted = EncryptionService::encrypt( trim( $provider_data[ $field ] ) );
				if ( false === $encrypted ) {
					return array(
						'success' => false,
						'message' => sprintf(
							/* translators: %s: field name */
							__( 'Failed to encrypt %s. Please check PHP OpenSSL extension.', 'fchub-stream' ),
							$field
						),
					);
				}
				$current_config[ $provider ][ $field ] = $encrypted;
			}
		}

		// Update configured_at timestamp.
		$current_config[ $provider ]['configured_at'] = current_time( 'mysql' );

		return array(
			'success' => true,
			'config'  => $current_config,
		);
	}

	/**
	 * Test provider API connection.
	 *
	 * Template Method: Verifies credentials by making test API call.
	 * Automatically decrypts encrypted credentials before testing.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $config Optional configuration to test. Uses saved config if null.
	 *
	 * @return array {
	 *     Test result.
	 *
	 *     @type string $status   Test status ('success' or 'error').
	 *     @type string $message  Success or error message.
	 * }
	 */
	public static function test_connection( ?array $config = null ): array {
		$provider = static::get_provider_name();

		if ( null === $config ) {
			$config = \FCHubStream\App\Services\StreamConfigService::get_private();
		} else {
			// Decrypt if needed (long encrypted values).
			$encrypted_fields = static::get_encrypted_fields();
			foreach ( $encrypted_fields as $field ) {
				if ( ! empty( $config[ $provider ][ $field ] ) && strlen( $config[ $provider ][ $field ] ) > 100 ) {
					$decrypted = EncryptionService::decrypt( $config[ $provider ][ $field ] );
					if ( false !== $decrypted ) {
						$config[ $provider ][ $field ] = $decrypted;
					}
				}
			}
		}

		// Delegate to child class to create API service and test.
		return static::perform_connection_test( $config );
	}

	/**
	 * Test provider API connection (instance method).
	 *
	 * Instance method wrapper for test_connection() static method.
	 * Allows calling test_connection() on service instances from controllers.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $config Optional configuration to test. Uses saved config if null.
	 *
	 * @return array {
	 *     Test result.
	 *
	 *     @type string $status   Test status ('success' or 'error').
	 *     @type string $message  Success or error message.
	 * }
	 */
	public function test_connection_instance( ?array $config = null ): array {
		return static::test_connection( $config );
	}

	/**
	 * Perform connection test.
	 *
	 * Child classes must implement this to create their API service
	 * instance and perform the connection test.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Configuration with decrypted credentials.
	 *
	 * @return array Test result with status and message.
	 */
	abstract protected static function perform_connection_test( array $config ): array;

	/**
	 * Normalize customer subdomain.
	 *
	 * Extracts only the subdomain part from customer_subdomain value.
	 * Handles both full URLs and domain-only formats.
	 *
	 * @since 1.0.0
	 *
	 * @param string $customer_subdomain Raw customer subdomain value.
	 *
	 * @return string Normalized subdomain (e.g., 'customer-abc123').
	 */
	protected static function normalize_customer_subdomain( string $customer_subdomain ): string {
		if ( empty( $customer_subdomain ) ) {
			return '';
		}

		$normalized = sanitize_text_field( $customer_subdomain );

		// If contains full URL, extract just the subdomain part.
		if ( preg_match( '/https?:\/\/(customer-[a-z0-9]+)\.cloudflarestream\.com/', $normalized, $matches ) ) {
			return $matches[1];
		}

		// If contains domain without protocol, extract subdomain.
		if ( preg_match( '/^(customer-[a-z0-9]+)\.cloudflarestream\.com/', $normalized, $matches ) ) {
			return $matches[1];
		}

		// Otherwise assume it's already just the subdomain (customer-xxx).
		return $normalized;
	}

	/**
	 * Validate provider configuration data.
	 *
	 * Template Method: Checks that required fields are present and not empty.
	 * Allows omitting encrypted fields if they already exist in saved configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $provider_data Provider configuration data to validate.
	 *
	 * @return array Array of error messages (empty if valid).
	 */
	public static function validate( array $provider_data ): array {
		$errors          = array();
		$provider        = static::get_provider_name();
		$required_fields = static::get_required_fields();
		$existing_config = StreamConfig::get();

		foreach ( $required_fields as $field => $field_config ) {
			$has_existing = $field_config['allow_existing'] &&
				! empty( $existing_config[ $provider ][ $field ] );

			if ( isset( $provider_data[ $field ] ) ) {
				$value = trim( sanitize_text_field( $provider_data[ $field ] ) );
				if ( empty( $value ) && ! $has_existing ) {
					$errors[] = sprintf(
						/* translators: %s: field label */
						__( '%s cannot be empty.', 'fchub-stream' ),
						$field_config['label']
					);
				}
			} elseif ( ! $has_existing ) {
				$errors[] = sprintf(
					/* translators: %s: field label */
					__( '%s is required for initial configuration.', 'fchub-stream' ),
					$field_config['label']
				);
			}
		}

		return $errors;
	}
}
