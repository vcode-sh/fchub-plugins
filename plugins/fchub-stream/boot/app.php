<?php
/**
 * Plugin bootstrap file.
 *
 * This file returns a callable that initializes the FCHub Stream plugin.
 * It handles:
 * - Loading the Composer autoloader
 * - Hooking into FluentCommunity
 * - Registering activation/deactivation hooks
 *
 * @package FCHub_Stream
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FCHubStream\App\Core\Application;
use function FCHubStream\App\Utils\log_debug;

/**
 * Bootstrap the plugin.
 *
 * This function is returned and executed by the main plugin file.
 * It sets up all necessary hooks and initializes the application.
 *
 * @since 1.0.0
 *
 * @param string $file The main plugin file path.
 *
 * @return void
 */
return function ( $file ) {
	// Ensure autoloader is loaded before registering hooks.
	if ( file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
		require_once __DIR__ . '/../vendor/autoload.php';
	}

	/**
	 * Register video deletion hooks EARLY (before portal loads).
	 *
	 * CRITICAL: These hooks must be registered immediately because they can be
	 * triggered during post deletion, which happens outside the portal context.
	 * If we wait for 'fluent_community/portal_loaded', the hooks won't be registered
	 * when posts are deleted from admin or API.
	 *
	 * @since 1.0.0
	 */
	add_action(
		'plugins_loaded',
		function () {
			// Register video deletion handler immediately (don't wait for portal).
			if ( class_exists( 'FCHubStream\App\Hooks\Handlers\VideoDeletionHandler' ) ) {
				$video_deletion_handler = new \FCHubStream\App\Hooks\Handlers\VideoDeletionHandler();

				// Hook into feed deletion.
				// Priority 5 to run BEFORE CleanupHandler (priority 10) so we can handle comments with videos.
				add_action(
					'fluent_community/feed/before_deleted',
					array( $video_deletion_handler, 'handle_feed_deleted' ),
					5, // Run BEFORE CleanupHandler (priority 10).
					1
				);

				// Hook into comment deletion (for video comments).
				add_action(
					'fluent_community/before_comment_delete',
					array( $video_deletion_handler, 'handle_comment_deleted' ),
					10,
					1
				);

				// Hook into feed update filter to capture old meta before update.
				add_filter(
					'fluent_community/feed/update_data',
					array( $video_deletion_handler, 'capture_feed_meta_before_update' ),
					10,
					2
				);

				// Hook into feed update action to detect and delete removed videos.
				add_action(
					'fluent_community/feed/updated',
					array( $video_deletion_handler, 'handle_feed_updated' ),
					10,
					2
				);

				// Hook into comment update filter to capture old meta before update.
				add_filter(
					'fluent_community/comment/update_comment_data',
					array( $video_deletion_handler, 'capture_comment_meta_before_update' ),
					10,
					3
				);

				// Hook into comment update action to detect and delete removed videos.
				add_action(
					'fluent_community/comment_updated',
					array( $video_deletion_handler, 'handle_comment_updated' ),
					10,
					2
				);

				log_debug( 'Video deletion hooks registered early (plugins_loaded)' );
			}

			// CRITICAL: Register portal vars hook EARLY (before portal_loaded).
			// Hook fluent_community/portal_vars may be called BEFORE fluent_community/portal_loaded,
			// so we must register it early to ensure settings are provided correctly.
			if ( class_exists( 'FCHubStream\App\Hooks\PortalIntegration\ConfigProvider' ) ) {
				$config_provider = new \FCHubStream\App\Hooks\PortalIntegration\ConfigProvider();
				$config_provider->register();
			}
		},
		20 // Priority 20 to ensure FluentCommunity classes are loaded.
	);

	/**
	 * Hook into FluentCommunity when it's fully loaded.
	 *
	 * NOTE: FluentCommunity plugin MUST be active for FCHub Stream to work.
	 * This action is fired after FluentCommunity portal is fully initialized.
	 *
	 * @since 1.0.0
	 */
	add_action(
		'fluent_community/portal_loaded',
		function ( $app ) use ( $file ) {
			// Initialize FCHub Stream Application.
			new Application( $app, $file );
		}
	);

	/**
	 * Register activation hook for database table creation.
	 *
	 * Handles plugin activation, including:
	 * - Creating database tables
	 * - Setting default options
	 * - Running any necessary setup
	 *
	 * @since 1.0.0
	 */
	register_activation_hook(
		$file,
		function ( $network_wide = false ) {
			// Ensure autoloader is loaded.
			if ( file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
				require_once __DIR__ . '/../vendor/autoload.php';
			}
			\FCHubStream\App\Hooks\Handlers\ActivationHandler::handle( $network_wide );
		}
	);

	/**
	 * Register deactivation hook for cleanup.
	 *
	 * Handles plugin deactivation cleanup tasks if needed.
	 *
	 * @since 1.0.0
	 */
	register_deactivation_hook(
		$file,
		function ( $network_wide = false ) {
			// Ensure autoloader is loaded.
			if ( file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
				require_once __DIR__ . '/../vendor/autoload.php';
			}
			\FCHubStream\App\Hooks\Handlers\DeactivationHandler::handle( $network_wide );
		}
	);
};
