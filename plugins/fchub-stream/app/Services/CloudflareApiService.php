<?php
/**
 * Cloudflare Stream API Service
 *
 * @package FCHubStream
 * @subpackage Services
 * @since 1.0.0
 */

namespace FCHubStream\App\Services;

use WP_Error;
use FCHubStream\App\Services\Http\AbstractApiClient;

/**
 * Cloudflare Stream API Service
 *
 * Handles all API communication with Cloudflare Stream including
 * connection testing, video uploads, video status checks, and direct upload URLs.
 *
 * Extends AbstractApiClient to inherit common HTTP functionality while
 * providing Cloudflare-specific API operations.
 *
 * @since 1.0.0
 */
class CloudflareApiService extends AbstractApiClient {
	/**
	 * Cloudflare API base URL
	 */
	const API_BASE_URL = 'https://api.cloudflare.com/client/v4';

	/**
	 * Account ID
	 *
	 * @var string
	 */
	private $account_id;

	/**
	 * API Token
	 *
	 * @var string
	 */
	private $api_token;

	/**
	 * Constructor
	 *
	 * @param string $account_id Cloudflare Account ID.
	 * @param string $api_token Cloudflare API Token.
	 */
	public function __construct( string $account_id, string $api_token ) {
		$this->account_id = $account_id;
		$this->api_token  = $api_token;
	}

	/**
	 * Get API base URL.
	 *
	 * Returns the Cloudflare API v4 base URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string API base URL.
	 */
	protected function get_base_url() {
		return self::API_BASE_URL;
	}

	/**
	 * Get authentication headers.
	 *
	 * Returns Cloudflare-specific authentication headers using Bearer token.
	 *
	 * @since 1.0.0
	 *
	 * @return array Authentication headers.
	 */
	protected function get_auth_headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->api_token,
		);
	}

	/**
	 * Get error code prefix.
	 *
	 * Returns Cloudflare-specific error code prefix for WP_Error codes.
	 *
	 * @since 1.0.0
	 *
	 * @return string Error code prefix.
	 */
	protected function get_error_code_prefix() {
		return 'cloudflare_api_error';
	}

	/**
	 * Test API connection
	 *
	 * Makes a simple API call to verify Cloudflare Stream credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Test result.
	 *
	 *     @type string $status  Status: 'success' or 'error'.
	 *     @type string $message Result message.
	 * }
	 */
	public function test_connection() {
		// Use list videos endpoint as a simple test.
		$response = $this->make_request( 'GET', "/accounts/{$this->account_id}/stream" );

		if ( is_wp_error( $response ) ) {
			return $this->create_test_result( false, $response->get_error_message() );
		}

		$parsed = $this->parse_response( $response );

		// Cloudflare API returns 200 with result array if successful.
		// Check for both success field (if present) and result array.
		if ( 200 === $parsed['status_code'] ) {
			// Check if response has success field (v4 API usually has it).
			if ( isset( $parsed['data']['success'] ) && true === $parsed['data']['success'] ) {
				return $this->create_test_result(
					true,
					__( 'Connection test successful.', 'fchub-stream' )
				);
			}
			// If no success field but has result array, it's also success.
			if ( isset( $parsed['data']['result'] ) && is_array( $parsed['data']['result'] ) ) {
				return $this->create_test_result(
					true,
					__( 'Connection test successful.', 'fchub-stream' )
				);
			}
		}

		// Extract error message.
		$error_message = $this->extract_error_message(
			$parsed['data'],
			__( 'Connection test failed.', 'fchub-stream' )
		);

		return $this->create_test_result( false, $error_message . ' (HTTP ' . $parsed['status_code'] . ')' );
	}

	/**
	 * List all videos from Cloudflare Stream
	 *
	 * Retrieves all videos from Cloudflare Stream with pagination support.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Optional. Query arguments.
	 *                    @type int $per_page Videos per page (max 100, default 100).
	 *                    @type int $page     Page number (default 1).
	 *
	 * @return array|WP_Error {
	 *     Video list data on success, WP_Error on failure.
	 *
	 *     @type array $videos  Array of video objects.
	 *     @type int   $total   Total number of videos.
	 *     @type int   $page    Current page number.
	 *     @type int   $per_page Videos per page.
	 *     @type int   $total_pages Total number of pages.
	 * }
	 *
	 * @throws WP_Error If API request fails.
	 */
	public function list_videos( $args = array() ) {
		$defaults = array(
			'per_page' => 100, // Max allowed by Cloudflare.
			'page'     => 1,
		);

		$args = wp_parse_args( $args, $defaults );

		// Build query string.
		$query_params = array(
			'per_page' => (int) $args['per_page'],
			'page'     => (int) $args['page'],
		);

		$endpoint = "/accounts/{$this->account_id}/stream?" . http_build_query( $query_params );

		$response = $this->make_request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] &&
			isset( $parsed['data']['success'] ) &&
			true === $parsed['data']['success'] ) {
			$result      = $parsed['data']['result'];
			$result_info = $parsed['data']['result_info'] ?? array();

			return array(
				'videos'      => is_array( $result ) ? $result : array(),
				'total'       => (int) ( $result_info['total_count'] ?? 0 ),
				'page'        => (int) ( $result_info['page'] ?? $args['page'] ),
				'per_page'    => (int) ( $result_info['per_page'] ?? $args['per_page'] ),
				'total_pages' => (int) ceil( ( $result_info['total_count'] ?? 0 ) / ( $result_info['per_page'] ?? $args['per_page'] ) ),
			);
		}

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to list videos.', 'fchub-stream' )
		);
	}

	/**
	 * List all videos (with automatic pagination)
	 *
	 * Retrieves ALL videos from Cloudflare Stream by automatically paginating through all pages.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error {
	 *     All videos on success, WP_Error on failure.
	 *
	 *     @type array $videos Array of all video objects.
	 *     @type int   $total  Total number of videos.
	 * }
	 *
	 * @throws WP_Error If API request fails.
	 */
	public function list_all_videos() {
		$all_videos = array();
		$page       = 1;
		$per_page   = 100;
		$total      = 0;

		while ( true ) {
			$result = $this->list_videos(
				array(
					'page'     => $page,
					'per_page' => $per_page,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$videos = $result['videos'] ?? array();
			$total  = $result['total'] ?? 0;

			if ( empty( $videos ) ) {
				break;
			}

			$all_videos = array_merge( $all_videos, $videos );

			// Check if we've retrieved all videos.
			if ( count( $all_videos ) >= $total ) {
				break;
			}

			++$page;
		}

		return array(
			'videos' => $all_videos,
			'total'  => $total,
		);
	}

	/**
	 * Create direct upload URL
	 *
	 * Creates a direct upload URL for uploading videos to Cloudflare Stream.
	 *
	 * @since 1.0.0
	 *
	 * @param array $options Optional. Upload options (maxDurationSeconds, etc.). Default empty array.
	 *
	 * @return array|WP_Error {
	 *     Upload URL data on success, WP_Error on failure.
	 *
	 *     @type string $uploadURL  Direct upload URL.
	 *     @type string $uid        Video UID.
	 * }
	 *
	 * @throws WP_Error If API request fails.
	 */
	public function create_direct_upload_url( $options = array() ) {
		$defaults = array(
			'maxDurationSeconds' => 3600,
		);

		$options = array_merge( $defaults, $options );

		$response = $this->make_request(
			'POST',
			"/accounts/{$this->account_id}/stream/direct_upload",
			$options
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] &&
			isset( $parsed['data']['success'] ) &&
			true === $parsed['data']['success'] ) {
			return $parsed['data']['result'];
		}

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to create upload URL.', 'fchub-stream' )
		);
	}

	/**
	 * Get video information
	 *
	 * Retrieves video information from Cloudflare Stream.
	 *
	 * @since 1.0.0
	 *
	 * @param string $video_uid Video UID.
	 *
	 * @return array|WP_Error {
	 *     Video data on success, WP_Error on failure.
	 *
	 *     @type string $uid           Video UID.
	 *     @type object $meta          Video metadata.
	 *     @type string $status        Processing status.
	 *     @type object $playback      Playback information.
	 * }
	 *
	 * @throws WP_Error If API request fails.
	 */
	public function get_video( $video_uid ) {
		$response = $this->make_request(
			'GET',
			"/accounts/{$this->account_id}/stream/{$video_uid}"
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] &&
			isset( $parsed['data']['success'] ) &&
			true === $parsed['data']['success'] ) {
			return $parsed['data']['result'];
		}

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to get video information.', 'fchub-stream' )
		);
	}

	/**
	 * Upload video file
	 *
	 * Uploads a video file directly to Cloudflare Stream using multipart/form-data.
	 * The video will be queued for processing immediately after upload.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Absolute path to video file.
	 * @param string $filename  Original filename for the video.
	 * @param array  $metadata  Optional. Video metadata (title, etc.). Default empty array.
	 *
	 * @return array|WP_Error {
	 *     Upload result on success, WP_Error on failure.
	 *
	 *     @type string $uid                Video UID.
	 *     @type array  $status             Processing status information.
	 *     @type string $thumbnail          Thumbnail URL.
	 *     @type bool   $readyToStream      Whether video is ready to stream.
	 *     @type array  $playback           Playback URLs and information.
	 * }
	 *
	 * @throws WP_Error If file doesn't exist or API request fails.
	 */
	public function upload_video( $file_path, $filename, $metadata = array() ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Video file does not exist.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		$url      = self::API_BASE_URL . "/accounts/{$this->account_id}/stream";
		$boundary = wp_generate_password( 24, false );

		// Build multipart/form-data body.
		$body = '';
		$eol  = "\r\n";

		// Add video file.
		$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $file_content ) {
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Failed to read video file.', 'fchub-stream' ),
				array( 'status' => 500 )
			);
		}

		$body .= "--{$boundary}{$eol}";
		$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"{$eol}";
		$body .= 'Content-Type: application/octet-stream' . $eol . $eol;
		$body .= $file_content . $eol;

		// Add metadata if provided.
		if ( ! empty( $metadata ) ) {
			foreach ( $metadata as $key => $value ) {
				$body .= "--{$boundary}{$eol}";
				$body .= "Content-Disposition: form-data; name=\"meta[{$key}]\"{$eol}{$eol}";
				$body .= $value . $eol;
			}
		}

		$body .= "--{$boundary}--{$eol}";

		// Make HTTP request.
		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'POST',
				'headers' => array_merge(
					$this->get_auth_headers(),
					array(
						'Content-Type' => "multipart/form-data; boundary={$boundary}",
					)
				),
				'body'    => $body,
				'timeout' => 300, // 5 minutes for large files.
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] &&
			isset( $parsed['data']['success'] ) &&
			true === $parsed['data']['success'] ) {
			return $parsed['data']['result'];
		}

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to upload video.', 'fchub-stream' )
		);
	}

	/**
	 * Set webhook notification URL
	 *
	 * Configures webhook notification URL for Cloudflare Stream.
	 * Cloudflare will return a secret that should be used to verify webhook signatures.
	 *
	 * @since 1.0.0
	 *
	 * @param string $notification_url Webhook notification URL (must include http:// or https://).
	 *
	 * @return array|WP_Error {
	 *     Webhook configuration on success, WP_Error on failure.
	 *
	 *     @type string $notificationUrl Webhook notification URL.
	 *     @type string $secret          Webhook signing secret (use this for signature verification).
	 *     @type string $modified        Last modification timestamp.
	 * }
	 *
	 * @throws WP_Error If API request fails.
	 */
	public function set_webhook( $notification_url ) {
		error_log( '[FCHub Stream] CloudflareApiService::set_webhook() - START | URL: ' . $notification_url ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Validate URL format.
		if ( ! filter_var( $notification_url, FILTER_VALIDATE_URL ) ) {
			error_log( '[FCHub Stream] CloudflareApiService::set_webhook() - Invalid URL format' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Invalid webhook URL format. Must include http:// or https://.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Cloudflare requires http:// or https:// protocol.
		$parsed_url = wp_parse_url( $notification_url );
		if ( ! isset( $parsed_url['scheme'] ) || ! in_array( $parsed_url['scheme'], array( 'http', 'https' ), true ) ) {
			error_log( '[FCHub Stream] CloudflareApiService::set_webhook() - Invalid protocol' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Webhook URL must use http:// or https:// protocol.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		$request_data = array(
			'notificationUrl' => $notification_url,
		);

		error_log( '[FCHub Stream] CloudflareApiService::set_webhook() - Making PUT request to: /accounts/' . $this->account_id . '/stream/webhook' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[FCHub Stream] CloudflareApiService::set_webhook() - Request data: ' . print_r( $request_data, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r

		$response = $this->make_request(
			'PUT',
			"/accounts/{$this->account_id}/stream/webhook",
			$request_data
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[FCHub Stream] CloudflareApiService::set_webhook() - Request failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $response;
		}

		error_log( '[FCHub Stream] CloudflareApiService::set_webhook() - Response received' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		$parsed = $this->parse_response( $response );

		error_log( '[FCHub Stream] CloudflareApiService::set_webhook() - Parsed response - Status: ' . $parsed['status_code'] . ' | Has success: ' . ( isset( $parsed['data']['success'] ) ? 'yes' : 'no' ) . ' | Has result: ' . ( isset( $parsed['data']['result'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( 200 === $parsed['status_code'] &&
			isset( $parsed['data']['success'] ) &&
			true === $parsed['data']['success'] &&
			isset( $parsed['data']['result'] ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[FCHub Stream] CloudflareApiService::set_webhook() - SUCCESS | Result: ' . print_r( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
					array(
						'has_secret'          => isset( $parsed['data']['result']['secret'] ),
						'has_notificationUrl' => isset( $parsed['data']['result']['notificationUrl'] ),
					),
					true
				)
			);
			return $parsed['data']['result'];
		}

		error_log( '[FCHub Stream] CloudflareApiService::set_webhook() - FAILED | Full response: ' . print_r( $parsed, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to configure webhook.', 'fchub-stream' )
		);
	}

	/**
	 * Get webhook configuration
	 *
	 * Retrieves current webhook configuration from Cloudflare Stream.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error {
	 *     Webhook configuration on success, WP_Error on failure.
	 *
	 *     @type string $notificationUrl Webhook notification URL.
	 *     @type string $secret          Webhook signing secret.
	 *     @type string $modified        Last modification timestamp.
	 * }
	 *
	 * @throws WP_Error If API request fails.
	 */
	public function get_webhook() {
		$response = $this->make_request(
			'GET',
			"/accounts/{$this->account_id}/stream/webhook"
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] &&
			isset( $parsed['data']['success'] ) &&
			true === $parsed['data']['success'] &&
			isset( $parsed['data']['result'] ) ) {
			return $parsed['data']['result'];
		}

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to get webhook configuration.', 'fchub-stream' )
		);
	}

	/**
	 * Update video settings
	 *
	 * Updates video settings such as allowedOrigins, requireSignedURLs, etc.
	 *
	 * @since 1.0.0
	 *
	 * @param string $video_uid Video UID to update.
	 * @param array  $options   Video options to update.
	 *                         @type array  $allowedOrigins     Array of allowed origin domains.
	 *                         @type bool   $requireSignedURLs  Whether to require signed URLs.
	 *                         @type string $creator           Creator ID.
	 *                         @type array  $meta              Video metadata.
	 *
	 * @return array|WP_Error Updated video data on success, WP_Error on failure.
	 *
	 * @throws WP_Error If video_uid is empty or API request fails.
	 */
	public function update_video( $video_uid, $options = array() ) {
		if ( empty( $video_uid ) ) {
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Video UID is required.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		$response = $this->make_request(
			'POST',
			"/accounts/{$this->account_id}/stream/{$video_uid}",
			$options
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_response( $response );

		if ( 200 === $parsed['status_code'] &&
			isset( $parsed['data']['success'] ) &&
			true === $parsed['data']['success'] ) {
			return $parsed['data']['result'];
		}

		return $this->create_error(
			$parsed['data'],
			$parsed['status_code'],
			__( 'Failed to update video.', 'fchub-stream' )
		);
	}

	/**
	 * Delete video from Cloudflare Stream
	 *
	 * Deletes a video from Cloudflare Stream by its video UID.
	 * Used for cleanup when posts/comments are deleted.
	 *
	 * @since 1.0.0
	 *
	 * @param string $video_uid Video UID to delete.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 *
	 * @throws WP_Error If video_uid is empty or API request fails.
	 */
	public function delete_video( $video_uid ) {
		error_log( '[FCHub Stream] CloudflareApiService::delete_video() - START | Video UID: ' . $video_uid . ' | Account ID: ' . $this->account_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Validate video_uid.
		if ( empty( $video_uid ) ) {
			error_log( '[FCHub Stream] CloudflareApiService::delete_video() - VALIDATION FAILED | Video UID is empty' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				$this->get_error_code_prefix(),
				__( 'Video UID is required.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		$endpoint = "/accounts/{$this->account_id}/stream/{$video_uid}";
		error_log( '[FCHub Stream] CloudflareApiService::delete_video() - Making DELETE request | Endpoint: ' . $endpoint ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Make DELETE request.
		$response = $this->make_request(
			'DELETE',
			$endpoint
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[FCHub Stream] CloudflareApiService::delete_video() - REQUEST ERROR | ' . $response->get_error_message() . ' | Code: ' . $response->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $response;
		}

		$parsed      = $this->parse_response( $response );
		$status_code = $parsed['status_code'];

		error_log( '[FCHub Stream] CloudflareApiService::delete_video() - Response received | Status Code: ' . $status_code . ' | Has data: ' . ( isset( $parsed['data'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Handle success codes (200, 204).
		if ( 200 === $status_code || 204 === $status_code ) {
			error_log( '[FCHub Stream] CloudflareApiService::delete_video() - SUCCESS | Video UID: ' . $video_uid . ' | Status: ' . $status_code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return true;
		}

		// Handle 404 - video not found (may already be deleted).
		if ( 404 === $status_code ) {
			// Log warning but return true (don't treat as error).
			error_log( '[FCHub Stream] CloudflareApiService::delete_video() - Video not found (may already be deleted) | Video UID: ' . $video_uid . ' | HTTP 404 - Returning true (not an error)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return true;
		}

		// Handle other errors.
		$error_message = isset( $parsed['data'] ) ? print_r( $parsed['data'], true ) : 'No error data'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		error_log( '[FCHub Stream] CloudflareApiService::delete_video() - ERROR | Video UID: ' . $video_uid . ' | Status: ' . $status_code . ' | Error data: ' . $error_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return $this->create_error(
			$parsed['data'],
			$status_code,
			__( 'Failed to delete video.', 'fchub-stream' )
		);
	}
}
