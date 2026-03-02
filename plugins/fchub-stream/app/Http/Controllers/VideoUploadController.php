<?php
/**
 * Video Upload Controller
 *
 * Handles REST API endpoints for video upload, status checking, and webhook processing.
 * Provides interface for FluentCommunity Portal to upload videos directly.
 *
 * @package FCHubStream
 * @subpackage Http\Controllers
 * @since 1.0.0
 */

namespace FCHubStream\App\Http\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FCHubStream\App\Services\VideoUploadService;
use function FCHubStream\App\Utils\log_debug;
use function FCHubStream\App\Utils\log_error;

/**
 * Video Upload Controller class.
 *
 * Manages video upload REST API endpoints including upload, status checking,
 * and webhook handling for Cloudflare Stream and Bunny.net Stream.
 *
 * @since 1.0.0
 */
class VideoUploadController {

	/**
	 * Upload video file
	 *
	 * Handles POST /stream/video-upload endpoint.
	 * Accepts multipart/form-data with video file and uploads to configured provider.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST API request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with upload result or error.
	 */
	public function upload( WP_REST_Request $request ) {
		log_debug( 'VideoUploadController::upload() - START' );
		log_debug( 'Request method: ' . $request->get_method() );
		log_debug( 'Request route: ' . $request->get_route() );
		log_debug( 'User logged in: ' . ( is_user_logged_in() ? 'YES' : 'NO' ) );

		// Check permissions - must be logged in user.
		// PortalPolicy may not work correctly with FluentCommunity router, so check directly.
		if ( ! is_user_logged_in() ) {
			log_debug( 'Upload rejected: User not logged in' );
			return new WP_Error(
				'unauthorized',
				__( 'You must be logged in to upload videos.', 'fchub-stream' ),
				array( 'status' => 401 )
			);
		}

		// Verify nonce for CSRF protection.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		log_debug( 'Nonce present: ' . ( $nonce ? 'YES' : 'NO' ) );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			log_debug( 'Upload rejected: Invalid nonce' );
			return new WP_Error(
				'invalid_nonce',
				__( 'Invalid security token. Please refresh the page and try again.', 'fchub-stream' ),
				array( 'status' => 403 )
			);
		}

		log_debug( 'Security checks passed' );

		// Get uploaded file.
		// WordPress REST API handles file uploads differently - check $_FILES directly.
		$files = $request->get_file_params();

		// Fallback to $_FILES if get_file_params() doesn't work.
		if ( empty( $files ) || ! isset( $files['file'] ) ) {
			$files = $_FILES;
		}

		$file = $files['file'] ?? null;

		// Log for debugging.
		log_debug( 'Upload request received. Files keys: ' . wp_json_encode( array_keys( $files ) ) );
		if ( $file ) {
			log_debug(
				'File data: ' . wp_json_encode(
					array(
						'name'     => $file['name'] ?? 'not set',
						'type'     => $file['type'] ?? 'not set',
						'size'     => $file['size'] ?? 'not set',
						'tmp_name' => isset( $file['tmp_name'] ) ? $file['tmp_name'] : 'not set',
						'error'    => $file['error'] ?? 'not set',
					)
				)
			);
		}

		if ( ! $file || ! isset( $file['tmp_name'] ) ) {
			log_debug( 'No file found in request' );
			return new WP_Error(
				'no_file',
				__( 'No file uploaded.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// WordPress REST API doesn't use is_uploaded_file() validation.
		// Instead, check if file exists and has valid error code.
		if ( ! file_exists( $file['tmp_name'] ) ) {
			log_error( 'File temp path does not exist: ' . $file['tmp_name'] );
			return new WP_Error(
				'file_not_found',
				__( 'Uploaded file not found.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Check for upload errors.
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			$error_code = $file['error'] ?? 'unknown';
			log_error( 'Upload error code: ' . $error_code );
			return new WP_Error(
				'upload_error',
				$this->get_upload_error_message( $file['error'] ?? UPLOAD_ERR_NO_FILE ),
				array( 'status' => 400 )
			);
		}

		log_debug( 'File passed validation, proceeding to upload service' );

		// Get context from request (post or comment).
		$context = $request->get_param( 'context' );
		$context = sanitize_text_field( $context );

		// Default to 'post' if not specified.
		if ( empty( $context ) || ! in_array( $context, array( 'post', 'comment' ), true ) ) {
			$context = 'post';
		}

		log_debug( 'Upload context: ' . $context );

		// Check if video in comments is enabled (if context is comment).
		if ( 'comment' === $context ) {
			if ( ! \FCHubStream\App\Services\StreamConfigService::is_comment_video_enabled() ) {
				log_debug( 'Upload rejected: Video in comments is disabled' );
				return new WP_Error(
					'comment_video_disabled',
					__( 'Video uploads in comments are currently disabled.', 'fchub-stream' ),
					array( 'status' => 403 )
				);
			}

			log_debug( 'Comment video enabled check passed' );
		}

		// Get upload settings (used for both posts and comments).
		$upload_settings = get_option(
			'fchub_stream_upload_settings',
			array(
				'max_file_size_mb' => 500,
				'allowed_formats'  => array( 'mp4', 'mov', 'webm', 'avi' ),
			)
		);

		// Validate file size.
		$max_size_mb  = $upload_settings['max_file_size_mb'] ?? 500;
		$file_size_mb = round( $file['size'] / ( 1024 * 1024 ), 2 );

		if ( $file_size_mb > $max_size_mb ) {
			log_debug( 'Upload rejected: File too large (' . $file_size_mb . 'MB > ' . $max_size_mb . 'MB)' );
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %1$s: File size in MB, %2$s: Maximum allowed size in MB */
					__( 'File size (%1$sMB) exceeds maximum allowed size (%2$sMB).', 'fchub-stream' ),
					$file_size_mb,
					$max_size_mb
				),
				array( 'status' => 413 )
			);
		}

		// Validate file format.
		$allowed_formats = $upload_settings['allowed_formats'] ?? array( 'mp4', 'mov', 'webm', 'avi' );
		$file_extension  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_extension, $allowed_formats, true ) ) {
			log_debug( 'Upload rejected: Invalid format (' . $file_extension . ')' );
			return new WP_Error(
				'invalid_format',
				sprintf(
					/* translators: %1$s: File extension, %2$s: Allowed formats */
					__( 'File format "%1$s" is not allowed. Allowed formats: %2$s', 'fchub-stream' ),
					$file_extension,
					implode( ', ', $allowed_formats )
				),
				array( 'status' => 400 )
			);
		}

		// Get metadata from request.
		$metadata = array();
		$title    = $request->get_param( 'title' );
		if ( ! empty( $title ) ) {
			$metadata['title'] = sanitize_text_field( $title );
		}

		// Add context to metadata for tracking.
		$metadata['context'] = $context;

		// Upload video.
		$result = VideoUploadService::upload(
			$file['tmp_name'],
			sanitize_file_name( $file['name'] ),
			$metadata
		);

		// Clean up temporary file.
		if ( file_exists( $file['tmp_name'] ) ) {
			wp_delete_file( $file['tmp_name'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
				'message' => __( 'Video uploaded successfully.', 'fchub-stream' ),
			),
			200
		);
	}

	/**
	 * Check video status
	 *
	 * Handles GET /stream/video-status/{video_id} endpoint.
	 * Checks encoding status of uploaded video.
	 *
	 * IMPORTANT: Checks database FIRST (updated by webhook), then falls back to API.
	 * This ensures consistent status across all users without API rate limits.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST API request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with status or error.
	 */
	public function check_status( WP_REST_Request $request ) {
		$video_id = $request->get_param( 'video_id' );
		$provider = $request->get_param( 'provider' );

		// Check permissions - must be logged in user.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'unauthorized',
				__( 'You must be logged in to check video status.', 'fchub-stream' ),
				array( 'status' => 401 )
			);
		}

		// Verify nonce for CSRF protection.
		// For status checks during long polling, nonce may expire.
		// If nonce is invalid but user is logged in, log warning but allow request.
		// This prevents polling from breaking during long encoding times.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			// Nonce invalid but user is logged in - log warning but allow request.
			// This handles cases where nonce expires during long polling (15+ minutes).
			log_debug( 'Status check: Invalid nonce but user is logged in (ID: ' . get_current_user_id() . '). Allowing request due to long polling.' );
		} elseif ( ! $nonce ) {
			// No nonce provided - log warning but allow if user is logged in.
			// Some frontend implementations may not send nonce for status checks.
			log_debug( 'Status check: No nonce provided but user is logged in (ID: ' . get_current_user_id() . '). Allowing request.' );
		}

		if ( empty( $video_id ) ) {
			return new WP_Error(
				'missing_video_id',
				__( 'Video ID is required.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $provider ) ) {
			return new WP_Error(
				'missing_provider',
				__( 'Provider is required.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// CRITICAL FIX: Check database FIRST for status (updated by webhook).
		// This ensures all users see consistent status without hitting API.
		// Webhook updates database when encoding completes, so database is source of truth.
		$db_status = $this->get_video_status_from_db( $video_id, $provider );

		// If found in database with 'ready' status, return immediately without API call.
		if ( $db_status && 'ready' === $db_status['status'] ) {
			log_debug( 'Status check: Found ready status in database for video_id: ' . $video_id );
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $db_status,
				),
				200
			);
		}

		// If not found or status is 'pending'/'failed' in database, check API for latest status.
		// This handles cases where webhook hasn't fired yet or video is still encoding.
		$result = VideoUploadService::get_video_status( $video_id, $provider );

		if ( is_wp_error( $result ) ) {
			// If Cloudflare returns 500/404, video might not be ready yet - return pending status instead of error
			// This allows frontend to continue polling.
			$error_code  = $result->get_error_code();
			$error_data  = $result->get_error_data();
			$status_code = $error_data['status'] ?? 0;

			log_error( 'Video status check failed - video_id: ' . $video_id . ', provider: ' . $provider . ', error_code: ' . $error_code . ', status_code: ' . $status_code );

			// For 404/500 errors, treat as pending (video may not be ready yet).
			if ( in_array( $status_code, array( 404, 500 ), true ) ) {
				log_debug( 'Treating error as pending status (video may still be encoding)' );
				return new WP_REST_Response(
					array(
						'success' => true,
						'data'    => array(
							'video_id'        => $video_id,
							'provider'        => $provider,
							'status'          => 'pending',
							'readyToStream'   => false,
							'ready_to_stream' => false,
						),
					),
					200
				);
			}

			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Update video status in database
	 *
	 * Handles POST /stream/video-update-status endpoint.
	 * Called by frontend when manifest probe confirms video is ready.
	 * Updates database to prevent encoding overlay flash on page refresh.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request REST API request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with result or error.
	 */
	public function update_status( WP_REST_Request $request ) {
		// Check permissions - must be logged in user.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'unauthorized',
				__( 'You must be logged in to update video status.', 'fchub-stream' ),
				array( 'status' => 401 )
			);
		}

		$video_id = $request->get_param( 'video_id' );
		$provider = $request->get_param( 'provider' );
		$status   = $request->get_param( 'status' );
		$html     = $request->get_param( 'html' );

		if ( empty( $video_id ) || empty( $provider ) || empty( $status ) ) {
			return new WP_Error(
				'missing_params',
				__( 'Video ID, provider, and status are required.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Only allow updating to 'ready' status (security - don't allow arbitrary status changes).
		if ( 'ready' !== $status ) {
			return new WP_Error(
				'invalid_status',
				__( 'Only ready status updates are allowed.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Update database using helper method (same as webhook uses).
		$this->update_video_status_in_db( $video_id, 'ready', $video_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Video status updated successfully.', 'fchub-stream' ),
			),
			200
		);
	}

	/**
	 * Handle provider webhook
	 *
	 * Handles POST /stream/webhook/{provider} endpoint.
	 * Processes webhook callbacks from Cloudflare Stream or Bunny.net Stream
	 * when video encoding is complete.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST API request object.
	 *
	 * @return WP_REST_Response|WP_Error Response with result or error.
	 */
	public function webhook( WP_REST_Request $request ) {
		$provider = $request->get_param( 'provider' );
		$body     = $request->get_body();

		log_debug( 'Webhook endpoint called - Method: ' . $request->get_method() . ', Route: ' . $request->get_route() );

		if ( empty( $provider ) ) {
			log_error( 'Webhook error: Provider missing' );
			return new WP_Error(
				'missing_provider',
				__( 'Provider is required.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		log_debug( 'Webhook provider: ' . $provider );

		// Verify webhook signature.
		$verification = $this->verify_webhook_signature( $request, $provider );

		if ( is_wp_error( $verification ) ) {
			log_error( 'Webhook signature verification failed: ' . $verification->get_error_message() );
			return $verification;
		}

		error_log( '[FCHub Stream] Webhook signature verified successfully' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Get webhook data.
		$data = $request->get_json_params();

		if ( empty( $data ) ) {
			log_error( 'Webhook error: Empty payload. Body: ' . $request->get_body() );
			return new WP_Error(
				'invalid_payload',
				__( 'Invalid webhook payload.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Extract video ID from webhook data.
		$video_id = '';
		if ( 'cloudflare_stream' === $provider && isset( $data['uid'] ) ) {
			$video_id = $data['uid'];
		} elseif ( 'bunny_stream' === $provider && isset( $data['VideoGuid'] ) ) {
			$video_id = $data['VideoGuid'];
		}

		log_debug( 'Webhook payload received: ' . wp_json_encode( $data ) );

		// Process webhook based on provider.
		if ( 'cloudflare_stream' === $provider ) {
			$result = $this->process_cloudflare_webhook( $data );
		} elseif ( 'bunny_stream' === $provider ) {
			$result = $this->process_bunny_webhook( $data );
		} else {
			$result = new WP_Error(
				'unsupported_provider',
				__( 'Unsupported provider.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		if ( is_wp_error( $result ) ) {
			log_error( 'Webhook processing failed: ' . $result->get_error_message() );
			return $result;
		}

		return $result;
	}

	/**
	 * Verify webhook signature
	 *
	 * Validates webhook requests using provider-specific signature verification.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param WP_REST_Request $request  REST API request object.
	 * @param string          $provider Provider name.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	private function verify_webhook_signature( WP_REST_Request $request, $provider ) {
		// For Cloudflare Stream.
		if ( 'cloudflare_stream' === $provider ) {
			// Try both uppercase and lowercase header names (WordPress REST API may normalize headers).
			$signature = $request->get_header( 'webhook-signature' );
			if ( ! $signature ) {
				$signature = $request->get_header( 'Webhook-Signature' );
			}

			if ( empty( $signature ) ) {
				log_error( 'Webhook signature missing. Headers: ' . wp_json_encode( $request->get_headers() ) );
				return new WP_Error(
					'missing_signature',
					__( 'Webhook signature is missing.', 'fchub-stream' ),
					array( 'status' => 401 )
				);
			}

			log_debug( 'Webhook signature found: ' . substr( $signature, 0, 50 ) . '...' );

			// Parse signature: time=1230811200,sig1=60493ec9388b44585a29543bcf0de62e....
			// According to Cloudflare docs: https://developers.cloudflare.com/stream/manage-video-library/using-webhooks/.
			$parts = array();
			foreach ( explode( ',', $signature ) as $part ) {
				list( $key, $value ) = explode( '=', $part, 2 );
				$parts[ $key ]       = $value;
			}

			if ( ! isset( $parts['time'] ) || ! isset( $parts['sig1'] ) ) {
				log_error( 'Invalid signature format. Parts: ' . wp_json_encode( $parts ) );
				return new WP_Error(
					'invalid_signature',
					__( 'Invalid webhook signature format.', 'fchub-stream' ),
					array( 'status' => 401 )
				);
			}

			$timestamp = (int) $parts['time'];
			$sig_hash  = $parts['sig1'];

			// Verify timestamp (prevent replay attacks - allow 5 minutes).
			// Cloudflare docs recommend discarding requests with timestamps that are too old.
			if ( abs( time() - $timestamp ) > 300 ) {
				log_error( 'Webhook signature expired. Timestamp: ' . $timestamp . ', Current: ' . time() );
				return new WP_Error(
					'expired_signature',
					__( 'Webhook signature has expired.', 'fchub-stream' ),
					array( 'status' => 401 )
				);
			}

			// Get webhook secret.
			$config = \FCHubStream\App\Services\StreamConfigService::get_private();
			$secret = $config['cloudflare']['webhook_secret'] ?? '';

			if ( empty( $secret ) ) {
				log_error( 'Webhook secret not configured in settings' );
				return new WP_Error(
					'missing_secret',
					__( 'Webhook secret not configured.', 'fchub-stream' ),
					array( 'status' => 500 )
				);
			}

			// Verify signature according to Cloudflare docs:
			// 1. Create signature source string: timestamp + '.' + body
			// 2. Compute HMAC-SHA256 using secret and source string
			// 3. Compare signatures using constant-time comparison.
			$body          = $request->get_body();
			$source_string = $timestamp . '.' . $body;
			$expected_sig  = hash_hmac( 'sha256', $source_string, $secret );

			// Use constant-time comparison to prevent timing attacks.
			if ( ! hash_equals( $expected_sig, $sig_hash ) ) {
				log_error( 'Signature verification failed. Expected: ' . substr( $expected_sig, 0, 20 ) . '..., Received: ' . substr( $sig_hash, 0, 20 ) . '...' );
				return new WP_Error(
					'invalid_signature',
					__( 'Invalid webhook signature.', 'fchub-stream' ),
					array( 'status' => 401 )
				);
			}

			log_debug( 'Webhook signature verified successfully' );
			return true;
		}

		// For Bunny.net Stream - signature verification to be implemented.
		// Bunny.net doesn't use webhook signatures by default.
		// Consider implementing IP whitelist or other security measures.

		return true;
	}

	/**
	 * Process Cloudflare webhook
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $data Webhook payload.
	 *
	 * @return WP_REST_Response Response.
	 */
	private function process_cloudflare_webhook( $data ) {
		$video_uid       = $data['uid'] ?? '';
		$ready_to_stream = $data['readyToStream'] ?? false;
		$status_state    = $data['status']['state'] ?? '';
		$err_reason_code = $data['status']['errReasonCode'] ?? $data['status']['errorReasonCode'] ?? '';
		$err_reason_text = $data['status']['errReasonText'] ?? $data['status']['errorReasonText'] ?? '';

		// Log webhook receipt (only in debug mode to avoid log spam).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			log_debug( 'Cloudflare webhook received - video_id: ' . $video_uid . ', ready: ' . ( $ready_to_stream ? 'YES' : 'NO' ) . ', state: ' . $status_state );
		}

		if ( empty( $video_uid ) ) {
			return new WP_Error(
				'invalid_payload',
				__( 'Video UID missing in webhook payload.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Handle error state - according to Cloudflare docs, status.state can be 'error'.
		if ( 'error' === $status_state ) {
			$error_msg = 'Video encoding failed - video_id: ' . $video_uid . ', error_code: ' . $err_reason_code . ', error_text: ' . $err_reason_text;
			log_error( $error_msg );

			// Update posts/comments with failed status.
			$this->update_video_status_in_db( $video_uid, 'failed', null, $err_reason_code, $err_reason_text );
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Webhook processed - video encoding failed.', 'fchub-stream' ),
				),
				200
			);
		}

		// Update status and HTML if ready.
		// CRITICAL: Cloudflare may send webhook with readyToStream=true and playback URLs
		// BUT pctComplete < 100, meaning not all quality levels are encoded yet.
		// Manifest URLs may return 404 until pctComplete reaches 100.
		// Reference: https://developers.cloudflare.com/stream/manage-video-library/using-webhooks.
		if ( $ready_to_stream ) {
			// Verify video has playback URLs AND pctComplete is 100 (all quality levels ready).
			$has_playback = isset( $data['playback']['hls'] ) && ! empty( $data['playback']['hls'] );
			$pct_complete = floatval( $data['status']['pctComplete'] ?? 0 );

			// Log pctComplete for debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				log_debug( 'Webhook pctComplete: ' . $pct_complete . '% for video_id: ' . $video_uid );
			}

			if ( $has_playback && $pct_complete >= 100 ) {
				// Log only in debug mode.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					log_debug( 'Video has playback URLs - marking as ready. Video ID: ' . $video_uid );
				}
				$this->update_video_status_in_db( $video_uid, 'ready', $video_uid );
			} else {
				// Video not fully ready yet - this is expected during encoding.
				$reason = ! $has_playback ? 'no playback URLs' : 'pctComplete=' . $pct_complete . '% (< 100%)';

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					log_debug( 'Video readyToStream=true but not fully encoded (' . $reason . ') - keeping as pending. Video ID: ' . $video_uid );
				}
			}
		}

		/**
		 * Fires when Cloudflare Stream webhook is received.
		 *
		 * @since 1.0.0
		 *
		 * @param string $video_uid       Video UID.
		 * @param bool   $ready_to_stream Whether video is ready to stream.
		 * @param string $status_state    Current processing state.
		 * @param array  $data            Full webhook payload.
		 */
		do_action( 'fchub_stream_cloudflare_webhook', $video_uid, $ready_to_stream, $status_state, $data );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Webhook processed successfully.', 'fchub-stream' ),
			),
			200
		);
	}

	/**
	 * Process Bunny.net webhook
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $data Webhook payload.
	 *
	 * @return WP_REST_Response Response.
	 */
	private function process_bunny_webhook( $data ) {
		$video_guid = $data['guid'] ?? $data['VideoGuid'] ?? '';
		$status     = $data['status'] ?? $data['Status'] ?? 0;

		if ( empty( $video_guid ) ) {
			return new WP_Error(
				'invalid_payload',
				__( 'Video GUID missing in webhook payload.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// TODO: Update video status in database or notify user.

		/**
		 * Fires when Bunny.net Stream webhook is received.
		 *
		 * @since 1.0.0
		 *
		 * @param string $video_guid Video GUID.
		 * @param int    $status     Processing status.
		 * @param array  $data       Full webhook payload.
		 */
		do_action( 'fchub_stream_bunny_webhook', $video_guid, $status, $data );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Webhook processed successfully.', 'fchub-stream' ),
			),
			200
		);
	}

	/**
	 * Get video status from database
	 *
	 * Retrieves video status from posts/comments meta.
	 * Used by status polling to avoid redundant API calls after webhook updates.
	 *
	 * @since 2.0.0
	 * @access private
	 *
	 * @param string $video_id Video ID to search for.
	 * @param string $provider Provider name.
	 *
	 * @return array|null Video status data or null if not found.
	 */
	private function get_video_status_from_db( $video_id, $provider ) {
		global $wpdb;

		// Search posts first.
		$posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, meta FROM {$wpdb->prefix}fcom_posts WHERE meta LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'%' . $wpdb->esc_like( $video_id ) . '%'
			)
		);

		if ( $posts ) {
			$meta = maybe_unserialize( $posts[0]->meta );
			if ( isset( $meta['media_preview']['video_id'] ) && $meta['media_preview']['video_id'] === $video_id ) {
				$status = $meta['media_preview']['status'] ?? 'pending';
				$html   = $meta['media_preview']['html'] ?? '';

				return array(
					'video_id'        => $video_id,
					'provider'        => $provider,
					'status'          => $status,
					'readyToStream'   => 'ready' === $status,
					'ready_to_stream' => 'ready' === $status,
					'html'            => $html,
					'thumbnail_url'   => $meta['media_preview']['image'] ?? '',
					'playerUrl'       => '', // Will be extracted from HTML if needed.
				);
			}
		}

		// Search comments if not found in posts.
		$comments = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, meta FROM {$wpdb->prefix}fcom_post_comments WHERE meta LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'%' . $wpdb->esc_like( $video_id ) . '%'
			)
		);

		if ( $comments ) {
			$meta = maybe_unserialize( $comments[0]->meta );
			if ( isset( $meta['media_preview']['video_id'] ) && $meta['media_preview']['video_id'] === $video_id ) {
				$status = $meta['media_preview']['status'] ?? 'pending';
				$html   = $meta['media_preview']['html'] ?? '';

				return array(
					'video_id'        => $video_id,
					'provider'        => $provider,
					'status'          => $status,
					'readyToStream'   => 'ready' === $status,
					'ready_to_stream' => 'ready' === $status,
					'html'            => $html,
					'thumbnail_url'   => $meta['media_preview']['image'] ?? '',
					'playerUrl'       => '', // Will be extracted from HTML if needed.
				);
			}
		}

		// Not found in database.
		return null;
	}

	/**
	 * Update video status in database (posts and comments)
	 *
	 * Helper method to update video status in posts and comments meta.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string      $video_uid      Video UID.
	 * @param string      $status         Status ('ready' or 'failed').
	 * @param string|null $video_id       Video ID for ready status (to generate player HTML).
	 * @param string      $error_code     Error code (for failed status).
	 * @param string      $error_text     Error text (for failed status).
	 *
	 * @return void
	 */
	private function update_video_status_in_db( $video_uid, $status, $video_id = null, $error_code = '', $error_text = '' ) {
		global $wpdb;
		$posts_table    = $wpdb->prefix . 'fcom_posts';
		$comments_table = $wpdb->prefix . 'fcom_post_comments';

		// Use video_id if provided, otherwise use video_uid.
		$search_video_id = $video_id ?? $video_uid;

		// Find posts and comments with this video_id.
		$posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, meta FROM {$wpdb->prefix}fcom_posts WHERE meta LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'%' . $wpdb->esc_like( $search_video_id ) . '%'
			)
		);

		$comments = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, meta FROM {$wpdb->prefix}fcom_post_comments WHERE meta LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'%' . $wpdb->esc_like( $search_video_id ) . '%'
			)
		);

		// Log for debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			log_debug( 'update_video_status_in_db - video_uid: ' . $video_uid . ', video_id: ' . ( $video_id ?? 'null' ) . ', status: ' . $status . ', found posts: ' . count( $posts ) . ', found comments: ' . count( $comments ) );
		}

		// Update posts.
		if ( $posts ) {
			foreach ( $posts as $post ) {
				$meta = maybe_unserialize( $post->meta );

				// Check if video_id matches video_uid OR search_video_id (for flexibility).
				$meta_video_id = $meta['media_preview']['video_id'] ?? null;
				if ( $meta_video_id && ( $meta_video_id === $video_uid || $meta_video_id === $search_video_id ) ) {
					if ( 'ready' === $status && $video_id ) {
						$provider = $meta['media_preview']['provider'] ?? \FCHubStream\App\Services\StreamConfigService::get_enabled_provider();

						// Verify video exists in provider before generating HTML.
						$video_exists = true;
						if ( 'cloudflare_stream' === $provider ) {
							try {
								$config = \FCHubStream\App\Services\StreamConfigService::get_cloudflare_config();
								if ( ! empty( $config['account_id'] ) && ! empty( $config['api_token'] ) ) {
									$api        = new \FCHubStream\App\Services\CloudflareApiService( $config['account_id'], $config['api_token'] );
									$video_info = $api->get_video( $video_id );

									if ( is_wp_error( $video_info ) ) {
										$video_exists = false;
										log_error( 'Video not found in Cloudflare Stream. Video ID: ' . $video_id );
									} elseif ( isset( $video_info['readyToStream'] ) && ! $video_info['readyToStream'] ) {
										$video_exists = false;
										log_debug( 'Video exists but not readyToStream. Video ID: ' . $video_id );
									} elseif ( empty( $video_info['playback']['hls'] ) ) {
										$video_exists = false;
										log_debug( 'Video exists but no playback URLs. Video ID: ' . $video_id );
									}
								}
							} catch ( \Exception $e ) {
								log_error( 'Error checking video existence: ' . $e->getMessage() );
							}
						}

						if ( $video_exists ) {
							$player_renderer = new \FCHubStream\App\Hooks\PortalIntegration\VideoPlayerRenderer();
							$player_html     = $player_renderer->get_player_html( $video_id, $provider, 'ready' );

							if ( empty( $player_html ) || strpos( $player_html, 'not available' ) !== false ) {
								log_error( 'Failed to generate player HTML for video_id: ' . $video_id . ', provider: ' . $provider );
							}

							$meta['media_preview']['html']   = $player_html;
							$meta['media_preview']['status'] = 'ready';

							if ( $video_id ) {
								$meta['media_preview']['video_id'] = $video_id;
							}
						} else {
							log_debug( 'Video not ready or not found, keeping status as pending. Video ID: ' . $video_id );
						}
					} elseif ( 'failed' === $status ) {
						$meta['media_preview']['status']     = 'failed';
						$meta['media_preview']['error_code'] = $error_code;
						$meta['media_preview']['error_text'] = $error_text;
					}

					$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$posts_table,
						array( 'meta' => maybe_serialize( $meta ) ),
						array( 'id' => $post->id ),
						array( '%s' ),
						array( '%d' )
					);

					if ( false === $updated ) {
						log_error( 'Failed to update post meta for post ID: ' . $post->id . ', video_id: ' . $video_id );
					} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						log_debug( 'Successfully updated post ID: ' . $post->id . ' with video_id: ' . $video_id . ', status: ' . $status );
					}
				} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					log_debug( 'Post ID ' . $post->id . ' has video_id mismatch. Meta video_id: ' . ( $meta_video_id ?? 'null' ) . ', expected: ' . $video_uid . ' or ' . $search_video_id );
				}
			}
		}

		// Update comments.
		if ( $comments ) {
			foreach ( $comments as $comment_row ) {
				$meta = maybe_unserialize( $comment_row->meta );

				// Check if video_id matches video_uid OR search_video_id (for flexibility).
				$meta_video_id = $meta['media_preview']['video_id'] ?? null;
				if ( $meta_video_id && ( $meta_video_id === $video_uid || $meta_video_id === $search_video_id ) ) {
					if ( 'ready' === $status && $video_id ) {
						$provider = $meta['media_preview']['provider'] ?? \FCHubStream\App\Services\StreamConfigService::get_enabled_provider();

						// Verify video exists in provider before generating HTML.
						$video_exists = true;
						if ( 'cloudflare_stream' === $provider ) {
							try {
								$config = \FCHubStream\App\Services\StreamConfigService::get_cloudflare_config();
								if ( ! empty( $config['account_id'] ) && ! empty( $config['api_token'] ) ) {
									$api        = new \FCHubStream\App\Services\CloudflareApiService( $config['account_id'], $config['api_token'] );
									$video_info = $api->get_video( $video_id );

									if ( is_wp_error( $video_info ) ) {
										$video_exists = false;
										log_error( 'Video not found in Cloudflare Stream (comment). Video ID: ' . $video_id );
									} elseif ( isset( $video_info['readyToStream'] ) && ! $video_info['readyToStream'] ) {
										$video_exists = false;
										log_debug( 'Video exists but not readyToStream (comment). Video ID: ' . $video_id );
									} elseif ( empty( $video_info['playback']['hls'] ) ) {
										$video_exists = false;
										log_debug( 'Video exists but no playback URLs (comment). Video ID: ' . $video_id );
									}
								}
							} catch ( \Exception $e ) {
								log_error( 'Error checking video existence (comment): ' . $e->getMessage() );
							}
						}

						if ( $video_exists ) {
							$player_renderer = new \FCHubStream\App\Hooks\PortalIntegration\VideoPlayerRenderer();
							$player_html     = $player_renderer->get_player_html( $video_id, $provider, 'ready' );

							if ( empty( $player_html ) || strpos( $player_html, 'not available' ) !== false ) {
								log_error( 'Failed to generate player HTML for comment video_id: ' . $video_id . ', provider: ' . $provider );
							}

							$meta['media_preview']['html']   = $player_html;
							$meta['media_preview']['status'] = 'ready';

							if ( $video_id ) {
								$meta['media_preview']['video_id'] = $video_id;
							}
						} else {
							log_debug( 'Video not ready or not found (comment), keeping status as pending. Video ID: ' . $video_id );
						}
					} elseif ( 'failed' === $status ) {
						$meta['media_preview']['status']     = 'failed';
						$meta['media_preview']['error_code'] = $error_code;
						$meta['media_preview']['error_text'] = $error_text;
					}

					$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$comments_table,
						array( 'meta' => maybe_serialize( $meta ) ),
						array( 'id' => $comment_row->id ),
						array( '%s' ),
						array( '%d' )
					);

					if ( false === $updated ) {
						log_error( 'Failed to update comment meta for comment ID: ' . $comment_row->id . ', video_id: ' . $video_id );
					} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						log_debug( 'Successfully updated comment ID: ' . $comment_row->id . ' with video_id: ' . $video_id . ', status: ' . $status );
					}
				}
			}
		}
	}

	/**
	 * Get upload error message
	 *
	 * Converts PHP upload error codes to user-friendly messages.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param int $error_code PHP upload error code.
	 *
	 * @return string Error message.
	 */
	private function get_upload_error_message( $error_code ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => __( 'File exceeds upload_max_filesize directive in php.ini.', 'fchub-stream' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds MAX_FILE_SIZE directive in HTML form.', 'fchub-stream' ),
			UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'fchub-stream' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'fchub-stream' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Missing temporary folder.', 'fchub-stream' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'fchub-stream' ),
			UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the file upload.', 'fchub-stream' ),
		);

		return $messages[ $error_code ] ?? __( 'Unknown upload error.', 'fchub-stream' );
	}
}
