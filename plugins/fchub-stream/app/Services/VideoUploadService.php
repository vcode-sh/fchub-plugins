<?php
/**
 * Video Upload Service
 *
 * Orchestrates video upload operations across multiple providers (Cloudflare Stream, Bunny.net Stream).
 * Handles file validation, provider selection, upload coordination, and response formatting.
 *
 * @package FCHubStream
 * @subpackage Services
 * @since 1.0.0
 */

namespace FCHubStream\App\Services;

use WP_Error;
use function FCHubStream\App\Utils\log_debug;
use function FCHubStream\App\Utils\log_error;

/**
 * Video Upload Service class.
 *
 * Coordinates video upload workflow including validation, provider selection,
 * and upload execution. Provides unified interface for uploading to different providers.
 *
 * @since 1.0.0
 */
class VideoUploadService {

	/**
	 * Upload video file to configured provider
	 *
	 * Main upload orchestration method. Validates file, selects provider,
	 * performs upload, and formats response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Absolute path to video file.
	 * @param string $filename  Original filename.
	 * @param array  $metadata  Optional. Video metadata. Default empty array.
	 *
	 * @return array|WP_Error {
	 *     Upload result on success, WP_Error on failure.
	 *
	 *     @type string $video_id         Video ID from provider.
	 *     @type string $provider         Provider name ('cloudflare_stream' or 'bunny_stream').
	 *     @type string $status           Upload status ('pending' or 'ready').
	 *     @type string $thumbnail_url    Thumbnail URL.
	 *     @type string $player_url       Player iframe URL.
	 *     @type string $html             Player HTML code.
	 *     @type int    $width            Video width.
	 *     @type int    $height           Video height.
	 *     @type bool   $ready_to_stream  Whether video is ready to stream.
	 * }
	 */
	public static function upload( $file_path, $filename, $metadata = array() ) {
		$filesize  = file_exists( $file_path ) ? filesize( $file_path ) : 0;
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );

		// Fallback: if extension is empty, try to get it from file_path.
		if ( empty( $extension ) ) {
			$extension = pathinfo( $file_path, PATHINFO_EXTENSION );
		}

		// Normalize extension to lowercase and ensure it's not empty.
		$extension = ! empty( $extension ) ? strtolower( $extension ) : 'unknown';

		try {
			// Start timer for upload performance tracking.
			$upload_start_time = microtime( true );

			// Validate file.
			$validation = self::validate_file( $file_path, $filename );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			// Get provider configuration.
			$config   = StreamConfigService::get_private();
			$provider = $config['provider'] ?? 'cloudflare';

			// Upload to provider.
			if ( 'bunny' === $provider ) {
				$result = self::upload_to_bunny( $file_path, $filename, $metadata, $config );
			} else {
				$result = self::upload_to_cloudflare( $file_path, $filename, $metadata, $config );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Store upload timestamp for encoding time calculation (expires in 14 days).
			$upload_start_time_unix = time();
			$total_upload_time      = microtime( true ) - $upload_start_time;
			$video_id               = $result['video_id'] ?? '';
			if ( ! empty( $video_id ) ) {
				set_transient( 'fchub_stream_upload_time_' . $video_id, $upload_start_time_unix, 14 * DAY_IN_SECONDS );
				set_transient( 'fchub_stream_upload_duration_' . $video_id, $total_upload_time, 14 * DAY_IN_SECONDS );
			}

			return $result;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'upload_exception',
				sprintf(
					/* translators: %s: Error message */
					__( 'Video upload failed: %s', 'fchub-stream' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Upload to Cloudflare Stream
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string $file_path Absolute path to video file.
	 * @param string $filename  Original filename.
	 * @param array  $metadata  Video metadata.
	 * @param array  $config    Provider configuration.
	 *
	 * @return array|WP_Error Upload result or error.
	 */
	private static function upload_to_cloudflare( $file_path, $filename, $metadata, $config ) {
		$cloudflare = $config['cloudflare'] ?? array();

		if ( empty( $cloudflare['account_id'] ) || empty( $cloudflare['api_token'] ) ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Cloudflare Stream credentials not configured.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Create API service.
		$api = new CloudflareApiService(
			$cloudflare['account_id'],
			$cloudflare['api_token']
		);

		// Upload video.
		$result = $api->upload_video( $file_path, $filename, $metadata );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Set allowedOrigins to allow video playback from WordPress site domain.
		$video_uid = $result['uid'] ?? '';
		if ( ! empty( $video_uid ) ) {
			$site_url = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $site_url ) {
				$allowed_origins = array( $site_url );
				if ( strpos( $site_url, 'www.' ) === 0 ) {
					$allowed_origins[] = substr( $site_url, 4 );
				} elseif ( strpos( $site_url, 'www.' ) !== 0 ) {
					$allowed_origins[] = 'www.' . $site_url;
				}

				$update_result = $api->update_video(
					$video_uid,
					array(
						'allowedOrigins' => $allowed_origins,
					)
				);

				if ( is_wp_error( $update_result ) ) {
					error_log( '[FCHub Stream] Failed to set allowedOrigins for video ' . $video_uid . ': ' . $update_result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[FCHub Stream] Successfully set allowedOrigins for video ' . $video_uid . ': ' . wp_json_encode( $allowed_origins ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}

		// Format response.
		return self::format_cloudflare_response( $result, $cloudflare );
	}

	/**
	 * Upload to Bunny.net Stream
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string $file_path Absolute path to video file.
	 * @param string $filename  Original filename.
	 * @param array  $metadata  Video metadata.
	 * @param array  $config    Provider configuration.
	 *
	 * @return array|WP_Error Upload result or error.
	 */
	private static function upload_to_bunny( $file_path, $filename, $metadata, $config ) {
		$bunny = $config['bunny'] ?? array();

		if ( empty( $bunny['library_id'] ) || empty( $bunny['api_key'] ) ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Bunny.net Stream credentials not configured.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Create API service (Account API Key needed for constructor).
		$api = new BunnyApiService(
			$bunny['api_key'],
			$bunny['api_key'],
			(int) $bunny['library_id']
		);

		// Upload video.
		$result = $api->upload_video(
			$file_path,
			$filename,
			$metadata,
			(int) $bunny['library_id'],
			$bunny['api_key']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Format response.
		return self::format_bunny_response( $result, $bunny );
	}

	/**
	 * Validate video file
	 *
	 * Validates file exists, size, and format against configuration limits.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Absolute path to video file.
	 * @param string $filename  Original filename (used to extract extension).
	 *
	 * @return true|WP_Error True if valid, WP_Error on validation failure.
	 */
	public static function validate_file( $file_path, $filename = '' ) {
		// Check file exists.
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'Video file not found.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		// Get upload settings.
		$defaults             = get_option( 'fchub_stream_upload_settings', array() );
		$max_file_size_mb     = $defaults['max_file_size'] ?? $defaults['max_file_size_mb'] ?? 500;
		$allowed_formats      = $defaults['allowed_formats'] ?? array( 'mp4', 'mov', 'webm', 'avi' );

		// Check file size.
		$file_size_mb = filesize( $file_path ) / 1024 / 1024;

		if ( $file_size_mb > $max_file_size_mb ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %d: Maximum file size in MB */
					__( 'File size exceeds maximum allowed size (%dMB).', 'fchub-stream' ),
					$max_file_size_mb
				),
				array( 'status' => 400 )
			);
		}

		// Check file format.
		$source_for_extension = ! empty( $filename ) ? $filename : $file_path;
		$file_extension       = strtolower( pathinfo( $source_for_extension, PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_extension, $allowed_formats, true ) ) {
			return new WP_Error(
				'invalid_format',
				sprintf(
					/* translators: %s: Comma-separated list of allowed formats */
					__( 'File format not allowed. Allowed formats: %s', 'fchub-stream' ),
					implode( ', ', $allowed_formats )
				),
				array( 'status' => 400 )
			);
		}

		// Check MIME type.
		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$mime_type = finfo_file( $finfo, $file_path );
		finfo_close( $finfo );

		$allowed_mime_types = array(
			'video/mp4',
			'video/quicktime',
			'video/webm',
			'video/x-msvideo',
		);

		if ( ! in_array( $mime_type, $allowed_mime_types, true ) ) {
			return new WP_Error(
				'invalid_mime_type',
				__( 'Invalid video file type.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Format Cloudflare response
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $result    API response from Cloudflare.
	 * @param array $cloudflare Cloudflare configuration.
	 *
	 * @return array Formatted response.
	 */
	private static function format_cloudflare_response( $result, $cloudflare ) {
		$video_id = $result['uid'] ?? '';
		$status   = $result['status']['state'] ?? 'pending';
		$ready    = $result['readyToStream'] ?? false;

		// Extract customer subdomain from playback URL.
		$customer_subdomain = '';
		if ( isset( $result['playback']['hls'] ) ) {
			$hls_url = $result['playback']['hls'];
			if ( preg_match( '/https?:\/\/(customer-[a-z0-9]+)\.cloudflarestream\.com/', $hls_url, $matches ) ) {
				$customer_subdomain = $matches[1];
			}
		}

		// Fallback to account_id if extraction failed.
		if ( empty( $customer_subdomain ) ) {
			$account_id         = $cloudflare['account_id'] ?? '';
			$customer_subdomain = "customer-{$account_id}";
		}

		// Generate player URL and HTML.
		$player_url = "https://{$customer_subdomain}.cloudflarestream.com/{$video_id}/iframe";

		$pct_complete = floatval( $result['status']['pctComplete'] ?? 0 );
		$actual_ready = $ready && isset( $result['playback']['hls'] ) && ! empty( $result['playback']['hls'] ) && $pct_complete >= 100;

		$player_html = '';
		if ( $actual_ready ) {
			$player_html = sprintf(
				'<div class="fchub-stream-player-wrapper" data-video-id="%s" data-provider="cloudflare_stream" style="position: relative; padding-bottom: 56.25%%; height: 0; overflow: hidden; margin: 0 !important;">
					<iframe
						src="%s"
						style="position: absolute; top: 0; left: 0; width: 100%%; height: 100%%; border: 0;"
						allow="accelerometer; gyroscope; autoplay; encrypted-media;"
						allowfullscreen="true">
					</iframe>
				</div>',
				esc_attr( $video_id ),
				esc_url( $player_url )
			);
		}

		$thumbnail_url = $result['thumbnail'] ?? '';

		return array(
			'video_id'           => $video_id,
			'provider'           => 'cloudflare_stream',
			'status'             => $actual_ready ? 'ready' : 'pending',
			'thumbnail_url'      => $thumbnail_url,
			'player_url'         => $player_url,
			'html'               => $player_html,
			'width'              => 1920,
			'height'             => 1080,
			'readyToStream'      => $actual_ready,
			'ready_to_stream'    => $actual_ready,
			'customer_subdomain' => $customer_subdomain,
		);
	}

	/**
	 * Format Bunny response
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $result API response from Bunny.net.
	 * @param array $bunny  Bunny.net configuration.
	 *
	 * @return array Formatted response.
	 */
	private static function format_bunny_response( $result, $bunny ) {
		$video_id   = $result['guid'] ?? '';
		$status_int = $result['status'] ?? 0;
		$ready      = ( 5 === $status_int );

		$library_id = $bunny['library_id'] ?? '';

		$player_url  = "https://iframe.mediadelivery.net/embed/{$library_id}/{$video_id}?autoplay=false";
		$player_html = sprintf(
			'<iframe src="%s" style="border: none; width: 100%%; aspect-ratio: 16/9;" allow="accelerometer; autoplay; encrypted-media; gyroscope;" allowfullscreen="true"></iframe>',
			esc_url( $player_url )
		);

		return array(
			'video_id'        => $video_id,
			'provider'        => 'bunny_stream',
			'status'          => $ready ? 'ready' : 'pending',
			'thumbnail_url'   => '',
			'player_url'      => $player_url,
			'html'            => $player_html,
			'width'           => 1920,
			'height'          => 1080,
			'ready_to_stream' => $ready,
		);
	}

	/**
	 * Get video status
	 *
	 * @since 1.0.0
	 *
	 * @param string $video_id Video ID from provider.
	 * @param string $provider Provider name ('cloudflare_stream' or 'bunny_stream').
	 *
	 * @return array|WP_Error Status information or error.
	 */
	public static function get_video_status( $video_id, $provider ) {
		$config = StreamConfigService::get_private();

		if ( 'bunny_stream' === $provider ) {
			return self::get_bunny_video_status( $video_id, $config );
		}

		return self::get_cloudflare_video_status( $video_id, $config );
	}

	/**
	 * Get Cloudflare video status
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private static function get_cloudflare_video_status( $video_id, $config ) {
		$cloudflare = $config['cloudflare'] ?? array();

		if ( empty( $cloudflare['account_id'] ) || empty( $cloudflare['api_token'] ) ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Cloudflare Stream credentials not configured.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		$api = new CloudflareApiService(
			$cloudflare['account_id'],
			$cloudflare['api_token']
		);

		$result = $api->get_video( $video_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::format_cloudflare_response( $result, $cloudflare );
	}

	/**
	 * Get Bunny video status
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private static function get_bunny_video_status( $video_id, $config ) {
		$bunny = $config['bunny'] ?? array();

		if ( empty( $bunny['library_id'] ) || empty( $bunny['api_key'] ) ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Bunny.net Stream credentials not configured.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		return new WP_Error(
			'not_implemented',
			__( 'Bunny.net video status check not yet implemented.', 'fchub-stream' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Generate player HTML
	 *
	 * @since 1.0.0
	 *
	 * @param string $video_id Video ID from provider.
	 * @param string $provider Provider name ('cloudflare_stream' or 'bunny_stream').
	 *
	 * @return string|WP_Error Player HTML or error.
	 */
	public static function generate_player_html( $video_id, $provider ) {
		$config = StreamConfigService::get_public();

		if ( 'bunny_stream' === $provider ) {
			$bunny      = $config['bunny'] ?? array();
			$library_id = $bunny['library_id'] ?? '';

			if ( empty( $library_id ) ) {
				return new WP_Error(
					'missing_config',
					__( 'Bunny.net library ID not configured.', 'fchub-stream' ),
					array( 'status' => 400 )
				);
			}

			$player_url = "https://iframe.mediadelivery.net/embed/{$library_id}/{$video_id}?autoplay=false";

			return sprintf(
				'<iframe src="%s" style="border: none; width: 100%%; aspect-ratio: 16/9;" allow="accelerometer; autoplay; encrypted-media; gyroscope;" allowfullscreen="true"></iframe>',
				esc_url( $player_url )
			);
		}

		// Cloudflare Stream.
		$cloudflare = $config['cloudflare'] ?? array();
		$account_id = $cloudflare['account_id'] ?? '';

		if ( empty( $account_id ) ) {
			return new WP_Error(
				'missing_config',
				__( 'Cloudflare account ID not configured.', 'fchub-stream' ),
				array( 'status' => 400 )
			);
		}

		$player_url = "https://customer-{$account_id}.cloudflarestream.com/{$video_id}/iframe";

		return sprintf(
			'<iframe src="%s" style="border: none; width: 100%%; aspect-ratio: 16/9;" allow="accelerometer; gyroscope; autoplay; encrypted-media;" allowfullscreen="true"></iframe>',
			esc_url( $player_url )
		);
	}
}
