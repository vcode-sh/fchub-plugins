<?php
/**
 * REST API Controller for stream configuration.
 *
 * @package FCHub_Stream
 * @subpackage Http\Controllers
 * @since 1.0.0
 */

namespace FCHubStream\App\Http\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FCHubStream\App\Services\StreamConfigService;
use FCHubStream\App\Models\StreamConfig;
use FCHubStream\App\Http\Controllers\Traits\ParsesJsonRequest;

/**
 * Main Stream Configuration Controller.
 *
 * Handles shared configuration operations across all stream providers.
 *
 * @since 1.0.0
 */
class StreamConfigController {
	use ParsesJsonRequest;

	/**
	 * Retrieve stream configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response Response with configuration data.
	 */
	public function get( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API interface.
		$config = StreamConfigService::get_public();

		return new WP_REST_Response(
			array(
				'success' => true,
				'config'  => $config,
			),
			200
		);
	}

	/**
	 * Remove all stream configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with success message, or error on failure.
	 */
	public function remove( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API interface.
		try {
			$deleted = StreamConfig::delete();

			if ( ! $deleted ) {
				return new WP_Error(
					'delete_failed',
					__( 'Failed to remove configuration.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Configuration removed successfully.', 'fchub-stream' ),
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

	/**
	 * Update active provider.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with success message, or error on failure.
	 */
	public function update_provider( WP_REST_Request $request ) {
		try {
			$data = $this->parse_json_request( $request );

			if ( null === $data || empty( $data ) || ! isset( $data['provider'] ) ) {
				return new WP_Error(
					'invalid_data',
					__( 'Provider is required.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}

			$provider = sanitize_text_field( $data['provider'] );

			if ( ! in_array( $provider, array( 'cloudflare', 'bunny' ), true ) ) {
				return new WP_Error(
					'invalid_provider',
					__( 'Invalid provider. Must be "cloudflare" or "bunny".', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}

			// Get current config.
			$config = StreamConfig::get();

			if ( ! is_array( $config ) ) {
				return new WP_Error(
					'invalid_config',
					__( 'Invalid configuration retrieved.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			// Update provider.
			$config['provider'] = $provider;

			// Save updated config.
			$saved = StreamConfig::save( $config );

			if ( ! $saved ) {
				return new WP_Error(
					'save_failed',
					__( 'Failed to update provider.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf(
						/* translators: %s: Provider name */
						__( 'Active provider changed to %s.', 'fchub-stream' ),
						'cloudflare' === $provider ? 'Cloudflare Stream' : 'Bunny.net Stream'
					),
				),
				200
			);
		} catch ( \Exception $e ) {
			error_log( '[FCHub Stream] Exception in update_provider: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'update_provider_exception',
				__( 'An error occurred while updating provider.', 'fchub-stream' ) . ' ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}
