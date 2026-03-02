<?php
/**
 * WordPress action hooks for FCHub Stream.
 *
 * Registers all WordPress action hooks used by the FCHub Stream plugin.
 * Actions are hooks that allow you to insert custom code at specific
 * points during WordPress execution.
 *
 * @package FCHub_Stream
 * @subpackage Hooks
 * @since 1.0.0
 *
 * @var \FluentCommunity\Framework\Foundation\Application $app The application instance.
 */

// Register admin menu action.
$app->addAction(
	'admin_menu',
	function () {
		$admin = new \FCHubStream\App\Admin\Admin( 'fchub-stream', FCHUB_STREAM_VERSION );
		$admin->add_plugin_admin_menu();
	}
);

// Register Portal integration hooks.
$fchub_stream_portal_integration = new \FCHubStream\App\Hooks\PortalIntegration();
$fchub_stream_portal_integration->register();

// NOTE: Video deletion hooks are now registered EARLY in boot/app.php (plugins_loaded action)
// to ensure they're available when posts are deleted outside the portal context.
//
// The hooks below are kept as a fallback, but they may not execute if portal hasn't loaded yet.
// Primary registration: boot/app.php -> plugins_loaded (priority 20)
//
// Register video deletion handler (fallback - should already be registered in boot/app.php).
if ( ! has_action( 'fluent_community/feed/before_deleted', array( 'FCHubStream\App\Hooks\Handlers\VideoDeletionHandler', 'handle_feed_deleted' ) ) ) {
	$fchub_stream_video_deletion_handler = new \FCHubStream\App\Hooks\Handlers\VideoDeletionHandler();

	// Hook into feed deletion.
	add_action(
		'fluent_community/feed/before_deleted',
		array( $fchub_stream_video_deletion_handler, 'handle_feed_deleted' ),
		10,
		1
	);

	// Hook into comment deletion (for video comments).
	add_action(
		'fluent_community/before_comment_delete',
		array( $fchub_stream_video_deletion_handler, 'handle_comment_deleted' ),
		10,
		1
	);

	error_log( '[FCHub Stream] Video deletion hooks registered in actions.php (fallback)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
