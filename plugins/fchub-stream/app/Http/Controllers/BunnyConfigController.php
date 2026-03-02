<?php
/**
 * Bunny.net Stream Configuration Controller.
 *
 * Handles REST API requests for Bunny.net Stream configuration including
 * saving configuration, testing API connections, fetching collections,
 * and managing enabled/disabled status.
 *
 * @package FCHubStream
 * @subpackage Http\Controllers
 * @since 1.0.0
 */

namespace FCHubStream\App\Http\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FCHubStream\App\Http\Controllers\Base\ProviderConfigController;
use FCHubStream\App\Services\StreamConfigService;
use FCHubStream\App\Services\BunnyConfigService;
use FCHubStream\App\Services\BunnyApiService;

/**
 * Bunny.net Stream Configuration Controller.
 *
 * Provides REST API endpoints for managing Bunny.net Stream integration
 * including configuration storage, API connection testing, and collection management.
 *
 * Extends ProviderConfigController to inherit common CRUD operations while
 * providing Bunny.net-specific functionality like collection management.
 *
 * @package FCHubStream
 * @subpackage Http\Controllers
 * @since 1.0.0
 */
class BunnyConfigController extends ProviderConfigController {

	/**
	 * Get provider name.
	 *
	 * Returns the unique identifier for Bunny.net Stream provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string Provider name 'bunny'.
	 */
	protected function get_provider_name() {
		return 'bunny';
	}

	/**
	 * Get credential fields.
	 *
	 * Returns array of credential field names required for Bunny.net Stream.
	 *
	 * @since 1.0.0
	 *
	 * @return array Credential field names: library_id, api_key.
	 */
	protected function get_credential_fields() {
		return array( 'library_id', 'api_key' );
	}

	/**
	 * Get missing credentials error message.
	 *
	 * Returns translated error message shown when Library ID or API Key is missing.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated error message.
	 */
	protected function get_missing_credentials_message() {
		return __( 'Library ID and API Key are required.', 'fchub-stream' );
	}

	/**
	 * Get provider service instance.
	 *
	 * Returns BunnyConfigService instance for connection testing.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Optional. Configuration data for service initialization.
	 *
	 * @return BunnyConfigService Provider service instance.
	 */
	protected function get_service_instance( $config = array() ) {
		return new BunnyConfigService();
	}

	/**
	 * Get provider enabled success message.
	 *
	 * Returns translated success message shown when Bunny.net Stream is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated success message.
	 */
	protected function get_enabled_message() {
		return __( 'Bunny.net Stream enabled successfully.', 'fchub-stream' );
	}

	/**
	 * Get provider disabled success message.
	 *
	 * Returns translated success message shown when Bunny.net Stream is disabled.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated success message.
	 */
	protected function get_disabled_message() {
		return __( 'Bunny.net Stream disabled successfully.', 'fchub-stream' );
	}

	/**
	 * Get Collections for Bunny.net Video Library.
	 *
	 * Handles GET request to retrieve collections from a Bunny.net video library.
	 * Requires library ID and API key either from request parameters or saved
	 * configuration. Returns list of available collections.
	 *
	 * This is a Bunny.net-specific endpoint not available for other providers.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request object with library_id and optional api_key.
	 *
	 * @return WP_REST_Response|WP_Error Collections list or error object.
	 *                                   Response structure: {
	 *                                       @type bool  $success     Whether request was successful.
	 *                                       @type array $collections List of collection objects.
	 *                                   }
	 *
	 * @throws WP_Error If library ID is missing, API key is missing, or exception occurs.
	 */
	public function get_collections( WP_REST_Request $request ) {
		try {
			$library_id = $request->get_param( 'library_id' );
			$api_key    = $request->get_param( 'api_key' );

			if ( empty( $library_id ) ) {
				return new WP_Error(
					'missing_library_id',
					__( 'Library ID is required.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}

			// If API key not provided, try to get from saved config.
			if ( empty( $api_key ) ) {
				$saved_config = StreamConfigService::get_private();
				if ( ! empty( $saved_config['bunny']['api_key'] ) &&
					$saved_config['bunny']['library_id'] === $library_id ) {
					$api_key = $saved_config['bunny']['api_key'];
				} else {
					return new WP_Error(
						'missing_api_key',
						__( 'API Key is required.', 'fchub-stream' ),
						array( 'status' => 400 )
					);
				}
			}

			$api_service = new BunnyApiService( '', $api_key, (int) $library_id );
			$result      = $api_service->list_collections( (int) $library_id, $api_key );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new WP_REST_Response(
				array(
					'success'     => true,
					'collections' => $result['collections'] ?? array(),
				),
				200
			);
		} catch ( \Exception $e ) {
			error_log( '[FCHub Stream] Exception in get_collections: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'get_collections_exception',
				__( 'An error occurred while fetching collections.', 'fchub-stream' ) . ' ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}
