<?php
/**
 * REST API route definitions.
 *
 * Registers all REST API endpoints for the FCHub Stream plugin including
 * stream configuration, provider settings (Bunny.net/Cloudflare), and
 * video management endpoints.
 *
 * @package FCHubStream
 * @subpackage Http\Routes
 * @since 1.0.0
 */

use FCHubStream\App\Http\Controllers\StreamConfigController;
use FCHubStream\App\Http\Controllers\VideoUploadController;
use FCHubStream\App\Http\Controllers\UploadSettingsController;
use FCHubStream\App\Http\Controllers\CommentVideoSettingsController;

/**
 * Stream Configuration Routes.
 *
 * Handles general stream configuration endpoints including retrieving,
 * saving, and testing stream provider configurations.
 */
$router->group(
	function ( $router ) {

		// Get stream configuration (public endpoint).
		$router->get( 'config', array( StreamConfigController::class, 'get' ) );

		// Save stream configuration.
		$router->post( 'config', array( StreamConfigController::class, 'save' ) );

		// Test stream API connection.
		$router->post( 'config/test', array( StreamConfigController::class, 'testConnection' ) );

		// Update active provider (Admin endpoint).
		$router->patch( 'config/provider', array( StreamConfigController::class, 'update_provider' ) );

		// Upload settings (Admin endpoint).
		$router->get( 'settings/upload', array( UploadSettingsController::class, 'get' ) );
		$router->post( 'settings/upload', array( UploadSettingsController::class, 'save' ) );
		$router->post( 'settings/upload/reset', array( UploadSettingsController::class, 'reset' ) );

		// Comment video settings (Admin endpoint).
		$router->get( 'settings/comment-video', array( CommentVideoSettingsController::class, 'get' ) );
		$router->post( 'settings/comment-video', array( CommentVideoSettingsController::class, 'save' ) );
		$router->post( 'settings/comment-video/reset', array( CommentVideoSettingsController::class, 'reset' ) );
	}
);

/**
 * Portal Video Upload Routes.
 *
 * Handles video upload endpoints for Portal users.
 * Uses PortalPolicy to ensure user is logged in.
 */
$router->withPolicy( 'PortalPolicy' )->group(
	function ( $router ) {
		// Video upload (Portal endpoint - requires user to be logged in).
		$router->post( 'video-upload', array( VideoUploadController::class, 'upload' ) );

		// Video status check (Portal endpoint - requires user to be logged in).
		$router->get( 'video-status/:video_id', array( VideoUploadController::class, 'check_status' ) );

		// Video status update (Portal endpoint - updates database when frontend confirms video is ready).
		$router->post( 'video-update-status', array( VideoUploadController::class, 'update_status' ) );
	}
);

/**
 * Webhook Routes.
 *
 * Public webhook endpoints (signature verified in controller).
 */
$router->group(
	function ( $router ) {
		// Webhook handler (Public endpoint - signature verified in controller).
		$router->post( 'webhook/:provider', array( VideoUploadController::class, 'webhook' ) );
	}
);
