<?php
/**
 * Video deletion handler.
 *
 * Handles automatic video deletion from Cloudflare Stream/Bunny.net when
 * posts or comments are deleted in FluentCommunity. Ensures proper cleanup
 * of video resources while preventing video deletion failures from blocking
 * post/comment deletions.
 *
 * @package FCHub_Stream
 * @subpackage Hooks\Handlers
 * @since 1.0.0
 */

namespace FCHubStream\App\Hooks\Handlers;

use FCHubStream\App\Services\CloudflareApiService;
use FCHubStream\App\Services\BunnyApiService;
use FCHubStream\App\Services\StreamConfigService;

/**
 * Class VideoDeletionHandler
 *
 * Handles automatic video deletion when posts or comments with videos are deleted
 * or updated (when video is removed during edit). Integrates with FluentCommunity
 * hooks to ensure videos are cleaned up from external providers (Cloudflare Stream
 * or Bunny.net).
 *
 * Supports three scenarios:
 * 1. Post/Comment deletion - deletes video when post/comment is deleted
 * 2. Post/Comment update with video removal - detects when video is removed during edit
 * 3. Post/Comment update with video replacement - deletes old video when replaced with new one
 *
 * @package FCHub_Stream
 * @subpackage Hooks\Handlers
 * @since 1.0.0
 */
class VideoDeletionHandler {
	/**
	 * Temporary storage for old meta before feed/comment updates.
	 * Used to compare old vs new meta and detect video removal.
	 *
	 * @since 1.0.0
	 * @var array<string, array> Key: feed/comment ID, Value: old meta array.
	 */
	private static $old_meta_cache = array();

	/**
	 * Handle feed deletion - delete associated video
	 *
	 * Called when a feed (post) is about to be deleted. Extracts video information
	 * from post meta and initiates video deletion from the appropriate provider.
	 *
	 * @since 1.0.0
	 *
	 * @param object $feed Feed object (FluentCommunity Feed model).
	 *
	 * @return void
	 */
	public function handle_feed_deleted( $feed ) {
		// CRITICAL: Log immediately to verify hook is called.
		error_log( '[FCHub Stream] ===== VIDEO DELETION HOOK CALLED =====' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[FCHub Stream] Feed object type: ' . gettype( $feed ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[FCHub Stream] Feed class: ' . ( is_object( $feed ) ? get_class( $feed ) : 'not object' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		$feed_id = isset( $feed->id ) ? $feed->id : 'unknown';
		error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - START | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// CRITICAL: Handle comments with videos BEFORE post deletion.
		// CleanupHandler deletes comments with $feed->comments()->delete() which doesn't fire hooks.
		// We must iterate through comments manually and delete videos before CleanupHandler runs.
		//
		// Use direct database query to ensure we get ALL comments, including nested ones,
		// regardless of any global scopes or relationship constraints.
		try {
			// Import Comment model class.
			if ( class_exists( '\FluentCommunity\App\Models\Comment' ) ) {
				$comments = \FluentCommunity\App\Models\Comment::where( 'post_id', $feed_id )
					->withoutGlobalScopes()
					->get();

				if ( ! $comments->isEmpty() ) {
					error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - Found ' . $comments->count() . ' comments, checking for videos...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

					$comments_with_video = 0;
					foreach ( $comments as $comment ) {
						// Check if comment has video before processing.
						$comment_meta = $comment->meta ?? array();
						if ( isset( $comment_meta['media_preview']['video_id'] ) ) {
							++$comments_with_video;
							error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - Comment #' . $comment->id . ' has video, deleting...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}

						// Always call handle_comment_deleted() - it will skip if no video.
						$this->handle_comment_deleted( $comment );
					}

					error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - Processed ' . $comments->count() . ' comments, ' . $comments_with_video . ' had videos' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				} else {
					error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - No comments found for post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			} else {
				error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - WARNING: Comment model class not found, skipping comment video deletion' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		} catch ( \Exception $e ) {
			// Log error but don't fail post deletion.
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - ERROR processing comments: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Get meta from feed.
		// Feed model has getMetaAttribute() accessor that unserializes JSON automatically.
		$meta = $feed->meta; // Returns array (already unserialized).

		error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - Meta retrieved | Post ID: ' . $feed_id . ' | Has meta: ' . ( ! empty( $meta ) ? 'yes' : 'no' ) . ' | Has media_preview: ' . ( isset( $meta['media_preview'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( isset( $meta['media_preview'] ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - Media preview structure: ' . wp_json_encode( $meta['media_preview'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Check if meta.media_preview exists and has video_id.
		if ( ! isset( $meta['media_preview']['video_id'] ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - SKIPPED | No video_id found in post meta | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// Extract video_id and provider.
		$video_id = $meta['media_preview']['video_id'];
		$provider = $meta['media_preview']['provider'] ?? null;

		error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - Video data extracted | Post ID: ' . $feed_id . ' | Video ID: ' . $video_id . ' | Provider: ' . ( $provider ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( empty( $provider ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - SKIPPED | No provider found in post meta | Post ID: ' . $feed_id . ' | Video ID: ' . $video_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - Calling delete_video_from_provider() | Post ID: ' . $feed_id . ' | Video ID: ' . $video_id . ' | Provider: ' . $provider ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Call delete_video_from_provider().
		$success = $this->delete_video_from_provider( $video_id, $provider );

		// Log result (success or error).
		if ( $success ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - COMPLETE SUCCESS | Video ' . $video_id . ' deleted from ' . $provider . ' | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} else {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_deleted() - COMPLETE FAILED | Video ' . $video_id . ' deletion failed from ' . $provider . ' | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Handle comment deletion - delete associated video
	 *
	 * Called when a comment is about to be deleted. Extracts video information
	 * from comment meta and initiates video deletion from the appropriate provider.
	 *
	 * @since 1.0.0
	 *
	 * @param object $comment Comment object (FluentCommunity Comment model).
	 *
	 * @return void
	 */
	public function handle_comment_deleted( $comment ) {
		$comment_id = isset( $comment->id ) ? $comment->id : 'unknown';
		error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_deleted() - START | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Get meta from comment.
		// Comment model also has meta property with getMetaAttribute() accessor.
		$meta = $comment->meta; // Returns array (already unserialized).

		error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_deleted() - Meta retrieved | Comment ID: ' . $comment_id . ' | Has meta: ' . ( ! empty( $meta ) ? 'yes' : 'no' ) . ' | Has media_preview: ' . ( isset( $meta['media_preview'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( isset( $meta['media_preview'] ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_deleted() - Media preview structure: ' . wp_json_encode( $meta['media_preview'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Check if meta.media_preview exists and has video_id.
		if ( ! isset( $meta['media_preview']['video_id'] ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_deleted() - SKIPPED | No video_id found in comment meta | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$video_id = $meta['media_preview']['video_id'];
		$provider = $meta['media_preview']['provider'] ?? null;

		error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_deleted() - Video data extracted | Comment ID: ' . $comment_id . ' | Video ID: ' . $video_id . ' | Provider: ' . ( $provider ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( empty( $provider ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_deleted() - SKIPPED | No provider found in comment meta | Comment ID: ' . $comment_id . ' | Video ID: ' . $video_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_deleted() - Calling delete_video_from_provider() | Comment ID: ' . $comment_id . ' | Video ID: ' . $video_id . ' | Provider: ' . $provider ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Call delete_video_from_provider().
		$success = $this->delete_video_from_provider( $video_id, $provider );

		if ( $success ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_deleted() - COMPLETE SUCCESS | Video ' . $video_id . ' deleted from ' . $provider . ' | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} else {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_deleted() - COMPLETE FAILED | Video ' . $video_id . ' deletion failed from ' . $provider . ' | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Delete video from provider
	 *
	 * Handles video deletion for both Cloudflare Stream and Bunny.net providers.
	 * Instantiates the appropriate API service and calls the delete_video() method.
	 * Errors are logged but do not throw exceptions to prevent blocking post/comment deletion.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string $video_id Video ID (UID for Cloudflare, GUID for Bunny).
	 * @param string $provider Provider name ('cloudflare_stream' or 'bunny_stream').
	 *
	 * @return bool True on success, false on failure.
	 */
	private function delete_video_from_provider( $video_id, $provider ) {
		error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - START | Video ID: ' . $video_id . ' | Provider: ' . $provider ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( empty( $video_id ) || empty( $provider ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - VALIDATION FAILED | Missing video_id or provider | Video ID: ' . ( $video_id ?? 'null' ) . ' | Provider: ' . ( $provider ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		if ( 'cloudflare_stream' === $provider ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Cloudflare Stream provider detected' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Get Cloudflare config.
			$config     = StreamConfigService::get_cloudflare_config();
			$account_id = $config['account_id'] ?? '';
			$api_token  = $config['api_token'] ?? '';

			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Config retrieved | Account ID: ' . ( ! empty( $account_id ) ? $account_id : 'empty' ) . ' | Has API Token: ' . ( ! empty( $api_token ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			if ( empty( $account_id ) || empty( $api_token ) ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - CONFIG ERROR | Cloudflare credentials not configured | Account ID: ' . ( empty( $account_id ) ? 'missing' : 'present' ) . ' | API Token: ' . ( empty( $api_token ) ? 'missing' : 'present' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return false;
			}

			// Instantiate CloudflareApiService.
			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Creating CloudflareApiService instance' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$api_service = new CloudflareApiService( $account_id, $api_token );

			// Call delete_video().
			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Calling CloudflareApiService::delete_video() | Video ID: ' . $video_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$result = $api_service->delete_video( $video_id );

			if ( is_wp_error( $result ) ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Cloudflare deletion returned WP_Error | Error: ' . $result->get_error_message() . ' | Code: ' . $result->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return false;
			}

			if ( true === $result ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Cloudflare deletion SUCCESS | Video ID: ' . $video_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Cloudflare deletion returned unexpected value: ' . wp_json_encode( $result ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return $result;
		} elseif ( 'bunny_stream' === $provider ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Bunny.net Stream provider detected' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Get Bunny.net config.
			$config     = StreamConfigService::get_bunny_config();
			$library_id = $config['library_id'] ?? '';
			$api_key    = $config['api_key'] ?? '';

			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Config retrieved | Library ID: ' . ( ! empty( $library_id ) ? $library_id : 'empty' ) . ' | Has API Key: ' . ( ! empty( $api_key ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			if ( empty( $library_id ) || empty( $api_key ) ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - CONFIG ERROR | Bunny.net credentials not configured | Library ID: ' . ( empty( $library_id ) ? 'missing' : 'present' ) . ' | API Key: ' . ( empty( $api_key ) ? 'missing' : 'present' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return false;
			}

			// Instantiate BunnyApiService.
			// Constructor: (account_api_key, stream_api_key, library_id).
			// Use api_key for both parameters (consistent with VideoUploadService pattern).
			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Creating BunnyApiService instance | Library ID: ' . (int) $library_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$api_service = new BunnyApiService( $api_key, $api_key, (int) $library_id );

			// Call delete_video().
			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Calling BunnyApiService::delete_video() | Video ID: ' . $video_id . ' | Library ID: ' . (int) $library_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$result = $api_service->delete_video( $video_id, (int) $library_id, $api_key );

			if ( is_wp_error( $result ) ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Bunny.net deletion returned WP_Error | Error: ' . $result->get_error_message() . ' | Code: ' . $result->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return false;
			}

			if ( true === $result ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Bunny.net deletion SUCCESS | Video ID: ' . $video_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - Bunny.net deletion returned unexpected value: ' . wp_json_encode( $result ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return $result;
		} else {
			error_log( '[FCHub Stream] VideoDeletionHandler::delete_video_from_provider() - UNKNOWN PROVIDER | Provider: ' . $provider . ' | Supported providers: cloudflare_stream, bunny_stream' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
	}

	/**
	 * Capture feed meta before update
	 *
	 * Called via filter `fluent_community/feed/update_data` to store old meta
	 * before feed is updated. This allows us to compare old vs new meta
	 * in `handle_feed_updated()` to detect video removal.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data Data array for updating the feed.
	 * @param object $feed Existing Feed model instance (with old meta).
	 *
	 * @return array Unmodified data array.
	 */
	public function capture_feed_meta_before_update( $data, $feed ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$feed_id = isset( $feed->id ) ? $feed->id : null;

		if ( empty( $feed_id ) ) {
			return $data;
		}

		// Store old meta before update.
		$old_meta                                   = $feed->meta ?? array();
		self::$old_meta_cache[ 'feed_' . $feed_id ] = $old_meta;

		error_log( '[FCHub Stream] VideoDeletionHandler::capture_feed_meta_before_update() - Captured old meta | Post ID: ' . $feed_id . ' | Has media_preview: ' . ( isset( $old_meta['media_preview'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( isset( $old_meta['media_preview']['video_id'] ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::capture_feed_meta_before_update() - Old video_id: ' . $old_meta['media_preview']['video_id'] . ' | Provider: ' . ( $old_meta['media_preview']['provider'] ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $data;
	}

	/**
	 * Handle feed update - detect and delete removed video
	 *
	 * Called when a feed (post) is updated. Compares old meta (captured in
	 * `capture_feed_meta_before_update()`) with new meta to detect if video
	 * was removed. If video was removed, deletes it from the provider.
	 *
	 * @since 1.0.0
	 *
	 * @param object $feed        Updated feed object (with new meta).
	 * @param array  $update_data Updated data array (unused).
	 *
	 * @return void
	 */
	public function handle_feed_updated( $feed, $update_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$feed_id = isset( $feed->id ) ? $feed->id : 'unknown';
		error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - START | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Get old meta from cache.
		$cache_key = 'feed_' . $feed_id;
		$old_meta  = self::$old_meta_cache[ $cache_key ] ?? null;

		// Clear cache after use.
		unset( self::$old_meta_cache[ $cache_key ] );

		if ( null === $old_meta ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - SKIPPED | No old meta cached (feed may not have been updated via filter) | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// Get new meta from updated feed.
		$new_meta = $feed->meta ?? array();

		error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - Comparing meta | Post ID: ' . $feed_id . ' | Old has media_preview: ' . ( isset( $old_meta['media_preview'] ) ? 'yes' : 'no' ) . ' | New has media_preview: ' . ( isset( $new_meta['media_preview'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Check if old meta had a video.
		$old_video_id = $old_meta['media_preview']['video_id'] ?? null;
		$old_provider = $old_meta['media_preview']['provider'] ?? null;

		// Check if new meta has a video.
		$new_video_id = $new_meta['media_preview']['video_id'] ?? null;
		$new_provider = $new_meta['media_preview']['provider'] ?? null;

		// If old meta had a video but new meta doesn't, video was removed.
		if ( ! empty( $old_video_id ) && empty( $new_video_id ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - VIDEO REMOVED DETECTED | Post ID: ' . $feed_id . ' | Old Video ID: ' . $old_video_id . ' | Provider: ' . ( $old_provider ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			if ( empty( $old_provider ) ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - SKIPPED | No provider found in old meta | Post ID: ' . $feed_id . ' | Video ID: ' . $old_video_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return;
			}

			// Delete video from provider.
			$success = $this->delete_video_from_provider( $old_video_id, $old_provider );

			if ( $success ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - COMPLETE SUCCESS | Video ' . $old_video_id . ' deleted from ' . $old_provider . ' | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - COMPLETE FAILED | Video ' . $old_video_id . ' deletion failed from ' . $old_provider . ' | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		} elseif ( ! empty( $old_video_id ) && ! empty( $new_video_id ) && $old_video_id !== $new_video_id ) {
			// Video was replaced with a different video.
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - VIDEO REPLACED DETECTED | Post ID: ' . $feed_id . ' | Old Video ID: ' . $old_video_id . ' | New Video ID: ' . $new_video_id . ' | Provider: ' . ( $old_provider ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Check if new meta explicitly specifies which video to replace.
			$replaces_video_id = $new_meta['media_preview']['replaces_video_id'] ?? null;
			$replaces_provider = $new_meta['media_preview']['replaces_provider'] ?? null;

			// CRITICAL: Only delete old video if explicit replacement info is provided.
			// This prevents accidental deletion when button was hidden but somehow video was uploaded.
			// Frontend should prevent this, but backend adds extra safety layer.
			if ( empty( $replaces_video_id ) ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - SKIPPED DELETION | No explicit replacement info provided. Old video ID: ' . $old_video_id . ' | New video ID: ' . $new_video_id . ' | Post ID: ' . $feed_id . ' | This should be prevented by frontend UI (button hidden).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				// Don't delete old video - keep both videos to prevent data loss.
				// Admin can manually clean up if needed.
				return;
			}

			// Use explicit replacement info if available, otherwise use old video from meta comparison.
			$video_to_delete = $replaces_video_id ?? $old_video_id;
			$provider_to_use = $replaces_provider ?? $old_provider;

			if ( $replaces_video_id ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - Using explicit replacement info | Video to delete: ' . $video_to_delete . ' | Provider: ' . $provider_to_use ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			if ( ! empty( $provider_to_use ) && ! empty( $video_to_delete ) ) {
				// Delete old video from provider.
				$success = $this->delete_video_from_provider( $video_to_delete, $provider_to_use );

				if ( $success ) {
					error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - COMPLETE SUCCESS | Old video ' . $video_to_delete . ' deleted from ' . $provider_to_use . ' | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				} else {
					error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - COMPLETE FAILED | Old video ' . $video_to_delete . ' deletion failed from ' . $provider_to_use . ' | Post ID: ' . $feed_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		} else {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_feed_updated() - NO ACTION NEEDED | Post ID: ' . $feed_id . ' | Old video_id: ' . ( $old_video_id ?? 'null' ) . ' | New video_id: ' . ( $new_video_id ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Capture comment meta before update
	 *
	 * Called via filter `fluent_community/comment/update_comment_data` to store old meta
	 * before comment is updated. This allows us to compare old vs new meta
	 * in `handle_comment_updated()` to detect video removal.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data    Data array for updating the comment.
	 * @param object $comment Existing Comment model instance (with old meta).
	 * @param array  $all_data Full data array submitted by the user (unused).
	 *
	 * @return array Unmodified data array.
	 */
	public function capture_comment_meta_before_update( $data, $comment, $all_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$comment_id = isset( $comment->id ) ? $comment->id : null;

		if ( empty( $comment_id ) ) {
			return $data;
		}

		// Store old meta before update.
		$old_meta = $comment->meta ?? array();
		self::$old_meta_cache[ 'comment_' . $comment_id ] = $old_meta;

		error_log( '[FCHub Stream] VideoDeletionHandler::capture_comment_meta_before_update() - Captured old meta | Comment ID: ' . $comment_id . ' | Has media_preview: ' . ( isset( $old_meta['media_preview'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( isset( $old_meta['media_preview']['video_id'] ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::capture_comment_meta_before_update() - Old video_id: ' . $old_meta['media_preview']['video_id'] . ' | Provider: ' . ( $old_meta['media_preview']['provider'] ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $data;
	}

	/**
	 * Handle comment update - detect and delete removed video
	 *
	 * Called when a comment is updated. Compares old meta (captured in
	 * `capture_comment_meta_before_update()`) with new meta to detect if video
	 * was removed. If video was removed, deletes it from the provider.
	 *
	 * @since 1.0.0
	 *
	 * @param object $comment Updated comment object (with new meta).
	 * @param object $feed    Feed object that the comment belongs to.
	 *
	 * @return void
	 */
	public function handle_comment_updated( $comment, $feed ) {
		$comment_id = isset( $comment->id ) ? $comment->id : 'unknown';
		error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - START | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Get old meta from cache.
		$cache_key = 'comment_' . $comment_id;
		$old_meta  = self::$old_meta_cache[ $cache_key ] ?? null;

		// Clear cache after use.
		unset( self::$old_meta_cache[ $cache_key ] );

		if ( null === $old_meta ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - SKIPPED | No old meta cached (comment may not have been updated via filter) | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// Get new meta from updated comment.
		$new_meta = $comment->meta ?? array();

		error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - Comparing meta | Comment ID: ' . $comment_id . ' | Old has media_preview: ' . ( isset( $old_meta['media_preview'] ) ? 'yes' : 'no' ) . ' | New has media_preview: ' . ( isset( $new_meta['media_preview'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Check if old meta had a video.
		$old_video_id = $old_meta['media_preview']['video_id'] ?? null;
		$old_provider = $old_meta['media_preview']['provider'] ?? null;

		// Check if new meta has a video.
		$new_video_id = $new_meta['media_preview']['video_id'] ?? null;
		$new_provider = $new_meta['media_preview']['provider'] ?? null;

		// If old meta had a video but new meta doesn't, video was removed.
		if ( ! empty( $old_video_id ) && empty( $new_video_id ) ) {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - VIDEO REMOVED DETECTED | Comment ID: ' . $comment_id . ' | Old Video ID: ' . $old_video_id . ' | Provider: ' . ( $old_provider ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			if ( empty( $old_provider ) ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - SKIPPED | No provider found in old meta | Comment ID: ' . $comment_id . ' | Video ID: ' . $old_video_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return;
			}

			// Delete video from provider.
			$success = $this->delete_video_from_provider( $old_video_id, $old_provider );

			if ( $success ) {
				error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - COMPLETE SUCCESS | Video ' . $old_video_id . ' deleted from ' . $old_provider . ' | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - COMPLETE FAILED | Video ' . $old_video_id . ' deletion failed from ' . $old_provider . ' | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		} elseif ( ! empty( $old_video_id ) && ! empty( $new_video_id ) && $old_video_id !== $new_video_id ) {
			// Video was replaced with a different video.
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - VIDEO REPLACED DETECTED | Comment ID: ' . $comment_id . ' | Old Video ID: ' . $old_video_id . ' | New Video ID: ' . $new_video_id . ' | Provider: ' . ( $old_provider ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			if ( ! empty( $old_provider ) ) {
				// Delete old video from provider.
				$success = $this->delete_video_from_provider( $old_video_id, $old_provider );

				if ( $success ) {
					error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - COMPLETE SUCCESS | Old video ' . $old_video_id . ' deleted from ' . $old_provider . ' | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				} else {
					error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - COMPLETE FAILED | Old video ' . $old_video_id . ' deletion failed from ' . $old_provider . ' | Comment ID: ' . $comment_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		} else {
			error_log( '[FCHub Stream] VideoDeletionHandler::handle_comment_updated() - NO ACTION NEEDED | Comment ID: ' . $comment_id . ' | Old video_id: ' . ( $old_video_id ?? 'null' ) . ' | New video_id: ' . ( $new_video_id ?? 'null' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
