<?php
/**
 * Cloudflare Stream configuration service.
 *
 * Handles Cloudflare Stream-specific configuration management including
 * credential encryption/decryption, validation, and API connection testing.
 *
 * @package FCHub_Stream
 * @subpackage Services
 * @since 1.0.0
 */

namespace FCHubStream\App\Services;

use FCHubStream\App\Services\Base\ProviderConfigService;
use FCHubStream\App\Services\CloudflareApiService;

/**
 * Cloudflare Stream Configuration Service class.
 *
 * Manages Cloudflare Stream provider configuration with secure
 * storage of API tokens and webhook secrets.
 *
 * Extends ProviderConfigService to inherit common configuration
 * management functionality while providing Cloudflare-specific
 * credential handling.
 *
 * @since 1.0.0
 */
class CloudflareConfigService extends ProviderConfigService {

	/**
	 * Get provider name.
	 *
	 * Returns the unique identifier for Cloudflare Stream provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string Provider name 'cloudflare'.
	 */
	protected static function get_provider_name() {
		return 'cloudflare';
	}

	/**
	 * Get encrypted field names.
	 *
	 * Returns array of Cloudflare fields requiring encryption.
	 *
	 * @since 1.0.0
	 *
	 * @return array Encrypted field names.
	 */
	protected static function get_encrypted_fields() {
		return array( 'api_token', 'webhook_secret' );
	}

	/**
	 * Get masked field configuration.
	 *
	 * Returns Cloudflare field masking rules for public display.
	 *
	 * @since 1.0.0
	 *
	 * @return array Masked field configuration.
	 */
	protected static function get_masked_fields() {
		return array(
			'api_token'      => array(
				'visible_chars' => 6,
				'has_flag'      => 'has_api_token',
				'masked_key'    => 'api_token_masked',
			),
			'webhook_secret' => array(
				'visible_chars' => 4,
				'has_flag'      => 'has_webhook_secret',
				'masked_key'    => 'webhook_secret_masked',
			),
		);
	}

	/**
	 * Get API service class name.
	 *
	 * Returns the Cloudflare API service class name.
	 *
	 * @since 1.0.0
	 *
	 * @return string API service class name.
	 */
	protected static function get_api_service_class() {
		return CloudflareApiService::class;
	}

	/**
	 * Get required validation fields.
	 *
	 * Returns Cloudflare required fields for validation.
	 *
	 * @since 1.0.0
	 *
	 * @return array Required field configuration.
	 */
	protected static function get_required_fields() {
		return array(
			'account_id'         => array(
				'label'          => __( 'Cloudflare Account ID', 'fchub-stream' ),
				'allow_existing' => false,
			),
			'api_token'          => array(
				'label'          => __( 'API Token', 'fchub-stream' ),
				'allow_existing' => true,
			),
			'customer_subdomain' => array(
				'label'          => __( 'Customer Subdomain', 'fchub-stream' ),
				'allow_existing' => true,
			),
		);
	}

	/**
	 * Perform connection test.
	 *
	 * Creates CloudflareApiService instance and tests connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Configuration with decrypted credentials.
	 *
	 * @return array Test result with status and message.
	 */
	protected static function perform_connection_test( array $config ): array {
		$account_id = $config['cloudflare']['account_id'] ?? '';
		$api_token  = $config['cloudflare']['api_token'] ?? '';

		if ( empty( $account_id ) || empty( $api_token ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Account ID and API Token are required.', 'fchub-stream' ),
			);
		}

		// Use CloudflareApiService to test connection.
		$api_service = new CloudflareApiService( $account_id, $api_token );
		return $api_service->test_connection();
	}
}
