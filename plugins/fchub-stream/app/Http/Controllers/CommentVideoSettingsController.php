<?php
/**
 * Comment Video Settings Controller
 *
 * Handles REST API endpoints for managing video upload settings specific to comments.
 * Only controls enabled/disabled flag - all other settings (size, format, duration)
 * are inherited from main upload settings.
 *
 * @package FCHubStream
 * @subpackage Http\Controllers
 * @since 1.0.0
 */

namespace FCHubStream\App\Http\Controllers;

use FCHubStream\App\Http\Controllers\Traits\ParsesJsonRequest;
use FCHubStream\App\Services\StreamConfigService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Comment Video Settings Controller class.
 *
 * Manages comment video configuration settings through REST API endpoints.
 * Provides CRUD operations for comment-specific video upload limits and validation rules.
 *
 * @since 1.0.0
 */
class CommentVideoSettingsController {

	use ParsesJsonRequest;

	/**
	 * Get comment video settings
	 *
	 * Handles GET /stream/settings/comment-video endpoint.
	 * Returns current comment video settings configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST API request object. Unused but required by REST API signature.
	 *
	 * @return WP_REST_Response|WP_Error Response with settings or error.
	 */
	public function get( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API signature.
		// Check permissions - must be admin.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'unauthorized',
				__( 'You do not have permission to access comment video settings.', 'fchub-stream' ),
				array( 'status' => 403 )
			);
		}

		$settings = StreamConfigService::get_comment_video_settings();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $settings,
			),
			200
		);
	}

	/**
	 * Save comment video settings
	 *
	 * Handles POST /stream/settings/comment-video endpoint.
	 * Updates comment video settings configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST API request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with result or error.
	 */
	public function save( WP_REST_Request $request ) {
		// Check permissions - must be admin.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'unauthorized',
				__( 'You do not have permission to modify comment video settings.', 'fchub-stream' ),
				array( 'status' => 403 )
			);
		}

		// Parse JSON request data using trait method.
		$data = $this->parse_json_request( $request );

		// Check if data is empty.
		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_Error(
				'invalid_data',
				__( 'Invalid request data. No data received.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Validate settings.
		$validation = $this->validate_comment_video_settings( $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Save settings using StreamConfigService.
		$result = StreamConfigService::save_comment_video_settings( $data );

		if ( ! $result['success'] ) {
			return new WP_Error(
				'save_failed',
				$result['message'] ?? __( 'Failed to save comment video settings.', 'fchub-stream' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => StreamConfigService::get_comment_video_settings(),
				'message' => $result['message'] ?? __( 'Comment video settings saved successfully.', 'fchub-stream' ),
			),
			200
		);
	}

	/**
	 * Validate comment video settings
	 *
	 * Only validates enabled flag - all other settings are inherited from upload settings.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $data Settings data to validate.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	private function validate_comment_video_settings( $data ) {
		// Validate enabled.
		if ( isset( $data['enabled'] ) && ! is_bool( $data['enabled'] ) ) {
			// Accept string values and convert.
			if ( in_array( $data['enabled'], array( 'true', 'false', '1', '0' ), true ) ) {
				$data['enabled'] = filter_var( $data['enabled'], FILTER_VALIDATE_BOOLEAN );
			} else {
				return new WP_Error(
					'invalid_enabled',
					__( 'The "enabled" field must be a boolean value.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Reset comment video settings to defaults
	 *
	 * Handles POST /stream/settings/comment-video/reset endpoint.
	 * Resets comment video settings to default values.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST API request object. Unused but required by REST API signature.
	 *
	 * @return WP_REST_Response|WP_Error Response with result or error.
	 */
	public function reset( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API signature.
		// Check permissions - must be admin.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'unauthorized',
				__( 'You do not have permission to reset comment video settings.', 'fchub-stream' ),
				array( 'status' => 403 )
			);
		}

		// Get defaults from ConfigDefaults.
		$defaults = \FCHubStream\App\Models\ConfigDefaults::get_comment_video_defaults();

		// Save defaults.
		$result = StreamConfigService::save_comment_video_settings( $defaults );

		if ( ! $result['success'] ) {
			return new WP_Error(
				'reset_failed',
				$result['message'] ?? __( 'Failed to reset comment video settings.', 'fchub-stream' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => StreamConfigService::get_comment_video_settings(),
				'message' => __( 'Comment video settings reset to defaults.', 'fchub-stream' ),
			),
			200
		);
	}
}
