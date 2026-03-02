<?php
/**
 * Bunny.net Stream API Service
 *
 * @package FCHubStream
 * @subpackage Services
 * @since 1.0.0
 */

namespace FCHubStream\App\Services;

use WP_Error;
use FCHubStream\App\Services\Http\AbstractApiClient;

/**
 * Bunny.net Stream API Service
 *
 * Handles all API communication with Bunny.net Stream including
 * video library management, collections, connection testing, and video operations.
 *
 * Extends AbstractApiClient to inherit common HTTP functionality while
 * providing Bunny.net-specific API operations.
 *
 * @link https://docs.bunny.net/reference/stream-api-overview Bunny.net Stream API Documentation
 *
 * @since 1.0.0
 */
class BunnyApiService extends AbstractApiClient {
	/**
	 * Bunny.net Main API base URL
	 */
	const MAIN_API_BASE_URL = 'https://api.bunny.net';

	/**
	 * Bunny.net Stream API base URL
	 */
	const STREAM_API_BASE_URL = 'https://video.bunnycdn.com';

	/**
	 * Account API Key (for managing Video Libraries)
	 *
	 * @var string
	 */
	private $account_api_key;

	/**
	 * Stream API Key (for Video Library operations)
	 *
	 * @var string
	 */
	private $stream_api_key;

	/**
	 * Video Library ID
	 *
	 * @var int
	 */
	private $library_id;

	/**
	 * Constructor
	 *
	 * @param string      $account_api_key Account API Key (for listing libraries).
	 * @param string|null $stream_api_key Stream API Key (for library operations).
	 * @param int|null    $library_id Video Library ID.
	 */
	public function __construct( string $account_api_key, ?string $stream_api_key = null, ?int $library_id = null ) {
		$this->account_api_key = $account_api_key;
		$this->stream_api_key  = $stream_api_key;
		$this->library_id      = $library_id;
	}

	/**
	 * Get API base URL.
	 *
	 * Returns the main Bunny.net API base URL by default.
	 * Individual methods can override this by passing $base_url to make_request().
	 *
	 * @since 1.0.0
	 *
	 * @return string API base URL.
	 */
	protected function get_base_url() {
		return self::MAIN_API_BASE_URL;
	}

	/**
	 * Get authentication headers.
	 *
	 * Returns Bunny.net-specific authentication headers using AccessKey.
	 * Uses account_api_key by default.
	 *
	 * @since 1.0.0
	 *
	 * @return array Authentication headers.
	 */
	protected function get_auth_headers() {
		return array(
			'AccessKey' => $this->account_api_key,
		);
	}

	/**
	 * Get error code prefix.
	 *
	 * Returns Bunny.net-specific error code prefix for WP_Error codes.
	 *
	 * @since 1.0.0
	 *
	 * @return string Error code prefix.
	 */
	protected function get_error_code_prefix() {
		return 'bunny_api_error';
	}

	/**
	 * Make request with custom API key.
	 *
	 * Override for make_request() that allows specifying a different API key.
	 * Used when Stream API Key is needed instead of Account API Key.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string $method   HTTP method (GET, POST, PUT, PATCH, DELETE).
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Optional. Request body for POST/PUT. Default empty array.
	 * @param string $api_key  API Key to use.
	 * @param string $base_url Base URL.
	 *
	 * @return array|WP_Error Response array on success, WP_Error on failure.
	 */
	private function make_request_with_key( $method, $endpoint, $body, $api_key, $base_url ) {
		// Temporarily store current account key.
		$original_key          = $this->account_api_key;
		$this->account_api_key = $api_key;

		// Make request with custom key.
		$response = $this->make_request( $method, $endpoint, $body, $base_url );

		// Restore original key.
		$this->account_api_key = $original_key;

		return $response;
	}

	/**
	 * List Video Libraries
	 *
	 * Lists all video libraries for the Bunny.net account.
	 * Requires Account API Key.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error {
	 *     List of video libraries on success, WP_Error on failure.
	 *
	 *     @type bool  $success   Success status.
	 *     @type array $libraries Array of library objects.
	 * }
	 *
	 * @throws WP_Error If API request fails.
	 */
	public function list_video_libraries() {
		$response = $this->make_request( 'GET', '/stream/videolibrary' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] && ! empty( $parsed['data'] ) ) {
			return array(
				'success'   => true,
				'libraries' => $parsed['data'],
			);
		}

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to list video libraries.', 'fchub-stream' )
		);
	}

	/**
	 * Get Video Library details
	 *
	 * Retrieves details for a specific video library.
	 * Requires Account API Key.
	 *
	 * @since 1.0.0
	 *
	 * @param int $library_id Video Library ID.
	 *
	 * @return array|WP_Error {
	 *     Library details on success, WP_Error on failure.
	 *
	 *     @type bool  $success Success status.
	 *     @type array $library Library object with details.
	 * }
	 *
	 * @throws WP_Error If API request fails.
	 */
	public function get_video_library( $library_id ) {
		$response = $this->make_request( 'GET', "/stream/videolibrary/{$library_id}" );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] && ! empty( $parsed['data'] ) ) {
			return array(
				'success' => true,
				'library' => $parsed['data'],
			);
		}

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to get video library details.', 'fchub-stream' )
		);
	}

	/**
	 * List Collections for a Video Library
	 *
	 * Lists all collections within a specific video library.
	 * Requires Stream API Key (from Video Library).
	 *
	 * @since 1.0.0
	 *
	 * @param int         $library_id      Video Library ID.
	 * @param string|null $stream_api_key Optional. Stream API Key. Uses constructor value if null. Default null.
	 *
	 * @return array|WP_Error {
	 *     List of collections on success, WP_Error on failure.
	 *
	 *     @type bool  $success     Success status.
	 *     @type array $collections Array of collection objects.
	 * }
	 *
	 * @throws WP_Error If API request fails or API key is missing.
	 */
	public function list_collections( $library_id, $stream_api_key = null ) {
		$api_key = $stream_api_key ?? $this->stream_api_key;

		if ( empty( $api_key ) ) {
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Stream API Key is required to list collections.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		$response = $this->make_request_with_key(
			'GET',
			"/library/{$library_id}/collections",
			array(),
			$api_key,
			self::STREAM_API_BASE_URL
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] && ! empty( $parsed['data'] ) ) {
			return array(
				'success'     => true,
				'collections' => $parsed['data'],
			);
		}

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to list collections.', 'fchub-stream' )
		);
	}

	/**
	 * Test API connection
	 *
	 * Tests connection to Bunny.net Stream API using Stream API Key.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null    $library_id      Optional. Video Library ID. Uses constructor value if null. Default null.
	 * @param string|null $stream_api_key Optional. Stream API Key. Uses constructor value if null. Default null.
	 *
	 * @return array {
	 *     Test result.
	 *
	 *     @type string $status  Status: 'success' or 'error'.
	 *     @type string $message Result message.
	 * }
	 */
	public function test_connection( $library_id = null, $stream_api_key = null ) {
		$lib_id  = $library_id ?? $this->library_id;
		$api_key = $stream_api_key ?? $this->stream_api_key;

		if ( empty( $lib_id ) || empty( $api_key ) ) {
			return $this->create_test_result(
				false,
				__( 'Library ID and Stream API Key are required for connection test.', 'fchub-stream' )
			);
		}

		// Test by getting library details.
		$response = $this->make_request_with_key(
			'GET',
			"/library/{$lib_id}",
			array(),
			$api_key,
			self::STREAM_API_BASE_URL
		);

		if ( is_wp_error( $response ) ) {
			return $this->create_test_result( false, $response->get_error_message() );
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] && ! empty( $parsed['data'] ) ) {
			return $this->create_test_result(
				true,
				__( 'Connection test successful.', 'fchub-stream' )
			);
		}

		$error_message = $this->extract_error_message(
			$parsed['data'],
			__( 'Connection test failed.', 'fchub-stream' )
		);

		return $this->create_test_result( false, $error_message . ' (HTTP ' . $parsed['status_code'] . ')' );
	}

	/**
	 * Upload video file
	 *
	 * Uploads a video file to Bunny.net Stream using a two-step process:
	 * 1. Creates video entry via POST (with metadata)
	 * 2. Uploads video file via PUT (binary body)
	 *
	 * Requires Stream API Key and Library ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $file_path      Absolute path to video file.
	 * @param string      $filename       Original filename for the video.
	 * @param array       $metadata       Optional. Video metadata (title, collectionId). Default empty array.
	 * @param int|null    $library_id     Optional. Library ID. Uses constructor value if null. Default null.
	 * @param string|null $stream_api_key Optional. Stream API Key. Uses constructor value if null. Default null.
	 *
	 * @return array|WP_Error {
	 *     Upload result on success, WP_Error on failure.
	 *
	 *     @type string $guid                Video GUID.
	 *     @type int    $status              Processing status (0=pending, 4=encoding, 5=ready).
	 *     @type string $title               Video title.
	 *     @type int    $thumbnailCount      Number of thumbnails generated.
	 * }
	 *
	 * @throws WP_Error If file doesn't exist, API key missing, or API request fails.
	 */
	public function upload_video( $file_path, $filename, $metadata = array(), $library_id = null, $stream_api_key = null ) {
		$lib_id  = $library_id ?? $this->library_id;
		$api_key = $stream_api_key ?? $this->stream_api_key;

		if ( empty( $lib_id ) || empty( $api_key ) ) {
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Library ID and Stream API Key are required for video upload.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Video file does not exist.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Step 1: Create video entry (POST with JSON metadata).
		$create_url  = self::STREAM_API_BASE_URL . "/library/{$lib_id}/videos";
		$create_body = array();

		// Extract title from metadata if provided.
		if ( ! empty( $metadata['title'] ) ) {
			$create_body['title'] = $metadata['title'];
		} else {
			// Use filename without extension as title.
			$create_body['title'] = pathinfo( $filename, PATHINFO_FILENAME );
		}

		// Add collectionId if provided.
		if ( ! empty( $metadata['collectionId'] ) ) {
			$create_body['collectionId'] = $metadata['collectionId'];
		}

		$create_response = wp_remote_request(
			$create_url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'AccessKey'    => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $create_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $create_response ) ) {
			return $create_response;
		}

		$create_parsed = $this->parse_response( $create_response );

		if ( 200 !== $create_parsed['status_code'] || empty( $create_parsed['data'] ) ) {
			return $this->create_error(
				$create_parsed['data'],
				$create_parsed['status_code'],
				__( 'Failed to create video entry.', 'fchub-stream' )
			);
		}

		// Extract video GUID from response.
		$video_guid = isset( $create_parsed['data']['guid'] ) ? $create_parsed['data']['guid'] : null;

		if ( empty( $video_guid ) ) {
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Failed to get video GUID from create response.', 'fchub-stream' ),
				array( 'status' => 500 )
			);
		}

		// Step 2: Upload video file (PUT with binary body).
		$upload_url = self::STREAM_API_BASE_URL . "/library/{$lib_id}/videos/{$video_guid}";

		$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $file_content ) {
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Failed to read video file.', 'fchub-stream' ),
				array( 'status' => 500 )
			);
		}

		// Determine MIME type from file extension.
		$mime_type        = 'application/octet-stream';
		$extension        = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$video_mime_types = array(
			'mp4'  => 'video/mp4',
			'mov'  => 'video/quicktime',
			'avi'  => 'video/x-msvideo',
			'webm' => 'video/webm',
			'mkv'  => 'video/x-matroska',
		);

		if ( isset( $video_mime_types[ $extension ] ) ) {
			$mime_type = $video_mime_types[ $extension ];
		}

		$upload_response = wp_remote_request(
			$upload_url,
			array(
				'method'  => 'PUT',
				'headers' => array(
					'AccessKey'    => $api_key,
					'Content-Type' => $mime_type,
				),
				'body'    => $file_content,
				'timeout' => 300, // 5 minutes for large files.
			)
		);

		if ( is_wp_error( $upload_response ) ) {
			return $upload_response;
		}

		$upload_parsed = $this->parse_response( $upload_response );

		if ( 200 !== $upload_parsed['status_code'] && 201 !== $upload_parsed['status_code'] ) {
			return $this->create_error(
				$upload_parsed['data'],
				$upload_parsed['status_code'],
				__( 'Failed to upload video file.', 'fchub-stream' )
			);
		}

		// Return combined result from create response (includes GUID, status, etc.).
		return $create_parsed['data'];
	}

	/**
	 * Delete video from Bunny.net Stream
	 *
	 * Deletes a video from Bunny.net Stream by its video GUID.
	 * Used for cleanup when posts/comments are deleted.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $video_guid     Video GUID to delete.
	 * @param int|null    $library_id     Optional. Library ID. Uses constructor value if null. Default null.
	 * @param string|null $stream_api_key Optional. Stream API Key. Uses constructor value if null. Default null.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 *
	 * @throws WP_Error If video_guid is empty, credentials are missing, or API request fails.
	 */
	public function delete_video( $video_guid, $library_id = null, $stream_api_key = null ) {
		$lib_id  = $library_id ?? $this->library_id;
		$api_key = $stream_api_key ?? $this->stream_api_key;

		error_log( '[FCHub Stream] BunnyApiService::delete_video() - START | Video GUID: ' . $video_guid . ' | Library ID: ' . $lib_id . ' | Has API Key: ' . ( ! empty( $api_key ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Validate required parameters.
		if ( empty( $video_guid ) ) {
			error_log( '[FCHub Stream] BunnyApiService::delete_video() - VALIDATION FAILED | Video GUID is empty' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Video GUID is required.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $lib_id ) || empty( $api_key ) ) {
			error_log( '[FCHub Stream] BunnyApiService::delete_video() - VALIDATION FAILED | Library ID: ' . ( empty( $lib_id ) ? 'empty' : $lib_id ) . ' | API Key: ' . ( empty( $api_key ) ? 'empty' : 'provided' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Library ID and Stream API Key are required.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		$endpoint = "/library/{$lib_id}/videos/{$video_guid}";
		$base_url = self::STREAM_API_BASE_URL;
		error_log( '[FCHub Stream] BunnyApiService::delete_video() - Making DELETE request | Endpoint: ' . $endpoint . ' | Base URL: ' . $base_url ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Make DELETE request using make_request_with_key() helper.
		// DELETE /library/{library_id}/videos/{video_guid}.
		$response = $this->make_request_with_key(
			'DELETE',
			$endpoint,
			array(), // No body for DELETE.
			$api_key,
			$base_url
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[FCHub Stream] BunnyApiService::delete_video() - REQUEST ERROR | ' . $response->get_error_message() . ' | Code: ' . $response->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $response;
		}

		$parsed      = $this->parse_response( $response );
		$status_code = $parsed['status_code'];

		error_log( '[FCHub Stream] BunnyApiService::delete_video() - Response received | Status Code: ' . $status_code . ' | Has data: ' . ( isset( $parsed['data'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Handle success codes (200, 204).
		if ( 200 === $status_code || 204 === $status_code ) {
			error_log( '[FCHub Stream] BunnyApiService::delete_video() - SUCCESS | Video GUID: ' . $video_guid . ' | Status: ' . $status_code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return true;
		}

		// Handle 404 - video not found (may already be deleted).
		if ( 404 === $status_code ) {
			// Log warning but return true (don't treat as error).
			error_log( '[FCHub Stream] BunnyApiService::delete_video() - Video not found (may already be deleted) | Video GUID: ' . $video_guid . ' | HTTP 404 - Returning true (not an error)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return true;
		}

		// Handle other errors.
		$error_message = isset( $parsed['data'] ) ? print_r( $parsed['data'], true ) : 'No error data'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		error_log( '[FCHub Stream] BunnyApiService::delete_video() - ERROR | Video GUID: ' . $video_guid . ' | Status: ' . $status_code . ' | Error data: ' . $error_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return $this->create_error(
			$parsed['data'],
			$status_code,
			__( 'Failed to delete video.', 'fchub-stream' )
		);
	}
}
