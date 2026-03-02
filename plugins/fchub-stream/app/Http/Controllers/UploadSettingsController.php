<?php
/**
 * Upload Settings Controller
 *
 * Handles REST API endpoints for managing video upload settings including
 * maximum file size, allowed formats, and maximum duration limits.
 *
 * @package FCHubStream
 * @subpackage Http\Controllers
 * @since 1.0.0
 */

namespace FCHubStream\App\Http\Controllers;

use FCHubStream\App\Http\Controllers\Traits\ParsesJsonRequest;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Upload Settings Controller class.
 *
 * Manages upload configuration settings through REST API endpoints.
 * Provides CRUD operations for upload limits and validation rules.
 *
 * @since 1.0.0
 */
class UploadSettingsController {

	use ParsesJsonRequest;

	/**
	 * Get upload settings
	 *
	 * Handles GET /stream/settings/upload endpoint.
	 * Returns current upload settings configuration.
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
				__( 'You do not have permission to access upload settings.', 'fchub-stream' ),
				array( 'status' => 403 )
			);
		}

		$settings = $this->get_upload_settings();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $settings,
			),
			200
		);
	}

	/**
	 * Save upload settings
	 *
	 * Handles POST /stream/settings/upload endpoint.
	 * Updates upload settings configuration.
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
				__( 'You do not have permission to modify upload settings.', 'fchub-stream' ),
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
		$validation = $this->validate_upload_settings( $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Save settings.
		$result = $this->save_upload_settings( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $this->get_upload_settings(),
				'message' => __( 'Upload settings saved successfully.', 'fchub-stream' ),
			),
			200
		);
	}

	/**
	 * Get upload settings from database
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @return array Upload settings.
	 */
	private function get_upload_settings() {
		$defaults = array(
			'max_file_size_mb'          => 500,
			'allowed_formats'           => array( 'mp4', 'mov', 'webm', 'avi' ),
			'max_duration_seconds'      => 0, // 0 = unlimited.
			'auto_publish'              => true,
			'polling_interval'          => 30,
			'enable_upload_from_portal' => true,
		);

		$settings = get_option( 'fchub_stream_upload_settings', array() );

		// Merge with defaults and ensure all keys exist.
		$settings = wp_parse_args( $settings, $defaults );

		// Support backward compatibility - if max_file_size exists, use it.
		if ( isset( $settings['max_file_size'] ) && ! isset( $settings['max_file_size_mb'] ) ) {
			$settings['max_file_size_mb'] = $settings['max_file_size'];
			unset( $settings['max_file_size'] );
		}

		return $settings;
	}

	/**
	 * Validate upload settings
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $data Settings data to validate.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	private function validate_upload_settings( $data ) {
		// Validate max_file_size_mb.
		if ( isset( $data['max_file_size_mb'] ) ) {
			$max_file_size = (int) $data['max_file_size_mb'];
			if ( $max_file_size < 1 || $max_file_size > 10000 ) {
				return new WP_Error(
					'invalid_max_file_size',
					__( 'Maximum file size must be between 1MB and 10000MB.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}
		}

		// Validate allowed_formats.
		if ( isset( $data['allowed_formats'] ) ) {
			if ( ! is_array( $data['allowed_formats'] ) || empty( $data['allowed_formats'] ) ) {
				return new WP_Error(
					'invalid_allowed_formats',
					__( 'Allowed formats must be a non-empty array.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}

			$valid_formats = array( 'mp4', 'mov', 'webm', 'avi', 'mkv', 'flv', 'm4v' );
			foreach ( $data['allowed_formats'] as $format ) {
				if ( ! in_array( $format, $valid_formats, true ) ) {
					return new WP_Error(
						'invalid_format',
						sprintf(
							/* translators: %s: Invalid format, %s: Valid formats */
							__( 'Invalid format "%1$s". Valid formats: %2$s', 'fchub-stream' ),
							$format,
							implode( ', ', $valid_formats )
						),
						array( 'status' => 400 )
					);
				}
			}
		}

		// Validate max_duration_seconds.
		if ( isset( $data['max_duration_seconds'] ) ) {
			$max_duration = (int) $data['max_duration_seconds'];
			if ( $max_duration < 0 || $max_duration > 21600 ) { // Max 6 hours.
				return new WP_Error(
					'invalid_max_duration',
					__( 'Maximum duration must be between 0 (unlimited) and 21600 seconds (6 hours).', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}
		}

		// Validate polling_interval.
		if ( isset( $data['polling_interval'] ) ) {
			$polling_interval = (int) $data['polling_interval'];
			if ( $polling_interval < 10 || $polling_interval > 300 ) {
				return new WP_Error(
					'invalid_polling_interval',
					__( 'Polling interval must be between 10 and 300 seconds.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Save upload settings to database
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $data Settings data to save.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function save_upload_settings( $data ) {
		$current_settings = $this->get_upload_settings();

		// Merge with current settings.
		$new_settings = array_merge(
			$current_settings,
			array_filter(
				$data,
				function ( $value ) {
					return null !== $value;
				}
			)
		);

		// Sanitize values.
		if ( isset( $new_settings['max_file_size_mb'] ) ) {
			$new_settings['max_file_size_mb'] = (int) $new_settings['max_file_size_mb'];
			// Remove old max_file_size if exists.
			unset( $new_settings['max_file_size'] );
		}

		if ( isset( $new_settings['max_duration_seconds'] ) ) {
			$new_settings['max_duration_seconds'] = (int) $new_settings['max_duration_seconds'];
		}

		if ( isset( $new_settings['polling_interval'] ) ) {
			$new_settings['polling_interval'] = (int) $new_settings['polling_interval'];
		}

		if ( isset( $new_settings['auto_publish'] ) ) {
			$new_settings['auto_publish'] = (bool) $new_settings['auto_publish'];
		}

		if ( isset( $new_settings['enable_upload_from_portal'] ) ) {
			$new_settings['enable_upload_from_portal'] = (bool) $new_settings['enable_upload_from_portal'];
		}

		if ( isset( $new_settings['allowed_formats'] ) && is_array( $new_settings['allowed_formats'] ) ) {
			$new_settings['allowed_formats'] = array_map( 'sanitize_text_field', $new_settings['allowed_formats'] );
		}

		// Save to database.
		// Note: update_option returns false if value hasn't changed, but that's not an error.
		$result = update_option( 'fchub_stream_upload_settings', $new_settings );

		// Verify the save actually worked by checking if the value was saved.
		$saved_settings = get_option( 'fchub_stream_upload_settings', array() );
		if ( empty( $saved_settings ) && ! empty( $new_settings ) ) {
			return new WP_Error(
				'save_failed',
				__( 'Failed to save upload settings.', 'fchub-stream' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Reset upload settings to defaults
	 *
	 * Handles POST /stream/settings/upload/reset endpoint.
	 * Resets upload settings to default values.
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
				__( 'You do not have permission to reset upload settings.', 'fchub-stream' ),
				array( 'status' => 403 )
			);
		}

		// Delete option to reset to defaults.
		delete_option( 'fchub_stream_upload_settings' );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $this->get_upload_settings(),
				'message' => __( 'Upload settings reset to defaults.', 'fchub-stream' ),
			),
			200
		);
	}
}
