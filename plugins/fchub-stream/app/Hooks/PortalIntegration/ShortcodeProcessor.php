<?php
/**
 * Shortcode Processor for FCHub Stream
 *
 * Handles processing of video shortcodes in posts and comments.
 * Integrates with FluentCommunity feed system to display video players.
 *
 * @package FCHubStream
 * @since 1.0.0
 */

namespace FCHubStream\App\Hooks\PortalIntegration;

use FCHubStream\App\Services\StreamConfigService;

/**
 * Class ShortcodeProcessor
 *
 * Processes video shortcodes before save and during API responses.
 */
class ShortcodeProcessor {
	/**
	 * Player renderer instance
	 *
	 * @var object PlayerRenderer instance
	 */
	private $player_renderer;

	/**
	 * Video validator instance
	 *
	 * @var object VideoValidator instance
	 */
	private $video_validator;

	/**
	 * Constructor
	 *
	 * @param object $player_renderer PlayerRenderer instance for generating player HTML.
	 * @param object $video_validator VideoValidator instance for checking video existence.
	 */
	public function __construct( $player_renderer, $video_validator ) {
		$this->player_renderer = $player_renderer;
		$this->video_validator = $video_validator;
	}

	/**
	 * Register WordPress hooks
	 *
	 * @return void
	 */
	public function register(): void {
		// CRITICAL: Priority 5 runs BEFORE processFeedMetaData (priority 10).
		add_filter( 'fluent_community/feed/new_feed_data', array( $this, 'process_shortcodes_before_save' ), 5, 2 );
		// Process shortcodes in requestData['media']['html'] BEFORE processFeedMetaData() during updates.
		// Use rest_pre_dispatch to modify $request before FeedsController::update() processes it.
		add_filter( 'rest_pre_dispatch', array( $this, 'process_media_html_in_request' ), 10, 3 );
		// Process shortcodes during feed updates (when editing posts) - fallback for message content.
		// CRITICAL: Priority 999 runs AFTER FluentCommunity's processFeedMetaData to ensure proper preservation.
		add_filter( 'fluent_community/feed/update_data', array( $this, 'process_shortcodes_before_update' ), 999, 2 );
		// Handle media removal during updates (has access to $requestData).
		add_filter( 'fluent_community/feed/update_feed_data', array( $this, 'process_media_removal_during_update' ), 5, 2 );
		add_action( 'fluent_community/comment_added', array( $this, 'process_comment_media_after_create' ), 5, 2 );
		add_filter( 'fluent_community/feed_api_response', array( $this, 'process_shortcodes_in_response' ), 10, 2 );
		add_filter( 'fluent_community/feeds_api_response', array( $this, 'process_shortcodes_in_feeds' ), 10, 2 );
		// Register post-update hook to emit event to frontend (GAP #7).
		add_action( 'fluent_community/feed/updated', array( $this, 'handle_feed_updated' ), 10, 2 );
	}

	/**
	 * Process video shortcodes before saving feed data
	 *
	 * Checks media object first, then falls back to message content.
	 * Creates media_preview meta for video embeds with pending status.
	 *
	 * @param array $data Feed data being saved.
	 * @param array $request_data Original request data from client.
	 * @return array Modified feed data with media_preview meta.
	 */
	public function process_shortcodes_before_save( $data, $request_data ) {
		error_log( '[FCHub Stream] process_shortcodes_before_save() - CALLED' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[FCHub Stream] request_data keys: ' . implode( ', ', array_keys( $request_data ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Log message content to check for shortcode.
		if ( isset( $request_data['message'] ) ) {
			$message_preview = substr( $request_data['message'], 0, 200 );
			error_log( '[FCHub Stream] request_data message: ' . $message_preview ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Check if this is an update (feed ID present in request_data).
		$is_update = isset( $request_data['id'] ) || isset( $request_data['feed_id'] );
		if ( $is_update ) {
			error_log( '[FCHub Stream] process_shortcodes_before_save() - DETECTED UPDATE (feed ID: ' . ( $request_data['id'] ?? $request_data['feed_id'] ?? 'unknown' ) . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		if ( isset( $request_data['media'] ) ) {
			error_log( '[FCHub Stream] media object present: ' . wp_json_encode( $request_data['media'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} else {
			error_log( '[FCHub Stream] NO media object in request_data!' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Only process if data is an array.
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// Check if media object contains our shortcode (from fetch interceptor).
		if ( isset( $request_data['media']['html'] ) && strpos( $request_data['media']['html'], '[fchub_stream:' ) !== false ) {
			error_log( '[FCHub Stream] process_shortcodes_before_save() - Found video in media.html' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			$shortcode = $request_data['media']['html'];
			$pattern   = '/\[fchub_stream:([a-zA-Z0-9_-]+)(?:\s+provider="(cloudflare_stream|bunny_stream)")?\]/';

			if ( preg_match( $pattern, $shortcode, $matches ) ) {
				$video_id = $matches[1];
				$provider = $matches[2] ?? null;

				// Get status from media object (sent from frontend after upload).
				// Default to 'pending' if not provided.
				$status = $request_data['media']['status'] ?? 'pending';

				// Get customer_subdomain from frontend (for Cloudflare encoding overlay).
				$customer_subdomain = $request_data['media']['customer_subdomain'] ?? '';

				error_log( '[FCHub Stream] process_shortcodes_before_save() - Video ID: ' . $video_id . ', Provider: ' . ( $provider ?? 'auto' ) . ', Status: ' . $status . ', Customer subdomain: ' . $customer_subdomain ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

				// Get player HTML with actual status from frontend.
				// Pass customer_subdomain as 4th parameter for encoding overlay.
				$player_html = $this->player_renderer->get_player_html( $video_id, $provider, $status, $customer_subdomain );

				// Get thumbnail.
				$thumbnail_url    = $request_data['media']['image'] ?? '';
				$enabled_provider = $provider ?? StreamConfigService::get_enabled_provider();

				// Note: player_html already includes wrapper div with margin fix from VideoPlayerRenderer.
				// No need to wrap again - just use as-is.

				// Create media_preview.
				$data['meta']['media_preview'] = array(
					'type'         => 'iframe_html',
					'provider'     => $enabled_provider,
					'html'         => $player_html,
					'image'        => $thumbnail_url,
					'video_id'     => $video_id,
					'status'       => $status, // Use status from frontend (pending/ready).
					'content_type' => 'video',
				);

				// CRITICAL: Remove shortcode from message and message_rendered to prevent it from appearing in post content.
				$message          = $data['message'] ?? '';
				$message_rendered = $data['message_rendered'] ?? '';

				// Remove shortcode from both message fields.
				$data['message']          = preg_replace( $pattern, '', $message );
				$data['message_rendered'] = preg_replace( $pattern, '', $message_rendered );

				// Clean up extra whitespace.
				$data['message']          = trim( $data['message'] );
				$data['message_rendered'] = trim( $data['message_rendered'] );

				error_log( '[FCHub Stream] process_shortcodes_before_save() - Created media_preview and removed shortcode from message' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

				return $data;
			}
		}

		// Fallback: Check message for shortcode (old method).
		$message          = $data['message'] ?? '';
		$message_rendered = $data['message_rendered'] ?? '';

		// Look for shortcode pattern: [fchub_stream:VIDEO_ID] or [fchub_stream:VIDEO_ID provider="..."].
		$pattern = '/\[fchub_stream:([a-zA-Z0-9_-]+)(?:\s+provider="(cloudflare_stream|bunny_stream)")?\]/';

		// CRITICAL: Check both message_rendered AND message (during updates, shortcode may be in message only).
		$matches = null;
		if ( preg_match( $pattern, $message_rendered, $matches ) ) {
			error_log( '[FCHub Stream] process_shortcodes_before_save() - Found shortcode in message_rendered' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} elseif ( preg_match( $pattern, $message, $matches ) ) {
			error_log( '[FCHub Stream] process_shortcodes_before_save() - Found shortcode in message (not in message_rendered)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		if ( $matches ) {
			$video_id = $matches[1];
			$provider = $matches[2] ?? null;

			error_log( '[FCHub Stream] process_shortcodes_before_save() - Found shortcode: video_id=' . $video_id . ', provider=' . ( $provider ?? 'auto' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Get player HTML with pending status (fallback for old shortcodes).
			$player_html = $this->player_renderer->get_player_html( $video_id, $provider, 'pending' );

			// Extract thumbnail URL from player HTML or use default.
			$thumbnail_url    = '';
			$enabled_provider = $provider ?? StreamConfigService::get_enabled_provider();

			if ( 'cloudflare_stream' === $enabled_provider ) {
				$config             = StreamConfigService::get_cloudflare_config();
				$customer_subdomain = $config['customer_subdomain'] ?? '';

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

				if ( $customer_subdomain ) {
					$thumbnail_url = "https://{$customer_subdomain}.cloudflarestream.com/{$video_id}/thumbnails/thumbnail.jpg";
				}
			}

			// Create media_preview object (like YouTube oembed).
			$data['meta']['media_preview'] = array(
				'type'         => 'iframe_html',
				'provider'     => $enabled_provider,
				'html'         => $player_html,
				'image'        => $thumbnail_url,
				'video_id'     => $video_id,
				'status'       => 'pending', // Will be updated to 'ready' by webhook.
				'content_type' => 'video',
			);

			// If this is replacing an existing video (from frontend), store replacement info.
			// VideoDeletionHandler will use this to delete old video.
			if ( isset( $body_params['media']['replaces_video_id'] ) ) {
				$data['meta']['media_preview']['replaces_video_id'] = $body_params['media']['replaces_video_id'];
				$data['meta']['media_preview']['replaces_provider'] = $body_params['media']['replaces_provider'] ?? $enabled_provider;
				error_log( '[FCHub Stream] process_shortcodes_before_save() - Video replacement detected: old=' . $body_params['media']['replaces_video_id'] . ', new=' . $video_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			// Remove shortcode from message and message_rendered.
			$data['message']          = preg_replace( $pattern, '', $message );
			$data['message_rendered'] = preg_replace( $pattern, '', $message_rendered );

			// Clean up extra whitespace.
			$data['message']          = trim( $data['message'] );
			$data['message_rendered'] = trim( $data['message_rendered'] );

			error_log( '[FCHub Stream] process_shortcodes_before_save() - Created media_preview and removed shortcode' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $data;
	}

	/**
	 * Process shortcode in request['media']['html'] BEFORE processFeedMetaData() - ONLY FOR UPDATES
	 *
	 * This hook runs BEFORE FeedsController::update() processes the request.
	 * Converts shortcode in request body to player HTML so processFeedMetaData() receives ready HTML.
	 *
	 * CRITICAL: Only processes UPDATE requests (with feed ID in route), not CREATE.
	 * For CREATE, use fluent_community/feed/new_feed_data hook which already handles shortcode removal.
	 *
	 * @param mixed  $result Response to replace the requested value with.
	 * @param object $server REST server instance.
	 * @param object $request Request used to generate the response.
	 * @return mixed Unchanged result (we modify $request by reference).
	 */
	public function process_media_html_in_request( $result, $server, $request ) {
		// Only process PUT/POST requests to feeds endpoint.
		$route = $request->get_route();
		if ( ! $route || strpos( $route, '/fluent-community/v2/feeds/' ) === false ) {
			return $result;
		}

		// Determine if this is CREATE or UPDATE request.
		// Pattern: /fluent-community/v2/feeds/{id} = UPDATE.
		// Pattern: /fluent-community/v2/feeds = CREATE.
		$route_parts      = explode( '/', trim( $route, '/' ) );
		$feed_id_in_route = false;

		// Check if route ends with a numeric ID (update) or just 'feeds' (create).
		if ( count( $route_parts ) >= 5 && is_numeric( $route_parts[4] ) ) {
			$feed_id_in_route = true;
		}

		$is_update = $feed_id_in_route;
		$is_create = ! $feed_id_in_route;

		// Process both CREATE and UPDATE requests.
		// For CREATE: Remove shortcode from message BEFORE message_rendered is created.
		// For UPDATE: Remove shortcode from message and process media.html.
		if ( ! $is_create && ! $is_update ) {
			return $result;
		}

		// Only process PUT/POST methods.
		$method = $request->get_method();
		if ( ! in_array( $method, array( 'PUT', 'POST' ), true ) ) {
			return $result;
		}

		// Get request body parameters.
		$body_params = $request->get_body_params();
		if ( ! is_array( $body_params ) ) {
			return $result;
		}

		// Check if media object contains shortcode in html field.
		$has_media_shortcode = isset( $body_params['media']['html'] ) && strpos( $body_params['media']['html'], '[fchub_stream:' ) !== false;

		// Also check if shortcode is in message (frontend inserts it into editor).
		$message               = $body_params['message'] ?? '';
		$has_message_shortcode = strpos( $message, '[fchub_stream:' ) !== false;

		// CRITICAL: For UPDATE requests, check if media.html is empty/null - this means user removed video.
		// If media.html is empty and there's existing video in meta, we need to remove media_preview.
		if ( $is_update ) {
			$media_html       = $body_params['media']['html'] ?? '';
			$media_html_empty = empty( trim( $media_html ) );

			// If media.html is empty and no shortcode in message, user removed video - clear media_preview.
			if ( $media_html_empty && ! $has_message_shortcode ) {
				// Set media.html to null to signal removal to processFeedMetaData.
				$body_params['media'] = array( 'html' => null );
				$request->set_body_params( $body_params );
				error_log( '[FCHub Stream] process_media_html_in_request() - UPDATE: Media.html is empty, clearing media_preview' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return $result;
			}
		}

		if ( ! $has_media_shortcode && ! $has_message_shortcode ) {
			return $result;
		}

		$pattern = '/\[fchub_stream:([a-zA-Z0-9_-]+)(?:\s+provider="(cloudflare_stream|bunny_stream)")?\]/';
		$matches = null;

		// Extract video_id from media.html or message.
		if ( $has_media_shortcode && preg_match( $pattern, $body_params['media']['html'], $matches ) ) {
			$request_type = $is_update ? 'UPDATE' : 'CREATE';
			error_log( '[FCHub Stream] process_media_html_in_request() - ' . $request_type . ': Found shortcode in request body[media][html]' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} elseif ( $has_message_shortcode && preg_match( $pattern, $message, $matches ) ) {
			$request_type = $is_update ? 'UPDATE' : 'CREATE';
			error_log( '[FCHub Stream] process_media_html_in_request() - ' . $request_type . ': Found shortcode in request body[message]' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		if ( $matches ) {
			$video_id = $matches[1];
			$provider = $matches[2] ?? null;

			// Get status from media object (sent from frontend after upload).
			$status = $body_params['media']['status'] ?? 'pending';

			// Get customer_subdomain from frontend (for Cloudflare encoding overlay).
			$customer_subdomain = $body_params['media']['customer_subdomain'] ?? '';

			$request_type = $is_update ? 'UPDATE' : 'CREATE';
			error_log( '[FCHub Stream] process_media_html_in_request() - ' . $request_type . ': Video ID: ' . $video_id . ', Provider: ' . ( $provider ?? 'auto' ) . ', Status: ' . $status ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Get player HTML with actual status from frontend.
			$player_html = $this->player_renderer->get_player_html( $video_id, $provider, $status, $customer_subdomain );

			// CRITICAL: Modify request body parameters so processFeedMetaData() receives processed HTML.
			// For both CREATE and UPDATE, process media.html if it contains shortcode.
			if ( $has_media_shortcode ) {
				$body_params['media']['html'] = $player_html;
			} elseif ( $has_message_shortcode ) {
				// If shortcode is in message but not in media.html, add media object.
				// This handles case where frontend adds shortcode to message but not to media.html.
				if ( ! isset( $body_params['media'] ) ) {
					$body_params['media'] = array();
				}
				$body_params['media']['html']     = $player_html;
				$body_params['media']['image']    = ''; // Will be set by processFeedMetaData.
				$body_params['media']['video_id'] = $video_id;
				$body_params['media']['status']   = $status;
			}

			// Preserve replacement info if frontend sent it (for video replacement in edit modal).
			if ( isset( $body_params['media']['replaces_video_id'] ) ) {
				// Keep replaces_video_id and replaces_provider in media object.
				// They will be copied to meta.media_preview by processFeedMetaData.
				error_log( '[FCHub Stream] process_media_html_in_request() - Preserving replacement info: old=' . $body_params['media']['replaces_video_id'] . ', new=' . $video_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			// CRITICAL: Remove shortcode from message to prevent it from appearing in post content.
			// This must happen BEFORE FeedsController creates message_rendered from message.
			// For CREATE: Before line 412 in FeedsController::store().
			// For UPDATE: Before line 543 in FeedsController::update().
			if ( $has_message_shortcode ) {
				$body_params['message'] = preg_replace( $pattern, '', $message );
				$body_params['message'] = trim( $body_params['message'] );
				error_log( '[FCHub Stream] process_media_html_in_request() - ' . $request_type . ': Removed shortcode from message' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			$request->set_body_params( $body_params );

			error_log( '[FCHub Stream] process_media_html_in_request() - ' . $request_type . ': Processed shortcode in request body' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $result;
	}

	/**
	 * Process video shortcodes before updating feed data
	 *
	 * Handles both:
	 * 1. Creating media_preview if shortcode is present in update data
	 * 2. Removing media_preview if shortcode is NOT present (user removed video during edit)
	 *
	 * @param array  $data Data array for updating the feed.
	 * @param object $feed Existing Feed model instance (with old meta).
	 * @return array Modified feed data.
	 */
	public function process_shortcodes_before_update( $data, $feed ) {
		error_log( '[FCHub Stream] process_shortcodes_before_update() - CALLED | Post ID: ' . ( $feed->id ?? 'unknown' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[FCHub Stream] process_shortcodes_before_update() - Data keys: ' . implode( ', ', array_keys( $data ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// CRITICAL: Check if we processed shortcode in media.html earlier (in update_feed_data hook).
		// If yes, replace meta.media_preview.html with processed iframe HTML.
		global $fchub_stream_processed_html;
		if ( ! empty( $fchub_stream_processed_html ) && isset( $data['meta']['media_preview'] ) ) {
			error_log( '[FCHub Stream] process_shortcodes_before_update() - Found processed HTML from earlier hook, replacing media_preview.html' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Replace shortcode in media_preview.html with iframe HTML.
			$data['meta']['media_preview']['html']     = $fchub_stream_processed_html['html'];
			$data['meta']['media_preview']['video_id'] = $fchub_stream_processed_html['video_id'];
			$data['meta']['media_preview']['provider'] = $fchub_stream_processed_html['provider'];
			$data['meta']['media_preview']['status']   = $fchub_stream_processed_html['status'];

			error_log( '[FCHub Stream] process_shortcodes_before_update() - Replaced media_preview.html with iframe (video_id: ' . $fchub_stream_processed_html['video_id'] . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Clear global to prevent reuse.
			$fchub_stream_processed_html = null;

			// Return early - we already processed the video.
			return $data;
		}

		// Only process if data is an array.
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// CRITICAL: Preserve existing FCHub Stream video if no media is sent in request.
		// When user edits post (title/content) without changing media, FluentCommunity doesn't send media object.
		// We must preserve existing media_preview to prevent video loss.
		$existing_meta      = $feed->meta ?? array();
		$has_existing_video = isset( $existing_meta['media_preview']['video_id'] ) &&
								isset( $existing_meta['media_preview']['provider'] ) &&
								in_array( $existing_meta['media_preview']['provider'], array( 'cloudflare_stream', 'bunny_stream' ), true );

		// CRITICAL: Use array_key_exists() to differentiate:
		// - Key doesn't exist: user didn't change media → preserve existing video.
		// - Key exists with null value: user explicitly removed media → DON'T preserve, allow deletion.
		$meta_exists              = isset( $data['meta'] ) && is_array( $data['meta'] );
		$media_preview_key_exists = $meta_exists && array_key_exists( 'media_preview', $data['meta'] );
		$media_preview_is_null    = $media_preview_key_exists && $data['meta']['media_preview'] === null;

		// CRITICAL: Check global flag from process_media_removal_during_update().
		// If hook #1 detected media removal, DON'T restore video here!
		global $fchub_stream_media_was_removed;
		if ( ! empty( $fchub_stream_media_was_removed ) ) {
			error_log( '[FCHub Stream] process_shortcodes_before_update() - User removed media (detected by earlier hook), NOT preserving video' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			// Clear flag.
			$fchub_stream_media_was_removed = null;
			// Don't preserve! Continue to deletion logic below.
		} elseif ( $media_preview_is_null ) {
			// FluentCommunity set media_preview to null - user explicitly removed video.
			// DON'T preserve existing video, allow deletion to proceed.
			error_log( '[FCHub Stream] process_shortcodes_before_update() - media_preview is null, user removed video, allowing deletion' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			// Don't preserve! Continue to deletion logic below.
		} elseif ( $has_existing_video && ! $media_preview_key_exists ) {
			// media_preview key doesn't exist in $data - user didn't change media, preserve existing video.
			error_log( '[FCHub Stream] process_shortcodes_before_update() - CRITICAL: Preserving existing FCHub Stream video (no media in request)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Ensure meta array exists.
			if ( ! isset( $data['meta'] ) ) {
				$data['meta'] = array();
			}

			// Preserve existing media_preview.
			$data['meta']['media_preview'] = $existing_meta['media_preview'];

			error_log( '[FCHub Stream] process_shortcodes_before_update() - Preserved video: ' . $existing_meta['media_preview']['video_id'] . ' (' . $existing_meta['media_preview']['provider'] . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// If there's existing video but no shortcode in message, user removed video.
		// Message is empty - might be just removing video, check if we should clear media_preview.
		// This will be handled by checking if shortcode exists below.

		// Get message content to check for shortcodes.
		$message          = $data['message'] ?? '';
		$message_rendered = $data['message_rendered'] ?? '';
		$combined_message = $message . ' ' . $message_rendered;

		error_log( '[FCHub Stream] process_shortcodes_before_update() - Message length: ' . strlen( $message ) . ' | Message rendered length: ' . strlen( $message_rendered ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( ! empty( $message ) ) {
			error_log( '[FCHub Stream] process_shortcodes_before_update() - Message preview: ' . substr( $message, 0, 200 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		if ( ! empty( $message_rendered ) ) {
			error_log( '[FCHub Stream] process_shortcodes_before_update() - Message rendered preview: ' . substr( $message_rendered, 0, 200 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Pattern to match [fchub_stream:VIDEO_ID] shortcodes.
		$pattern = '/\[fchub_stream:([a-zA-Z0-9_-]+)(?:\s+provider="(cloudflare_stream|bunny_stream)")?\]/';

		// Check if shortcode exists in message or message_rendered.
		$has_shortcode = preg_match( $pattern, $combined_message );

		// Get existing meta (from feed object or from data array).
		$existing_meta      = $feed->meta ?? $data['meta'] ?? array();
		$has_existing_video = isset( $existing_meta['media_preview']['video_id'] );

		error_log( '[FCHub Stream] process_shortcodes_before_update() - Has shortcode: ' . ( $has_shortcode ? 'yes' : 'no' ) . ' | Has existing video: ' . ( $has_existing_video ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// If shortcode exists, process it (create/update media_preview).
		if ( $has_shortcode ) {
			// CRITICAL: Check both message_rendered AND message (during updates, shortcode may be in message only).
			$matches = null;
			if ( preg_match( $pattern, $message_rendered, $matches ) ) {
				error_log( '[FCHub Stream] process_shortcodes_before_update() - Found shortcode in message_rendered' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} elseif ( preg_match( $pattern, $message, $matches ) ) {
				error_log( '[FCHub Stream] process_shortcodes_before_update() - Found shortcode in message (not in message_rendered)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			if ( $matches ) {
				$video_id          = $matches[1];
				$provider          = $matches[2] ?? null;
				$matched_shortcode = $matches[0]; // Full matched shortcode string.

				error_log( '[FCHub Stream] process_shortcodes_before_update() - Found shortcode: video_id=' . $video_id . ', provider=' . ( $provider ?? 'auto' ) . ', shortcode=' . $matched_shortcode ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

				// Get player HTML with pending status (or ready if video already exists and is ready).
				$existing_status = $existing_meta['media_preview']['status'] ?? 'pending';
				$status          = ( $has_existing_video && $existing_meta['media_preview']['video_id'] === $video_id ) ? $existing_status : 'pending';

				$player_html = $this->player_renderer->get_player_html( $video_id, $provider, $status );

				// Extract thumbnail URL.
				$thumbnail_url    = '';
				$enabled_provider = $provider ?? StreamConfigService::get_enabled_provider();

				if ( 'cloudflare_stream' === $enabled_provider ) {
					$config             = StreamConfigService::get_cloudflare_config();
					$customer_subdomain = $config['customer_subdomain'] ?? '';

					// Normalize customer_subdomain.
					if ( ! empty( $customer_subdomain ) ) {
						if ( preg_match( '/https?:\/\/(customer-[a-z0-9]+)\.cloudflarestream\.com/', $customer_subdomain, $subdomain_matches ) ) {
							$customer_subdomain = $subdomain_matches[1];
						} elseif ( preg_match( '/^(customer-[a-z0-9]+)\.cloudflarestream\.com/', $customer_subdomain, $subdomain_matches ) ) {
							$customer_subdomain = $subdomain_matches[1];
						}
					}

					if ( $customer_subdomain ) {
						$thumbnail_url = "https://{$customer_subdomain}.cloudflarestream.com/{$video_id}/thumbnails/thumbnail.jpg";
					}
				}

				// Ensure meta array exists.
				if ( ! isset( $data['meta'] ) ) {
					$data['meta'] = array();
				}

				// Create/update media_preview.
				$data['meta']['media_preview'] = array(
					'type'         => 'iframe_html',
					'provider'     => $enabled_provider,
					'html'         => $player_html,
					'image'        => $thumbnail_url,
					'video_id'     => $video_id,
					'status'       => $status,
					'content_type' => 'video',
				);

				// If this is replacing an existing video (from frontend), store replacement info.
				// VideoDeletionHandler will use this to delete old video.
				// Note: This is for process_shortcodes_before_update hook, not process_media_html_in_request.
				// processFeedMetaData will copy replaces_video_id from body_params['media'] to meta.media_preview.

				// CRITICAL: Remove only the matched shortcode (not all shortcodes).
				// Use preg_quote to escape special regex characters in the matched shortcode.
				$escaped_shortcode = preg_quote( $matched_shortcode, '/' );
				$specific_pattern  = '/' . $escaped_shortcode . '/';

				// Remove only this specific shortcode from message and message_rendered.
				$data['message']          = preg_replace( $specific_pattern, '', $message, 1 );
				$data['message_rendered'] = preg_replace( $specific_pattern, '', $message_rendered, 1 );

				// Clean up extra whitespace.
				$data['message']          = trim( $data['message'] );
				$data['message_rendered'] = trim( $data['message_rendered'] );

				error_log( '[FCHub Stream] process_shortcodes_before_update() - Created/updated media_preview and removed shortcode: ' . $matched_shortcode ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( '[FCHub Stream] process_shortcodes_before_update() - WARNING: Shortcode detected but could not match pattern' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		} elseif ( $has_existing_video ) {
			// CRITICAL: Before deleting, check if media_preview was already set by FluentCommunity
			// When frontend sends video in 'media' object (not shortcode in message),
			// FluentCommunity's processFeedMetaData() copies it to $data['meta']['media_preview'].
			// We must NOT delete it in this case!
			$already_has_media_preview = isset( $data['meta']['media_preview']['video_id'] );

			if ( $already_has_media_preview ) {
				// FluentCommunity already processed 'media' object from request and set media_preview.
				// Video is OK, preserve it (don't delete).
				error_log( '[FCHub Stream] process_shortcodes_before_update() - No shortcode in message but media_preview already set by FluentCommunity (from request media object), preserving video' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				// No shortcode in message, no media_preview in data, but existing video exists.
				// This means user removed video (cleared media in frontend).
				error_log( '[FCHub Stream] process_shortcodes_before_update() - Shortcode removed and no media in request, deleting media_preview from meta' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

				// Ensure meta array exists.
				if ( ! isset( $data['meta'] ) ) {
					$data['meta'] = $existing_meta;
				}

				// Remove media_preview from meta.
				unset( $data['meta']['media_preview'] );

				// If meta is now empty, set to empty array (FluentCommunity expects array).
				if ( empty( $data['meta'] ) ) {
					$data['meta'] = array();
				}
			}
		}

		return $data;
	}

	/**
	 * Handle media removal during feed update AND process shortcodes in media.html
	 *
	 * CRITICAL: This hook runs BEFORE processFeedMetaData, so we can modify request_data.
	 * FluentCommunity will copy media.html to meta.media_preview.html, so we must
	 * process shortcode to iframe HTML HERE before that happens.
	 *
	 * Checks if media.html is null/empty in request_data and removes media_preview from meta.
	 * Also processes FCHub Stream shortcodes in media.html to iframe HTML.
	 * This hook has access to $request_data, unlike fluent_community/feed/update_data.
	 *
	 * @param array $data         Data array for updating the feed.
	 * @param array $request_data Full request data including media object.
	 * @return array Modified feed data.
	 */
	public function process_media_removal_during_update( $data, $request_data ) {
		// CRITICAL: Use array_key_exists() instead of isset() to differentiate:
		// - Key doesn't exist: user didn't touch media → preserve existing video.
		// - Key exists with null value: user explicitly removed media → delete video.
		$media_key_exists = array_key_exists( 'media', $request_data );
		$media_is_null    = $media_key_exists && $request_data['media'] === null;

		if ( $media_is_null ) {
			// User explicitly set media to null (removed video) - process deletion.
			error_log( '[FCHub Stream] process_media_removal_during_update() - Media is explicitly null, user removed video' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// CRITICAL: Set global flag to tell process_shortcodes_before_update() NOT to preserve video.
			// Without this, hook #2 would restore the video we just deleted!
			global $fchub_stream_media_was_removed;
			$fchub_stream_media_was_removed = true;

			// Set media_html to null to trigger deletion logic below.
			$media_html = null;
		} elseif ( ! $media_key_exists ) {
			// Media key not in request at all - user didn't touch media, preserve existing video.
			error_log( '[FCHub Stream] process_media_removal_during_update() - No media key in request, preserving existing media_preview (if any)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $data;
		} else {
			// Media exists and is not null - check media.html.
			$media_html = $request_data['media']['html'] ?? null;
		}

		if ( $media_html === null || ( is_string( $media_html ) && empty( trim( $media_html ) ) ) ) {
			// User explicitly removed video - clear media_preview from meta.
			error_log( '[FCHub Stream] process_media_removal_during_update() - Media.html is null/empty, removing media_preview' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Ensure meta array exists.
			if ( ! isset( $data['meta'] ) ) {
				$data['meta'] = array();
			}

			// Remove media_preview from meta.
			unset( $data['meta']['media_preview'] );

			// If meta is now empty, set to empty array (FluentCommunity expects array).
			if ( empty( $data['meta'] ) ) {
				$data['meta'] = array();
			}

			return $data;
		}

		// CRITICAL: Process FCHub Stream shortcode in media.html BEFORE FluentCommunity copies it.
		// Pattern to match [fchub_stream:VIDEO_ID] or [fchub_stream:VIDEO_ID provider="..."].
		$pattern = '/\[fchub_stream:([a-zA-Z0-9_-]+)(?:\s+provider="(cloudflare_stream|bunny_stream)")?\]/';

		if ( is_string( $media_html ) && preg_match( $pattern, $media_html, $matches ) ) {
			$video_id = $matches[1];
			$provider = $matches[2] ?? null;

			error_log( '[FCHub Stream] process_media_removal_during_update() - Found shortcode in media.html: ' . $matches[0] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FCHub Stream] process_media_removal_during_update() - Video ID: ' . $video_id . ', Provider: ' . ( $provider ?? 'auto' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Get video status from request_data if available.
			$status = $request_data['media']['status'] ?? 'pending';

			// Generate iframe HTML.
			$player_html = $this->player_renderer->get_player_html( $video_id, $provider, $status );

			// CRITICAL: Modify $request_data['media']['html'] so FluentCommunity copies iframe, not shortcode.
			// We use a reference trick: we need to modify $request_data which is passed by value,
			// but we can't do that. Instead, we'll add filter to modify it.
			// Actually, we CAN'T modify $request_data here because it's passed by value.
			// We need a different approach - modify via filter on the result.

			// Instead, we'll set a flag and process in next hook.
			// Actually, let's use a global to pass the processed HTML.
			global $fchub_stream_processed_html;
			$fchub_stream_processed_html = array(
				'video_id' => $video_id,
				'provider' => $provider ?? StreamConfigService::get_enabled_provider(),
				'html'     => $player_html,
				'status'   => $status,
			);

			error_log( '[FCHub Stream] process_media_removal_during_update() - Processed shortcode to iframe HTML (stored in global)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $data;
	}

	/**
	 * Process comment media after creation
	 *
	 * Handles video shortcodes in comment media by creating media_preview meta.
	 * Uses raw request body to access media object before FluentCommunity processing.
	 *
	 * @param object $comment Comment model instance.
	 * @param object $feed Feed model instance (unused).
	 * @return void
	 */
	public function process_comment_media_after_create( $comment, $feed ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Get raw request body to check for media object.
		$raw_body     = file_get_contents( 'php://input' );
		$request_data = json_decode( $raw_body, true );

		error_log( '[FCHub Stream] process_comment_media_after_create() - comment ID: ' . $comment->id . ', request keys: ' . implode( ', ', array_keys( $request_data ?? array() ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( isset( $request_data['media']['html'] ) && strpos( $request_data['media']['html'], '[fchub_stream:' ) !== false ) {
			error_log( '[FCHub Stream] process_comment_media_after_create() - Found video in media' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			$shortcode = $request_data['media']['html'];
			$pattern   = '/\[fchub_stream:([a-zA-Z0-9_-]+)(?:\s+provider="(cloudflare_stream|bunny_stream)")?\]/';

			if ( preg_match( $pattern, $shortcode, $matches ) ) {
				$video_id = $matches[1];
				$provider = $matches[2] ?? null;

				// Get status from media object (sent from frontend after upload).
				// Default to 'pending' if not provided.
				$status = $request_data['media']['status'] ?? 'pending';

				// Get customer_subdomain from frontend (for Cloudflare encoding overlay).
				$customer_subdomain = $request_data['media']['customer_subdomain'] ?? '';

				error_log( '[FCHub Stream] process_comment_media_after_create() - Video ID: ' . $video_id . ', Provider: ' . ( $provider ?? 'auto' ) . ', Status: ' . $status . ', Customer subdomain: ' . $customer_subdomain ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

				// Get player HTML with actual status from frontend.
				// Pass customer_subdomain as 4th parameter for encoding overlay.
				$player_html = $this->player_renderer->get_player_html( $video_id, $provider, $status, $customer_subdomain );

				// Get thumbnail.
				$thumbnail_url    = $request_data['media']['image'] ?? '';
				$enabled_provider = $provider ?? StreamConfigService::get_enabled_provider();

				// Get current meta (use model accessor).
				$meta = $comment->meta ?? array();

				// Note: player_html already includes wrapper div from VideoPlayerRenderer.
				// No need to wrap again - just use as-is.

				// Create media_preview for comment.
				$meta['media_preview'] = array(
					'type'         => 'iframe_html',
					'provider'     => $enabled_provider,
					'html'         => $player_html,
					'image'        => $thumbnail_url,
					'video_id'     => $video_id,
					'status'       => $status, // Use status from frontend (pending/ready).
					'content_type' => 'video',
				);

				// Use Eloquent model to update (proper way).
				$comment->meta = $meta;
				$saved         = $comment->save();

				if ( $saved ) {
					error_log( '[FCHub Stream] process_comment_media_after_create() - Comment meta updated successfully' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				} else {
					error_log( '[FCHub Stream] process_comment_media_after_create() - Failed to save comment' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}
	}

	/**
	 * Process shortcodes in single feed API response
	 *
	 * Validates video existence and replaces shortcodes with player HTML.
	 * Only processes feeds endpoint, skips chat and other endpoints.
	 *
	 * @param array $data API response data.
	 * @param array $with Additional data requested (unused).
	 * @return array Modified API response.
	 */
	public function process_shortcodes_in_response( $data, $with ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only process if data is an array and has feed structure.
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// CRITICAL: Only process feeds endpoint - skip chat and other endpoints.
		// Chat returns ['messages' => ...], not ['feed' => ...].
		if ( ! isset( $data['feed'] ) ) {
			return $data;
		}

		// Process feed if it exists.
		if ( isset( $data['feed'] ) && is_array( $data['feed'] ) ) {
			// Validate video exists if meta has video_id.
			if ( isset( $data['feed']['meta']['media_preview']['video_id'] ) ) {
				$video_id = $data['feed']['meta']['media_preview']['video_id'];
				$provider = $data['feed']['meta']['media_preview']['provider'] ?? null;
				$status   = $data['feed']['meta']['media_preview']['status'] ?? null;
				$html     = $data['feed']['meta']['media_preview']['html'] ?? '';

				// Check if video exists in provider (only if provider is our stream provider).
				if ( in_array( $provider, array( 'cloudflare_stream', 'bunny_stream' ), true ) ) {
					// If status is 'ready', ensure HTML is updated (not encoding overlay).
					if ( 'ready' === $status ) {
						// Check if HTML still contains encoding overlay (old HTML).
						if ( strpos( $html, 'fchub-stream-encoding' ) !== false || strpos( $html, 'Encoding video...' ) !== false ) {
							// Regenerate HTML with ready status (no encoding overlay).
							$player_html = $this->player_renderer->get_player_html( $video_id, $provider, 'ready' );
							// Note: player_html already includes wrapper - no need to wrap again.
							$data['feed']['meta']['media_preview']['html'] = $player_html;
							$feed_id                                       = $data['feed']['id'] ?? null;
							error_log( '[FCHub Stream] Updated media_preview HTML for ready video in post ID: ' . ( $feed_id ?? 'unknown' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

							// CRITICAL: Update database so next page load shows iframe immediately.
							if ( $feed_id ) {
								$this->update_feed_meta_in_db( $feed_id, $data['feed']['meta'] );
							}
						}
					} elseif ( strpos( $html, '<iframe' ) !== false && strpos( $html, 'fchub-stream-encoding' ) === false ) {
						// If status is not 'ready', ensure HTML shows encoding overlay (not iframe).
						// HTML contains iframe but status is pending - regenerate with encoding overlay.
						$player_html = $this->player_renderer->get_player_html( $video_id, $provider, 'pending' );
						// Note: player_html already includes wrapper - no need to wrap again.
						$data['feed']['meta']['media_preview']['html'] = $player_html;
						error_log( '[FCHub Stream] Updated media_preview HTML for pending video (removed iframe) in post ID: ' . ( $data['feed']['id'] ?? 'unknown' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

						// Note: We don't check video_exists() for pending videos because:
						// 1. Video may exist but not be ready yet (encoding in progress).
						// 2. API may return 404/500 temporarily during encoding.
						// 3. We trust the status field - if it's pending, show encoding overlay.
					}
				}
			}

			// Only process if feed exists and has message_rendered.
			if ( isset( $data['feed']['message_rendered'] ) && is_string( $data['feed']['message_rendered'] ) ) {
				if ( strpos( $data['feed']['message_rendered'], '[fchub_stream:' ) !== false ) {
					$data['feed']['message_rendered'] = $this->player_renderer->replace_shortcodes_with_player( $data['feed']['message_rendered'] );
				}
			}
		}

		return $data;
	}

	/**
	 * Process shortcodes in feeds list API response
	 *
	 * Handles both Collection and array responses from FluentCommunity.
	 * Validates video existence and replaces shortcodes with player HTML.
	 * Only processes feeds endpoint, skips chat and other endpoints.
	 *
	 * @param array $data API response data.
	 * @param array $with Additional data requested (unused).
	 * @return array Modified API response.
	 */
	public function process_shortcodes_in_feeds( $data, $with ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Early return if data is not an array or is null.
		if ( ! is_array( $data ) || is_null( $data ) ) {
			return $data;
		}

		// CRITICAL: Only process feeds endpoint structure - skip chat and other endpoints.
		// Chat returns ['messages' => ...], not ['feeds' => ...].
		if ( ! isset( $data['feeds'] ) ) {
			// This is not a feeds endpoint (might be activities, chat, etc.) - return unchanged.
			return $data;
		}

		// Wrap in try-catch to prevent any errors from breaking the API response.
		try {
			// Preserve all original keys (sticky, execution_time, last_fetched_timestamp, etc.)
			// Only modify feeds.data if it exists and is accessible.
			if ( isset( $data['feeds']['data'] ) ) {
				$feeds_data = $data['feeds']['data'];

				// CRITICAL FIX: Only modify Collection if there are shortcodes to process.
				// Don't create new Collection instance unless necessary to preserve all properties.
				if ( is_object( $feeds_data ) && method_exists( $feeds_data, 'map' ) && method_exists( $feeds_data, 'toArray' ) ) {
					// It's a Collection - first check if any feed has shortcode WITHOUT calling toArray().
					// Using toArray() might modify Collection's internal state.
					$has_shortcode = false;

					// Use each() to iterate without converting to array.
					$feeds_data->each(
						function ( $feed ) use ( &$has_shortcode ) {
							if ( $has_shortcode ) {
									return; // Already found shortcode, skip rest.
							}

							$message_rendered = null;
							if ( is_object( $feed ) ) {
								$message_rendered = $feed->message_rendered ?? null;
							} elseif ( is_array( $feed ) ) {
								$message_rendered = $feed['message_rendered'] ?? null;
							}

							if ( $message_rendered && is_string( $message_rendered ) && strpos( $message_rendered, '[fchub_stream:' ) !== false ) {
								$has_shortcode = true;
							}
						}
					);

					// Process Collection - validate videos and process shortcodes.
					$data['feeds']['data'] = $feeds_data->map(
						function ( $feed ) {
							// Convert to array for easier manipulation (FluentCommunity returns Collection of models).
							$feed_array = is_object( $feed ) ? $feed->toArray() : $feed;
							$feed_id    = $feed_array['id'] ?? 'unknown';

							// Validate video exists if meta has video_id.
							if ( isset( $feed_array['meta']['media_preview']['video_id'] ) ) {
								$video_id = $feed_array['meta']['media_preview']['video_id'];
								$provider = $feed_array['meta']['media_preview']['provider'] ?? null;
								$status   = $feed_array['meta']['media_preview']['status'] ?? null;
								$html     = $feed_array['meta']['media_preview']['html'] ?? '';

								// Check if video exists in provider (only if provider is our stream provider).
								if ( in_array( $provider, array( 'cloudflare_stream', 'bunny_stream' ), true ) ) {
									// If status is 'ready', ensure HTML is updated (not encoding overlay).
									if ( 'ready' === $status ) {
										// Check if HTML still contains encoding overlay (old HTML).
										if ( strpos( $html, 'fchub-stream-encoding' ) !== false || strpos( $html, 'Encoding video...' ) !== false ) {
											// Regenerate HTML with ready status (no encoding overlay).
											$player_html = $this->player_renderer->get_player_html( $video_id, $provider, 'ready' );
											// Note: player_html already includes wrapper - no need to wrap again.
											$feed_array['meta']['media_preview']['html'] = $player_html;
											error_log( '[FCHub Stream] Updated media_preview HTML for ready video in post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

											// CRITICAL: Update database so next page load shows iframe immediately.
											// This is fallback when polling didn't update (e.g., webhook came during page load).
											$this->update_feed_meta_in_db( $feed_id, $feed_array['meta'] );
										}
									} elseif ( strpos( $html, '<iframe' ) !== false && strpos( $html, 'fchub-stream-encoding' ) === false ) {
										// If status is not 'ready', ensure HTML shows encoding overlay (not iframe).
										// HTML contains iframe but status is pending - regenerate with encoding overlay.
										$player_html = $this->player_renderer->get_player_html( $video_id, $provider, 'pending' );
										// Note: player_html already includes wrapper - no need to wrap again.
										$feed_array['meta']['media_preview']['html'] = $player_html;
										error_log( '[FCHub Stream] Updated media_preview HTML for pending video (removed iframe) in post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

										// Note: We don't check video_exists() for pending videos because:
										// 1. Video may exist but not be ready yet (encoding in progress).
										// 2. API may return 404/500 temporarily during encoding.
										// 3. We trust the status field - if it's pending, show encoding overlay.
									}
								}
							}

							// Process shortcodes in message_rendered.
							if ( isset( $feed_array['message_rendered'] ) && is_string( $feed_array['message_rendered'] ) ) {
								if ( strpos( $feed_array['message_rendered'], '[fchub_stream:' ) !== false ) {
									$feed_array['message_rendered'] = $this->player_renderer->replace_shortcodes_with_player( $feed_array['message_rendered'] );
								}
							}

							// Return as array (FluentCommunity will handle conversion if needed).
							return $feed_array;
						}
					);
					// If no shortcode found, leave Collection unchanged (don't create new instance).
				} elseif ( is_array( $feeds_data ) ) {
					// It's already an array - process normally.
					$modified = false;
					foreach ( $feeds_data as $key => $feed ) {
						// Validate video exists if meta has video_id.
						if ( isset( $feed['meta']['media_preview']['video_id'] ) ) {
							$video_id = $feed['meta']['media_preview']['video_id'];
							$provider = $feed['meta']['media_preview']['provider'] ?? null;
							$status   = $feed['meta']['media_preview']['status'] ?? null;
							$html     = $feed['meta']['media_preview']['html'] ?? '';

							// Check if video exists in provider (only if provider is our stream provider).
							if ( in_array( $provider, array( 'cloudflare_stream', 'bunny_stream' ), true ) ) {
								// If status is 'ready', ensure HTML is updated (not encoding overlay).
								if ( 'ready' === $status ) {
									// Check if HTML still contains encoding overlay (old HTML).
									if ( strpos( $html, 'fchub-stream-encoding' ) !== false || strpos( $html, 'Encoding video...' ) !== false ) {
										// Regenerate HTML with ready status (no encoding overlay).
										$player_html = $this->player_renderer->get_player_html( $video_id, $provider, 'ready' );
										// Note: player_html already includes wrapper - no need to wrap again.
										$feeds_data[ $key ]['meta']['media_preview']['html'] = $player_html;
										$feed_id = $feed['id'] ?? 'unknown';
										error_log( '[FCHub Stream] Updated media_preview HTML for ready video in post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
										$modified = true;

										// CRITICAL: Update database so next page load shows iframe immediately.
										// This is fallback when polling didn't update (e.g., webhook came during page load).
										$this->update_feed_meta_in_db( $feed_id, $feeds_data[ $key ]['meta'] );
									}
								} elseif ( strpos( $html, '<iframe' ) !== false && strpos( $html, 'fchub-stream-encoding' ) === false ) {
									// If status is not 'ready', ensure HTML shows encoding overlay (not iframe).
									// HTML contains iframe but status is pending - regenerate with encoding overlay.
									$player_html = $this->player_renderer->get_player_html( $video_id, $provider, 'pending' );
									// Note: player_html already includes wrapper - no need to wrap again.
									$feeds_data[ $key ]['meta']['media_preview']['html'] = $player_html;
									$feed_id = $feed['id'] ?? 'unknown';
									error_log( '[FCHub Stream] Updated media_preview HTML for pending video (removed iframe) in post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
									$modified = true;

									// Note: We don't check video_exists() for pending videos because:
									// 1. Video may exist but not be ready yet (encoding in progress).
									// 2. API may return 404/500 temporarily during encoding.
									// 3. We trust the status field - if it's pending, show encoding overlay.
								}
							}
						}

						if ( isset( $feed['message_rendered'] ) && is_string( $feed['message_rendered'] ) ) {
							if ( strpos( $feed['message_rendered'], '[fchub_stream:' ) !== false ) {
								$feeds_data[ $key ]['message_rendered'] = $this->player_renderer->replace_shortcodes_with_player( $feed['message_rendered'] );
								$modified                               = true;
							}
						}
					}

					if ( $modified ) {
						$data['feeds']['data'] = $feeds_data;
					}
				}
				// If unknown type, don't modify - return unchanged.
			}

			// Note: We don't add missing keys like 'sticky', 'activities', etc.
			// because FluentCommunity FeedsController already sets them appropriately.
			// Adding them here could cause issues if they're not expected.
		} catch ( \Exception $e ) {
			// Log error but return original data unchanged to prevent breaking the API.
			error_log( 'FCHub Stream: Error processing feeds API response: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $data;
	}

	/**
	 * Handle feed updated event (GAP #7, GAP #11)
	 *
	 * Called after FluentCommunity updates a feed.
	 * Clears cache and emits JavaScript event to frontend for UI refresh.
	 *
	 * Enhanced in Phase 2 (GAP #11) with:
	 * - Meta validation to ensure data integrity
	 * - Comparison to detect actual changes
	 * - Enhanced logging with structured context
	 * - Error recovery mechanism
	 *
	 * @since 2.1.0
	 *
	 * @param object $feed  Updated feed object.
	 * @param array  $dirty Array of changed fields.
	 *
	 * @return void
	 */
	public function handle_feed_updated( $feed, $dirty ) {
		if ( ! $feed || ! isset( $feed->id ) ) {
			error_log( '[FCHub Stream] handle_feed_updated() - ERROR: Invalid feed object' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$feed_id         = absint( $feed->id );
		$changed_keys    = array_keys( $dirty );
		$has_meta_change = in_array( 'meta', $changed_keys, true );

		error_log( '[FCHub Stream] handle_feed_updated() - Feed ID: ' . $feed_id . ' | Changed fields: ' . implode( ', ', $changed_keys ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// GAP #11: Validate meta data if video exists.
		$meta           = $feed->meta ?? array();
		$has_video_meta = isset( $meta['media_preview']['video_id'] ) &&
						isset( $meta['media_preview']['provider'] );

		if ( $has_video_meta ) {
			$video_id = $meta['media_preview']['video_id'] ?? '';
			$provider = $meta['media_preview']['provider'] ?? '';
			$status   = $meta['media_preview']['status'] ?? 'unknown';

			// Validate video data integrity.
			if ( empty( $video_id ) || empty( $provider ) ) {
				error_log( '[FCHub Stream] handle_feed_updated() - WARNING: Invalid video meta data (empty video_id or provider) for feed ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( '[FCHub Stream] handle_feed_updated() - Video meta validated: ID=' . $video_id . ', Provider=' . $provider . ', Status=' . $status ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// GAP #11: Clear WordPress object cache for this feed.
		// Multiple cache groups to ensure full invalidation.
		wp_cache_delete( 'feed_' . $feed_id, 'fcom' );
		wp_cache_delete( 'fcom_feed_' . $feed_id, 'fcom' );

		// GAP #11: Only emit event if meta actually changed or if video exists.
		// This prevents unnecessary frontend refreshes.
		$should_emit_event = $has_meta_change || $has_video_meta;

		if ( ! $should_emit_event ) {
			error_log( '[FCHub Stream] handle_feed_updated() - No meta changes and no video, skipping event emission for feed ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// Emit JavaScript event to frontend (via inline script in wp_footer).
		// This will be picked up by portal-app event listeners for UI refresh.
		add_action(
			'wp_footer',
			function () use ( $feed_id, $meta, $has_video_meta ) {
				?>
				<script type="text/javascript">
				(function() {
					if (typeof window !== 'undefined') {
						try {
							window.dispatchEvent(new CustomEvent('fluent_community/feed/updated', {
								detail: {
									feed_id: <?php echo absint( $feed_id ); ?>,
									meta: <?php echo wp_json_encode( $meta ); ?>,
									has_video: <?php echo $has_video_meta ? 'true' : 'false'; ?>
								}
							}));
							console.log('[FCHub Stream] Emitted feed updated event for feed ID:', <?php echo absint( $feed_id ); ?>, 'Has video:', <?php echo $has_video_meta ? 'true' : 'false'; ?>);
						} catch (e) {
							console.error('[FCHub Stream] Failed to emit feed updated event:', e);
						}
					}
				})();
				</script>
				<?php
			},
			999
		);
	}

	/**
	 * Update feed meta in database (GAP #11 Enhanced)
	 *
	 * Helper method to update post/comment meta in database.
	 * Used to persist HTML changes so next page load doesn't show encoding overlay.
	 *
	 * Enhanced in Phase 2 (GAP #11) with:
	 * - Input validation
	 * - Database error handling with context
	 * - Cache invalidation after successful update
	 * - Structured error logging
	 *
	 * @since 2.0.0
	 * @access private
	 *
	 * @param int   $feed_id Feed/post ID.
	 * @param array $meta    Updated meta array.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function update_feed_meta_in_db( $feed_id, $meta ) {
		global $wpdb;

		// GAP #11: Validate input parameters.
		if ( ! is_numeric( $feed_id ) || $feed_id <= 0 ) {
			error_log( '[FCHub Stream] update_feed_meta_in_db() - ERROR: Invalid feed_id: ' . var_export( $feed_id, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return false;
		}

		if ( ! is_array( $meta ) ) {
			error_log( '[FCHub Stream] update_feed_meta_in_db() - ERROR: Invalid meta (not an array) for feed ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		// Check if already updated (prevent multiple writes per request).
		static $updated_feeds = array();

		if ( isset( $updated_feeds[ $feed_id ] ) ) {
			error_log( '[FCHub Stream] update_feed_meta_in_db() - Skipping duplicate update for feed ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return true; // Already updated this request.
		}

		// GAP #11: Serialize and validate before DB write.
		$serialized_meta = maybe_serialize( $meta );
		$meta_size       = strlen( $serialized_meta );

		// Log meta size for monitoring (warn if large).
		if ( $meta_size > 50000 ) { // 50KB threshold.
			error_log( '[FCHub Stream] update_feed_meta_in_db() - WARNING: Large meta size (' . $meta_size . ' bytes) for feed ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Update database - Direct query required for FluentCommunity custom table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			$wpdb->prefix . 'fcom_posts',
			array( 'meta' => $serialized_meta ),
			array( 'id' => $feed_id ),
			array( '%s' ),
			array( '%d' )
		);

		// GAP #11: Enhanced error handling with context.
		if ( false === $updated ) {
			// Database error occurred.
			$db_error = $wpdb->last_error;
			error_log( '[FCHub Stream] update_feed_meta_in_db() - FAILED: Database error for feed ' . $feed_id . ' | Error: ' . $db_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		// Success - mark as updated and clear cache.
		$updated_feeds[ $feed_id ] = true;

		// GAP #11: Clear cache after successful DB update to ensure consistency.
		wp_cache_delete( 'feed_' . $feed_id, 'fcom' );
		wp_cache_delete( 'fcom_feed_' . $feed_id, 'fcom' );

		error_log( '[FCHub Stream] update_feed_meta_in_db() - SUCCESS: Updated feed ' . $feed_id . ' (' . $updated . ' row affected, ' . $meta_size . ' bytes)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return true;
	}
}
