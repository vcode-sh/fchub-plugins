<?php
/**
 * Cloudflare Stream Configuration Controller.
 *
 * Handles REST API requests for Cloudflare Stream configuration including
 * saving configuration, testing API connections, and managing enabled/disabled status.
 *
 * @package FCHub_Stream
 * @subpackage Http\Controllers
 * @since 1.0.0
 */

namespace FCHubStream\App\Http\Controllers;

use FCHubStream\App\Http\Controllers\Base\ProviderConfigController;
use FCHubStream\App\Services\CloudflareConfigService;
use FCHubStream\App\Services\CloudflareApiService;
use FCHubStream\App\Services\StreamConfigService;
use FCHubStream\App\Models\StreamConfig;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use function FCHubStream\App\Utils\log_debug;

/**
 * Cloudflare Stream Configuration Controller.
 *
 * Provides REST API endpoints for managing Cloudflare Stream integration
 * including configuration storage and API connection testing.
 *
 * Extends ProviderConfigController to inherit common CRUD operations while
 * providing Cloudflare-specific credential management.
 *
 * @package FCHub_Stream
 * @subpackage Http\Controllers
 * @since 1.0.0
 */
class CloudflareConfigController extends ProviderConfigController {

	/**
	 * Get provider name.
	 *
	 * Returns the unique identifier for Cloudflare Stream provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string Provider name 'cloudflare'.
	 */
	protected function get_provider_name() {
		return 'cloudflare';
	}

	/**
	 * Get credential fields.
	 *
	 * Returns array of credential field names required for Cloudflare Stream.
	 *
	 * @since 1.0.0
	 *
	 * @return array Credential field names: account_id, api_token.
	 */
	protected function get_credential_fields() {
		return array( 'account_id', 'api_token' );
	}

	/**
	 * Get missing credentials error message.
	 *
	 * Returns translated error message shown when Account ID or API Token is missing.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated error message.
	 */
	protected function get_missing_credentials_message() {
		return __( 'Account ID and API Token are required.', 'fchub-stream' );
	}

	/**
	 * Get provider service instance.
	 *
	 * Returns CloudflareConfigService instance for connection testing.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Optional. Configuration data for service initialization.
	 *
	 * @return CloudflareConfigService Provider service instance.
	 */
	protected function get_service_instance( $config = array() ) {
		return new CloudflareConfigService();
	}

	/**
	 * Get provider enabled success message.
	 *
	 * Returns translated success message shown when Cloudflare Stream is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated success message.
	 */
	protected function get_enabled_message() {
		return __( 'Cloudflare Stream enabled successfully.', 'fchub-stream' );
	}

	/**
	 * Get provider disabled success message.
	 *
	 * Returns translated success message shown when Cloudflare Stream is disabled.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated success message.
	 */
	protected function get_disabled_message() {
		return __( 'Cloudflare Stream disabled successfully.', 'fchub-stream' );
	}

	/**
	 * Activate webhook
	 *
	 * Configures webhook notification URL in Cloudflare Stream and saves the returned secret.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST API request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with result or error.
	 * @throws \Exception If configuration retrieval, service initialization, or webhook activation fails.
	 */
	public function activate_webhook( WP_REST_Request $request ) {
		// Log immediately - before any operation.
		error_log( '[FCHub Stream] ========== ACTIVATE WEBHOOK START ==========' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Method called' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Request method: ' . $request->get_method() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Request URI: ' . $request->get_route() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		try {
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Inside try block' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Check permissions - must be admin.
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Checking permissions...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$has_permission = current_user_can( 'manage_options' );
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Has permission: ' . ( $has_permission ? 'YES' : 'NO' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			if ( ! $has_permission ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Permission denied' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return new WP_Error(
					'unauthorized',
					__( 'You do not have permission to activate webhook.', 'fchub-stream' ),
					array( 'status' => 403 )
				);
			}

			// Get current config to get credentials.
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Getting decrypted config...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			try {
				$config = StreamConfigService::get_private();
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - get_private() succeeded' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} catch ( \Exception $e ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - get_private() EXCEPTION: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				throw $e;
			}

			// phpcs:disable WordPress.PHP.DevelopmentFunctions -- Debug logging for webhook activation.
			error_log(
				'[FCHub Stream] CloudflareConfigController::activate_webhook() - Config received: ' . print_r(
					array(
						'has_cloudflare' => isset( $config['cloudflare'] ),
						'has_account_id' => ! empty( $config['cloudflare']['account_id'] ?? '' ),
						'has_api_token'  => ! empty( $config['cloudflare']['api_token'] ?? '' ),
					),
					true
				)
			);
			// phpcs:enable WordPress.PHP.DevelopmentFunctions

			if ( empty( $config['cloudflare']['account_id'] ) || empty( $config['cloudflare']['api_token'] ) ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Missing credentials' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return new WP_Error(
					'missing_credentials',
					__( 'Cloudflare Account ID and API Token must be configured first.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}

			// Build webhook URL.
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Building webhook URL...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$webhook_url = rest_url( 'fluent-community/v2/stream/webhook/cloudflare_stream' );
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Webhook URL: ' . $webhook_url ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Configure webhook in Cloudflare.
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Creating CloudflareApiService...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			try {
				$api_service = new CloudflareApiService(
					$config['cloudflare']['account_id'],
					$config['cloudflare']['api_token']
				);
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - CloudflareApiService created successfully' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} catch ( \Exception $e ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - CloudflareApiService creation EXCEPTION: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				throw $e;
			}

			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Calling set_webhook()...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$webhook_result = $api_service->set_webhook( $webhook_url );

			if ( is_wp_error( $webhook_result ) ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - set_webhook() failed: ' . $webhook_result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return $webhook_result;
			}

			// phpcs:disable WordPress.PHP.DevelopmentFunctions -- Debug logging for webhook activation.
			error_log(
				'[FCHub Stream] CloudflareConfigController::activate_webhook() - Webhook result: ' . print_r(
					array(
						'has_secret'          => isset( $webhook_result['secret'] ),
						'has_notificationUrl' => isset( $webhook_result['notificationUrl'] ),
					),
					true
				)
			);
			// phpcs:enable WordPress.PHP.DevelopmentFunctions

			// Extract secret from Cloudflare response.
			$webhook_secret = $webhook_result['secret'] ?? '';

			if ( empty( $webhook_secret ) ) {
				// phpcs:disable WordPress.PHP.DevelopmentFunctions -- Debug logging for webhook activation.
				log_debug( 'CloudflareConfigController::activate_webhook() - No secret in response: ' . print_r( $webhook_result, true ) );
				// phpcs:enable WordPress.PHP.DevelopmentFunctions
				return new WP_Error(
					'no_secret',
					__( 'Cloudflare did not return a webhook secret.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Webhook secret received: ' . substr( $webhook_secret, 0, 10 ) . '...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Save webhook secret to config.
			// Get encrypted config from database (ProviderConfigService::save expects encrypted values).
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Getting encrypted config from database...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			try {
				$current_config = StreamConfig::get();
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - StreamConfig::get() succeeded' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} catch ( \Exception $e ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - StreamConfig::get() EXCEPTION: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				throw $e;
			}

			// StreamConfig::get() already merges with defaults, so we can use it directly.
			// phpcs:disable WordPress.PHP.DevelopmentFunctions -- Debug logging for webhook activation.
			error_log(
				'[FCHub Stream] CloudflareConfigController::activate_webhook() - Config structure: ' . print_r(
					array(
						'has_cloudflare' => isset( $current_config['cloudflare'] ),
						'keys'           => array_keys( $current_config ),
					),
					true
				)
			);
			// phpcs:enable WordPress.PHP.DevelopmentFunctions

			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Calling CloudflareConfigService::save()...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			try {
				$save_result = CloudflareConfigService::save( $current_config, array( 'webhook_secret' => $webhook_secret ) );
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - CloudflareConfigService::save() succeeded' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} catch ( \Exception $e ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - CloudflareConfigService::save() EXCEPTION: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				throw $e;
			}

			if ( ! $save_result['success'] ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Save failed: ' . ( $save_result['message'] ?? 'Unknown error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return new WP_Error(
					'save_failed',
					__( 'Failed to save webhook secret.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Save result success, saving to database...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Save to database using StreamConfig.
			try {
				$saved = StreamConfig::save( $save_result['config'] );
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - StreamConfig::save() result: ' . ( $saved ? 'TRUE' : 'FALSE' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} catch ( \Exception $e ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - StreamConfig::save() EXCEPTION: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				throw $e;
			}

			if ( ! $saved ) {
				error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - Database save failed' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return new WP_Error(
					'database_save_failed',
					__( 'Failed to save webhook secret to database.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - SUCCESS' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] ========== ACTIVATE WEBHOOK SUCCESS ==========' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Webhook activated successfully. Secret has been saved.', 'fchub-stream' ),
					'data'    => array(
						'notification_url' => $webhook_result['notificationUrl'] ?? $webhook_url,
						'has_secret'       => true,
					),
				),
				200
			);
		} catch ( \Exception $e ) {
			error_log( '[FCHub Stream] ========== ACTIVATE WEBHOOK EXCEPTION ==========' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - EXCEPTION: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - EXCEPTION Class: ' . get_class( $e ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - EXCEPTION File: ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - EXCEPTION Trace: ' . $e->getTraceAsString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] ========== ACTIVATE WEBHOOK EXCEPTION END ==========' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'webhook_activation_exception',
				__( 'An error occurred while activating webhook.', 'fchub-stream' ) . ' ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		} catch ( \Error $e ) {
			error_log( '[FCHub Stream] ========== ACTIVATE WEBHOOK FATAL ERROR ==========' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - FATAL ERROR: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - FATAL ERROR Class: ' . get_class( $e ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - FATAL ERROR File: ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] CloudflareConfigController::activate_webhook() - FATAL ERROR Trace: ' . $e->getTraceAsString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] ========== ACTIVATE WEBHOOK FATAL ERROR END ==========' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'webhook_activation_fatal_error',
				__( 'A fatal error occurred while activating webhook.', 'fchub-stream' ) . ' ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}
