<?php
/**
 * Video Player Renderer
 *
 * Handles HTML generation for video players and shortcode replacement.
 * Supports Cloudflare Stream and Bunny Stream providers with various
 * player states including pending/encoding status.
 *
 * @package FCHubStream
 * @subpackage Hooks\PortalIntegration
 * @since 1.0.0
 */

namespace FCHubStream\App\Hooks\PortalIntegration;

use FCHubStream\App\Services\StreamConfigService;
use FCHubStream\App\Services\CloudflareApiService;

/**
 * Class VideoPlayerRenderer
 *
 * Responsible for generating video player HTML and managing WordPress
 * content filtering to allow iframe and div tags for video players.
 *
 * @since 1.0.0
 */
class VideoPlayerRenderer {

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_kses_allowed_html', array( $this, 'allow_iframe_in_kses' ), 10, 2 );
	}

	/**
	 * Get player HTML for a video ID.
	 *
	 * Generates the appropriate HTML for video players based on the provider
	 * (Cloudflare Stream or Bunny Stream). Handles special states like pending/encoding.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $video_id            Video ID.
	 * @param string|null $provider            Optional. Provider name. If not specified, uses enabled provider.
	 * @param string|null $status              Optional. Video status (pending, ready, etc.).
	 * @param string      $customer_subdomain  Optional. Cloudflare customer subdomain for encoding overlay.
	 * @return string Player HTML.
	 */
	public function get_player_html( string $video_id, ?string $provider = null, ?string $status = null, string $customer_subdomain = '' ): string {
		// Use specified provider or get enabled provider.
		$enabled_provider = $provider ?? StreamConfigService::get_enabled_provider();

		if ( ! $enabled_provider ) {
			return '<p><em>Video player not available (no provider enabled)</em></p>';
		}

		$player_html = '';

		if ( 'cloudflare_stream' === $enabled_provider ) {
			$config = StreamConfigService::get_cloudflare_config();
			// Use customer_subdomain from parameter if provided, otherwise from config.
			if ( empty( $customer_subdomain ) ) {
				$customer_subdomain = $config['customer_subdomain'] ?? '';
			}

			// Normalize customer_subdomain - extract only subdomain part if full URL is provided.
			if ( ! empty( $customer_subdomain ) ) {
				// If contains .cloudflarestream.com, extract just the subdomain part.
				if ( preg_match( '/https?:\/\/(customer-[a-z0-9]+)\.cloudflarestream\.com/', $customer_subdomain, $matches ) ) {
					$customer_subdomain = $matches[1];
				} elseif ( preg_match( '/^(customer-[a-z0-9]+)\.cloudflarestream\.com/', $customer_subdomain, $matches ) ) {
					$customer_subdomain = $matches[1];
				}
				// Otherwise assume it's already just the subdomain (customer-xxx).
			}

			// If status is pending or null/unknown, show thumbnail with encoding overlay.
			// Only render iframe if status is explicitly 'ready'.
			if ( 'ready' !== $status ) {
				// If customer_subdomain is provided, use it directly for encoding overlay.
				// This avoids API call when frontend already sent customer_subdomain.
				if ( empty( $customer_subdomain ) && ! empty( $config['account_id'] ) && ! empty( $config['api_token'] ) ) {
					try {
						$api        = new CloudflareApiService( $config['account_id'], $config['api_token'] );
						$video_info = $api->get_video( $video_id );

						if ( ! is_wp_error( $video_info ) ) {
							// Extract customer_subdomain from HLS URL.
							if ( isset( $video_info['playback']['hls'] ) ) {
								if ( preg_match( '/https?:\/\/(customer-[a-z0-9]+)\.cloudflarestream\.com/', $video_info['playback']['hls'], $matches ) ) {
									$customer_subdomain = $matches[1];
								}
							}

							// Check if video is ready to stream AND has playback URLs.
							// Cloudflare may set readyToStream=true before playback URLs are accessible.
							$ready_to_stream = $video_info['readyToStream'] ?? false;
							$has_playback    = isset( $video_info['playback']['hls'] ) && ! empty( $video_info['playback']['hls'] );

							if ( $ready_to_stream && $has_playback ) {
								// Video is ready - render iframe.
								$status = 'ready';
							} else {
								// Video is not ready - show encoding overlay.
								$status = 'pending';
							}
						} elseif ( is_wp_error( $video_info ) ) {
							error_log( '[FCHub Stream] Cloudflare API error checking video status. Video ID: ' . $video_id . ', Error: ' . $video_info->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					} catch ( \Exception $e ) {
						// Silently fail - will show encoding overlay or error.
						error_log( '[FCHub Stream] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}

				// Show encoding overlay if not ready.
				if ( 'ready' !== $status && $customer_subdomain ) {
					$thumbnail_url = "https://{$customer_subdomain}.cloudflarestream.com/{$video_id}/thumbnails/thumbnail.jpg";

					return sprintf(
						'<div class="fchub-stream-player-wrapper fchub-stream-encoding" data-video-id="%s" data-provider="%s" data-status="%s" style="position: relative; padding-bottom: 56.25%%; height: 0; overflow: hidden; background: #000; margin: 0 !important;">
							<img src="%s" style="position: absolute; top: 0; left: 0; width: 100%%; height: 100%%; object-fit: cover;" alt="Video thumbnail" />
							<div style="position: absolute; top: 0; left: 0; width: 100%%; height: 100%%; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.5); color: white; font-size: 18px; font-weight: 500;">
								<svg style="width: 24px; height: 24px; margin-right: 8px; animation: spin 1s linear infinite;" viewBox="0 0 24 24">
									<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
									<path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
								</svg>
								<span>Encoding video...</span>
							</div>
							<style>
								@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
							</style>
						</div>',
						esc_attr( $video_id ),
						esc_attr( $enabled_provider ),
						esc_attr( $status ?? 'pending' ),
						esc_url( $thumbnail_url )
					);
				}
			}

			// If customer_subdomain missing and status is ready, fetch from API to get it.
			if ( empty( $customer_subdomain ) && ! empty( $config['account_id'] ) && ! empty( $config['api_token'] ) && 'ready' === $status ) {
				try {
					$api        = new CloudflareApiService( $config['account_id'], $config['api_token'] );
					$video_info = $api->get_video( $video_id );

					if ( ! is_wp_error( $video_info ) && isset( $video_info['playback']['hls'] ) ) {
						// Extract customer_subdomain from HLS URL.
						if ( preg_match( '/https?:\/\/(customer-[a-z0-9]+)\.cloudflarestream\.com/', $video_info['playback']['hls'], $matches ) ) {
							$customer_subdomain = $matches[1];
						}

						// Double-check: video must have playback URLs even if status is 'ready'.
						// Cloudflare may set readyToStream=true before playback URLs are accessible.
						$ready_to_stream = $video_info['readyToStream'] ?? false;
						$has_playback    = isset( $video_info['playback']['hls'] ) && ! empty( $video_info['playback']['hls'] );

						if ( ! ( $ready_to_stream && $has_playback ) ) {
							// Video not actually ready - don't render iframe.
							$status = 'pending';
						}
					} elseif ( is_wp_error( $video_info ) ) {
						error_log( '[FCHub Stream] Cloudflare API error fetching video info. Video ID: ' . $video_id . ', Error: ' . $video_info->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

						// Don't render iframe if API call failed.
						$status = 'pending';
					}
				} catch ( \Exception $e ) {
					// Silently fail - will try to render iframe anyway.
					error_log( '[FCHub Stream] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}

			// Only render iframe if status is 'ready' and we have customer_subdomain.
			if ( 'ready' === $status && $customer_subdomain ) {
				$player_html = sprintf(
					'<div class="fchub-stream-player-wrapper" data-video-id="%s" data-provider="cloudflare_stream" style="position: relative; padding-bottom: 56.25%%; height: 0; overflow: hidden; margin: 0 !important;">
						<iframe
							src="https://%s.cloudflarestream.com/%s/iframe?postMessage=true"
							style="position: absolute; top: 0; left: 0; width: 100%%; height: 100%%; border: 0;"
							allow="accelerometer; gyroscope; autoplay; encrypted-media;"
							allowfullscreen="true">
						</iframe>
					</div>',
					esc_attr( $video_id ),
					esc_attr( $customer_subdomain ),
					esc_attr( $video_id )
				);
			}
		} elseif ( 'bunny_stream' === $enabled_provider ) {
			$config     = StreamConfigService::get_bunny_config();
			$library_id = $config['library_id'] ?? '';

			if ( $library_id ) {
				$player_html = sprintf(
					'<div class="fchub-stream-player-wrapper" data-video-id="%s" data-provider="bunny_stream" style="position: relative; padding-bottom: 56.25%%; height: 0; overflow: hidden;">
						<iframe
							src="https://iframe.mediadelivery.net/embed/%s/%s?autoplay=false"
							style="position: absolute; top: 0; left: 0; width: 100%%; height: 100%%; border: 0;"
							allow="accelerometer; gyroscope; autoplay; encrypted-media;"
							allowfullscreen="true">
						</iframe>
					</div>',
					esc_attr( $video_id ),
					esc_attr( $library_id ),
					esc_attr( $video_id )
				);
			}
		}

		if ( empty( $player_html ) ) {
			return '<p><em>Video player not available</em></p>';
		}

		return $player_html;
	}

	/**
	 * Replace shortcodes with video player HTML.
	 *
	 * Processes [fchub_stream:VIDEO_ID] and [fchub_stream:VIDEO_ID provider="PROVIDER"]
	 * shortcodes in content and replaces them with the appropriate video player HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Content containing shortcodes.
	 * @return string Content with shortcodes replaced by player HTML.
	 */
	public function replace_shortcodes_with_player( string $content ): string {
		// Pattern with optional provider attribute.
		return preg_replace_callback(
			'/\[fchub_stream:([a-zA-Z0-9_-]+)(?:\s+provider="(cloudflare_stream|bunny_stream)")?\]/',
			function ( $matches ) {
				$video_id = $matches[1];
				$provider = $matches[2] ?? null;
				return $this->get_player_html( $video_id, $provider );
			},
			$content
		);
	}

	/**
	 * Allow iframe and div tags in WordPress content filtering.
	 *
	 * Modifies the allowed HTML tags for the 'post' context to include
	 * iframe and div tags with specific attributes needed for video players.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $allowed_tags Allowed HTML tags and attributes.
	 * @param string $context      Context for allowed tags.
	 * @return array Modified allowed tags.
	 */
	public function allow_iframe_in_kses( array $allowed_tags, string $context ): array {
		if ( 'post' === $context ) {
			$allowed_tags['iframe'] = array(
				'src'             => true,
				'width'           => true,
				'height'          => true,
				'style'           => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'frameborder'     => true,
			);

			$allowed_tags['div'] = array(
				'class'         => true,
				'style'         => true,
				'data-video-id' => true,
				'data-provider' => true,
			);
		}

		return $allowed_tags;
	}
}
