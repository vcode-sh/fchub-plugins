<?php
/**
 * FluentCommunity Portal Asset Manager
 *
 * Handles asset loading (JavaScript and CSS) for the FluentCommunity Portal integration.
 * Manages cache busting, inline CSS injection, and video MIME type registration.
 *
 * @package FCHubStream
 * @subpackage Hooks\PortalIntegration
 * @since 1.0.0
 */

namespace FCHubStream\App\Hooks\PortalIntegration;

/**
 * Class AssetManager
 *
 * Manages portal assets including JavaScript files, inline CSS, and video MIME types.
 */
class AssetManager {

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register(): void {
		// Register video MIME types support.
		add_filter( 'fluent_community/support_attachment_types', array( $this, 'add_video_types' ), 1 );

		// Add portal scripts and CSS files.
		add_filter( 'fluent_community/portal_data_vars', array( $this, 'add_portal_scripts' ), 10, 1 );

		// Register CSS output hooks early (not inside add_portal_scripts to ensure they're always registered).
		add_action( 'fluent_community/portal_head', array( $this, 'add_portal_css' ) );
		add_action( 'wp_head', array( $this, 'add_portal_css' ) ); // Fallback for admin.
	}

	/**
	 * Add Portal scripts and CSS files.
	 *
	 * Adds JavaScript files with cache busting and registers CSS output hooks.
	 * Portal CSS hooks are added inside this method to ensure they fire at the right time.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data_vars Portal data vars.
	 * @return array Modified data vars.
	 */
	public function add_portal_scripts( array $data_vars ): array {
		$portal_js = FCHUB_STREAM_DIR . 'portal-app/dist/fchub-stream-portal.js';

		// Add JavaScript with cache busting.
		if ( file_exists( $portal_js ) ) {
			$file_time                                    = filemtime( $portal_js );
			$data_vars['js_files']['fchub_stream_portal'] = array(
				'url'  => FCHUB_STREAM_URL . 'portal-app/dist/fchub-stream-portal.js?v=' . $file_time,
				'deps' => array(),
			);
		}

		// Note: CSS hooks are registered in register() method to ensure they're always available.

		return $data_vars;
	}

	/**
	 * Add portal CSS for video player styling.
	 *
	 * Outputs inline CSS for video player styling, including fixes for feed media margins,
	 * comment overflow issues, and responsive video embedding.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_portal_css(): void {
		echo '<style>
				/* Video Player Wrapper - Base styling */
				.fchub-stream-player-wrapper {
					margin: 0 !important;
				}

				/* Fix FluentCommunity feed_media margin for video iframes - only in comments */
				.comment_text_media .feed_media .fchub-stream-player-wrapper,
				.comment_wrap .feed_media .fchub-stream-player-wrapper {
					margin: 0 !important;
				}

				/* Fix .fcom_top_media: remove gap from feed padding-top and add rounded top corners */
				.fcom_top_media {
					margin-top: -16px !important; /* Remove gap from feed padding-top: 16px */
					margin-left: -20px !important; /* Extend to feed edges */
					margin-right: -20px !important; /* Extend to feed edges */
					border-radius: 10px 10px 0 0 !important; /* Match feed card border-radius on top */
					overflow: hidden !important; /* Clip video to rounded corners */
				}

				/* CRITICAL FIX: Reset negative margins for YouTube oembed and our video iframes - FluentCommunity already has its own negative margins */
				/* Without this, YouTube videos and our videos get double negative margins (ours + FluentCommunity) and overflow by ~78px */
				.fcom_top_media .feed_media_oembed,
				.fcom_top_media .feed_media.feed_media_oembed,
				.fcom_top_media .feed_media_iframe_html,
				.fcom_top_media .feed_media.feed_media_iframe_html {
					margin-left: 0 !important;
					margin-right: 0 !important;
					margin-top: 0 !important;
				}

				/* CRITICAL: Fix video overflow in comments - limit width to parent container */
				/* Target all possible comment containers */
				.comment_text_media .fchub-stream-player-wrapper,
				.comment_text_media > .fchub-stream-player-wrapper,
				.each_comment .fchub-stream-player-wrapper,
				.each_comment > .fchub-stream-player-wrapper,
				.comment_wrap .fchub-stream-player-wrapper,
				.comment_wrap > .fchub-stream-player-wrapper,
				.feed_comment .fchub-stream-player-wrapper,
				.feed_comment > .fchub-stream-player-wrapper {
					max-width: 100% !important;
					width: 100% !important;
					box-sizing: border-box !important;
					border-radius: 8px !important;
				}

				/* Ensure iframe inside comment wrapper respects container width */
				.comment_text_media .fchub-stream-player-wrapper iframe,
				.each_comment .fchub-stream-player-wrapper iframe,
				.comment_wrap .fchub-stream-player-wrapper iframe,
				.feed_comment .fchub-stream-player-wrapper iframe {
					max-width: 100% !important;
					width: 100% !important;
				}

				/* Fix for padding-bottom aspect ratio trick - ensure parent limits width */
				.comment_text_media,
				.comment_wrap {
					overflow-x: hidden !important;
					max-width: 100% !important;
				}

				/* CRITICAL: Fix feed_media_iframe_html negative margin that causes overflow */
				/* ONLY in comments - keep negative margins in main posts for full width */
				.comment_text_media .feed_media_iframe_html,
				.comment_wrap .feed_media_iframe_html,
				.each_comment .feed_media_iframe_html,
				.feed_comment .feed_media_iframe_html {
					margin-left: 0 !important;
					margin-right: 0 !important;
					max-width: 100% !important;
					width: 100% !important;
				}

				/* Fix feed_media_ext_video inside comments */
				.comment_text_media .feed_media_ext_video,
				.comment_wrap .feed_media_ext_video {
					max-width: 100% !important;
					width: 100% !important;
				}

				/* Force wrapper to respect parent width even with padding-bottom trick */
				.comment_wrap .fchub-stream-player-wrapper[style*="padding-bottom"],
				.comment_text_media .fchub-stream-player-wrapper[style*="padding-bottom"] {
					max-width: 100% !important;
					width: 100% !important;
				}

				/* Comment Video Notification Styles */
				.fchub-stream-comment-notification {
					padding: 12px;
					background: #f0fdf4;
					border: 1px solid #86efac;
					border-radius: 6px;
					margin: 12px 0;
				}

				.fchub-stream-comment-notification-header {
					display: flex;
					align-items: center;
					gap: 8px;
					margin-bottom: 8px;
				}

				.fchub-stream-comment-notification-icon {
					width: 20px;
					height: 20px;
					color: #16a34a;
				}

				.fchub-stream-comment-notification-title {
					color: #166534;
					font-weight: 500;
					font-size: 14px;
				}

				.fchub-stream-comment-notification-message {
					color: #15803d;
					font-size: 13px;
					margin: 0 0 8px 28px;
				}

				.fchub-stream-comment-notification-remove {
					margin-left: 28px;
					padding: 4px 12px;
					background: white;
					border: 1px solid #86efac;
					color: #dc2626;
					border-radius: 4px;
					font-size: 13px;
					cursor: pointer;
					transition: all 0.2s ease;
				}

				.fchub-stream-comment-notification-remove:hover {
					background: #fee2e2;
					border-color: #dc2626;
				}

				/* Mobile Responsive Adjustments */
				@media (max-width: 768px) {
					.fchub-stream-comment-notification {
						padding: 10px;
						margin: 10px 0;
					}

					.fchub-stream-comment-notification-title {
						font-size: 13px;
					}

					.fchub-stream-comment-notification-message {
						font-size: 12px;
						margin-left: 24px;
					}

					.fchub-stream-comment-notification-remove {
						margin-left: 24px;
						font-size: 12px;
					}
				}

				/* CRITICAL: Hide "Remove Media" link in edit feed dialogs when FCHub Stream video exists */
				/* Prevents accidental video removal during post editing */
				.el-dialog.fcom_feed_edit_dialog .feed_media_iframe_html a[href*="remove"],
				.el-dialog.fcom_feed_edit_dialog .feed_media_iframe_html .remove-media,
				.el-dialog.fcom_feed_edit_dialog .feed_media_iframe_html button[class*="remove"],
				.el-dialog.fcom_feed_modal .feed_media_iframe_html a[href*="remove"],
				.el-dialog.fcom_feed_modal .feed_media_iframe_html .remove-media,
				.el-dialog.fcom_feed_modal .feed_media_iframe_html button[class*="remove"],
				.el-dialog[data-feed-id] .feed_media_iframe_html a[href*="remove"],
				.el-dialog[data-feed-id] .feed_media_iframe_html .remove-media,
				.el-dialog[data-feed-id] .feed_media_iframe_html button[class*="remove"] {
					display: none !important;
				}

				/* Hide "Remove Media" text/link specifically for FCHub Stream videos */
				.el-dialog.fcom_feed_edit_dialog iframe[src*="cloudflarestream.com"] ~ a,
				.el-dialog.fcom_feed_edit_dialog iframe[src*="cloudflarestream.com"] ~ .remove-media,
				.el-dialog.fcom_feed_edit_dialog iframe[src*="bunny.net"] ~ a,
				.el-dialog.fcom_feed_edit_dialog iframe[src*="bunny.net"] ~ .remove-media,
				.el-dialog.fcom_feed_modal iframe[src*="cloudflarestream.com"] ~ a,
				.el-dialog.fcom_feed_modal iframe[src*="cloudflarestream.com"] ~ .remove-media,
				.el-dialog.fcom_feed_modal iframe[src*="bunny.net"] ~ a,
				.el-dialog.fcom_feed_modal iframe[src*="bunny.net"] ~ .remove-media,
				.el-dialog[data-feed-id] iframe[src*="cloudflarestream.com"] ~ a,
				.el-dialog[data-feed-id] iframe[src*="cloudflarestream.com"] ~ .remove-media,
				.el-dialog[data-feed-id] iframe[src*="bunny.net"] ~ a,
				.el-dialog[data-feed-id] iframe[src*="bunny.net"] ~ .remove-media {
					display: none !important;
				}
			</style>';
	}

	/**
	 * Add video MIME types to supported attachment types.
	 *
	 * Registers video MIME types that FluentCommunity should accept for attachments.
	 *
	 * @since 1.0.0
	 *
	 * @param array $types Supported attachment types.
	 * @return array Modified types.
	 */
	public function add_video_types( array $types ): array {
		$types[] = 'video/mp4';
		$types[] = 'video/quicktime';
		$types[] = 'video/webm';
		$types[] = 'video/x-msvideo';

		return $types;
	}
}
