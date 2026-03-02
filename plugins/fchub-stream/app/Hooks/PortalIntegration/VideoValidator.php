<?php
/**
 * Video Validator Class
 *
 * Handles video existence validation for different streaming providers.
 *
 * @package FCHubStream\App\Hooks\PortalIntegration
 */

namespace FCHubStream\App\Hooks\PortalIntegration;

use FCHubStream\App\Services\StreamConfigService;
use FCHubStream\App\Services\CloudflareApiService;
use FCHubStream\App\Services\BunnyApiService;

/**
 * Class VideoValidator
 *
 * Validates video existence across different streaming providers.
 */
class VideoValidator {

	/**
	 * Check if a video exists on the streaming provider.
	 *
	 * @param string $video_id The video ID to check.
	 * @param string $provider The streaming provider (cloudflare_stream or bunny_stream).
	 *
	 * @return bool True if video exists or cannot be verified, false if definitely doesn't exist.
	 */
	public function video_exists( string $video_id, string $provider ): bool {
		if ( empty( $video_id ) || empty( $provider ) ) {
			return false;
		}

		try {
			if ( 'cloudflare_stream' === $provider ) {
				$config = StreamConfigService::get_cloudflare_config();
				if ( empty( $config['account_id'] ) || empty( $config['api_token'] ) ) {
					// Can't check - assume exists to avoid breaking display.
					return true;
				}

				$api        = new CloudflareApiService( $config['account_id'], $config['api_token'] );
				$video_info = $api->get_video( $video_id );

				// If not WP_Error and has data, video exists.
				return ! is_wp_error( $video_info ) && ! empty( $video_info );
			} elseif ( 'bunny_stream' === $provider ) {
				$config = StreamConfigService::get_bunny_config();
				if ( empty( $config['library_id'] ) || empty( $config['api_key'] ) ) {
					// Can't check - assume exists to avoid breaking display.
					return true;
				}

				// Bunny.net doesn't have a direct "get video" endpoint in our service,
				// so we'll try to delete it - if it returns 404, video doesn't exist.
				// Actually, better approach: try to get video list and check if video_id is in it.
				// For now, assume exists if we can't verify (to avoid breaking display).
				// TODO: Add get_video() method to BunnyApiService if available.
				return true; // Assume exists until we can verify.
			}
		} catch ( \Exception $e ) {
			// On error, assume video exists to avoid breaking display.
			error_log( '[FCHub Stream] Error checking video existence: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return true;
		}

		return false;
	}
}
