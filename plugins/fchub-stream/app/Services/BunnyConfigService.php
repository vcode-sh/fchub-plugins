<?php
/**
 * Bunny.net Stream Configuration Service
 *
 * @package FCHubStream
 * @subpackage Services
 * @since 1.0.0
 */

namespace FCHubStream\App\Services;

use FCHubStream\App\Services\Base\ProviderConfigService;
use FCHubStream\App\Services\BunnyApiService;

/**
 * Bunny.net Stream Configuration Service
 *
 * Handles Bunny.net-specific configuration logic including
 * encryption/decryption, saving config, testing connections,
 * and validating Bunny.net Stream credentials.
 *
 * Extends ProviderConfigService to inherit common configuration
 * management functionality while providing Bunny.net-specific
 * credential handling.
 *
 * @since 1.0.0
 */
class BunnyConfigService extends ProviderConfigService {

	/**
	 * Get provider name.
	 *
	 * Returns the unique identifier for Bunny.net Stream provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string Provider name 'bunny'.
	 */
	protected static function get_provider_name() {
		return 'bunny';
	}

	/**
	 * Get encrypted field names.
	 *
	 * Returns array of Bunny.net fields requiring encryption.
	 *
	 * @since 1.0.0
	 *
	 * @return array Encrypted field names.
	 */
	protected static function get_encrypted_fields() {
		return array( 'api_key' );
	}

	/**
	 * Get masked field configuration.
	 *
	 * Returns Bunny.net field masking rules for public display.
	 *
	 * @since 1.0.0
	 *
	 * @return array Masked field configuration.
	 */
	protected static function get_masked_fields() {
		return array(
			'api_key' => array(
				'visible_chars' => 6,
				'has_flag'      => 'has_api_key',
				'masked_key'    => 'api_key_masked',
			),
		);
	}

	/**
	 * Get API service class name.
	 *
	 * Returns the Bunny.net API service class name.
	 *
	 * @since 1.0.0
	 *
	 * @return string API service class name.
	 */
	protected static function get_api_service_class() {
		return BunnyApiService::class;
	}

	/**
	 * Get required validation fields.
	 *
	 * Returns Bunny.net required fields for validation.
	 *
	 * @since 1.0.0
	 *
	 * @return array Required field configuration.
	 */
	protected static function get_required_fields() {
		return array(
			'library_id' => array(
				'label'          => __( 'Bunny.net Library ID', 'fchub-stream' ),
				'allow_existing' => false,
			),
			'api_key'    => array(
				'label'          => __( 'API Key', 'fchub-stream' ),
				'allow_existing' => true,
			),
		);
	}

	/**
	 * Perform connection test.
	 *
	 * Creates BunnyApiService instance and tests connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Configuration with decrypted credentials.
	 *
	 * @return array Test result with status and message.
	 */
	protected static function perform_connection_test( array $config ): array {
		$library_id = $config['bunny']['library_id'] ?? '';
		$api_key    = $config['bunny']['api_key'] ?? '';

		if ( empty( $library_id ) || empty( $api_key ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Library ID and API Key are required.', 'fchub-stream' ),
			);
		}

		// Use BunnyApiService to test connection.
		$api_service = new BunnyApiService( '', $api_key, (int) $library_id );
		return $api_service->test_connection( (int) $library_id, $api_key );
	}
}
