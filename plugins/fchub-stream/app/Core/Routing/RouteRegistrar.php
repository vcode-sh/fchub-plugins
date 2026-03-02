<?php
/**
 * REST API Route Registrar.
 *
 * Handles registration of REST API routes both via FluentCommunity router
 * and WordPress REST API. Manages route definitions, permissions, and callbacks.
 *
 * @package FCHub_Stream
 * @subpackage Core\Routing
 * @since 1.0.0
 */

namespace FCHubStream\App\Core\Routing;

use InvalidArgumentException;
use FCHubStream\App\Core\Application;

/**
 * Route Registrar Class.
 *
 * Centralizes REST API route registration for the plugin.
 * Handles both FluentCommunity framework routes and direct WordPress REST routes.
 *
 * @since 1.0.0
 */
class RouteRegistrar {

	/**
	 * Application instance.
	 *
	 * @since 1.0.0
	 * @var Application
	 */
	protected $app;

	/**
	 * FluentCommunity application instance.
	 *
	 * @since 1.0.0
	 * @var object
	 */
	protected $fluent_app;

	/**
	 * Constructor.
	 *
	 * Initializes the route registrar with application instances.
	 *
	 * @since 1.0.0
	 *
	 * @param Application $app         FCHub Stream application instance.
	 * @param object      $fluent_app  FluentCommunity application instance.
	 */
	public function __construct( Application $app, $fluent_app ) {
		$this->app        = $app;
		$this->fluent_app = $fluent_app;
	}

	/**
	 * Register all REST API routes.
	 *
	 * Registers routes both via FluentCommunity router and WordPress REST API.
	 * The dual registration ensures proper permission handling for admin endpoints.
	 *
	 * @since 1.0.0
	 *
	 * @throws InvalidArgumentException If route registration fails.
	 */
	public function register() {
		$this->register_fluent_routes();
		$this->register_wordpress_routes();
	}

	/**
	 * Register routes via FluentCommunity router.
	 *
	 * Registers standard routes using FluentCommunity's routing system.
	 *
	 * @since 1.0.0
	 *
	 * @throws InvalidArgumentException If route registration fails.
	 */
	protected function register_fluent_routes() {
		$route_registrar = $this;

		// Register routes directly using router->group() like other FluentCommunity modules.
		// Set prefix first, then group.
		$this->fluent_app->router->prefix( 'stream' )->group(
			function ( $router ) use ( $route_registrar ) {
				$route_registrar->require_route_file( $router );
			}
		);
	}

	/**
	 * Register routes directly via WordPress REST API.
	 *
	 * Registers admin routes directly with WordPress to ensure proper
	 * permission handling with WordPress nonces.
	 *
	 * @since 1.0.0
	 */
	protected function register_wordpress_routes() {
		add_action(
			'rest_api_init',
			function () {
				$namespace = 'fluent-community/v2';

				// Get configuration (shared).
				register_rest_route(
					$namespace,
					'/stream/config',
					array(
						'methods'             => 'GET',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\StreamConfigController();
							return $controller->get( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Cloudflare: Save configuration.
				register_rest_route(
					$namespace,
					'/stream/config/cloudflare',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\CloudflareConfigController();
							return $controller->save( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Cloudflare: Test connection.
				register_rest_route(
					$namespace,
					'/stream/config/cloudflare/test',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\CloudflareConfigController();
							return $controller->test_connection( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Cloudflare: Update enabled status.
				register_rest_route(
					$namespace,
					'/stream/config/cloudflare/enabled',
					array(
						'methods'             => 'PATCH',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\CloudflareConfigController();
							return $controller->update_enabled( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Cloudflare: Activate webhook.
				register_rest_route(
					$namespace,
					'/stream/config/cloudflare/webhook',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							error_log( '[FCHub Stream] RouteRegistrar - activate_webhook callback called' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							try {
								error_log( '[FCHub Stream] RouteRegistrar - Creating CloudflareConfigController...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								$controller = new \FCHubStream\App\Http\Controllers\CloudflareConfigController();
								error_log( '[FCHub Stream] RouteRegistrar - Calling activate_webhook()...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								$result = $controller->activate_webhook( $wp_request );
								error_log( '[FCHub Stream] RouteRegistrar - activate_webhook() returned' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								return $result;
							} catch ( \Exception $e ) {
								error_log( '[FCHub Stream] RouteRegistrar - EXCEPTION in callback: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								error_log( '[FCHub Stream] RouteRegistrar - EXCEPTION Trace: ' . $e->getTraceAsString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								throw $e;
							} catch ( \Error $e ) {
								error_log( '[FCHub Stream] RouteRegistrar - FATAL ERROR in callback: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								error_log( '[FCHub Stream] RouteRegistrar - FATAL ERROR Trace: ' . $e->getTraceAsString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								throw $e;
							}
						},
						'permission_callback' => function () {
							error_log( '[FCHub Stream] RouteRegistrar - Checking permissions for activate_webhook' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							$has_permission = current_user_can( 'manage_options' );
							error_log( '[FCHub Stream] RouteRegistrar - Permission check result: ' . ( $has_permission ? 'GRANTED' : 'DENIED' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							return $has_permission;
						},
					)
				);

				// Cloudflare: Remove configuration.
				register_rest_route(
					$namespace,
					'/stream/config/cloudflare',
					array(
						'methods'             => 'DELETE',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\CloudflareConfigController();
							return $controller->remove( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Bunny.net: Save configuration.
				register_rest_route(
					$namespace,
					'/stream/config/bunny',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\BunnyConfigController();
							return $controller->save( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Bunny.net: Test Connection.
				register_rest_route(
					$namespace,
					'/stream/config/bunny/test',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\BunnyConfigController();
							return $controller->test_connection( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Bunny.net: Get Collections.
				register_rest_route(
					$namespace,
					'/stream/config/bunny/collections',
					array(
						'methods'             => 'GET',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\BunnyConfigController();
							return $controller->get_collections( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Bunny.net: Update enabled status.
				register_rest_route(
					$namespace,
					'/stream/config/bunny/enabled',
					array(
						'methods'             => 'PATCH',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\BunnyConfigController();
							return $controller->update_enabled( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Bunny.net: Remove configuration.
				register_rest_route(
					$namespace,
					'/stream/config/bunny',
					array(
						'methods'             => 'DELETE',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\BunnyConfigController();
							return $controller->remove( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Remove configuration (shared).
				register_rest_route(
					$namespace,
					'/stream/config',
					array(
						'methods'             => 'DELETE',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\StreamConfigController();
							return $controller->remove( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Update active provider (Admin endpoint).
				register_rest_route(
					$namespace,
					'/stream/config/provider',
					array(
						'methods'             => 'PATCH',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\StreamConfigController();
							return $controller->update_provider( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Video upload (Portal endpoint - requires user to be logged in).
				register_rest_route(
					$namespace,
					'/stream/video-upload',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\VideoUploadController();
							return $controller->upload( $wp_request );
						},
						'permission_callback' => function () {
							return is_user_logged_in();
						},
					)
				);

				// Video status check (Portal endpoint - requires user to be logged in).
				register_rest_route(
					$namespace,
					'/stream/video-status/(?P<video_id>[a-zA-Z0-9_-]+)',
					array(
						'methods'             => 'GET',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\VideoUploadController();
							return $controller->check_status( $wp_request );
						},
						'permission_callback' => function () {
							return is_user_logged_in();
						},
						'args'                => array(
							'video_id' => array(
								'required' => true,
								'type'     => 'string',
							),
						),
					)
				);

				// Video status update (Portal endpoint - updates database when frontend confirms ready).
				register_rest_route(
					$namespace,
					'/stream/video-update-status',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\VideoUploadController();
							return $controller->update_status( $wp_request );
						},
						'permission_callback' => function () {
							return is_user_logged_in();
						},
					)
				);

				// Upload settings endpoints (Admin endpoints).
				register_rest_route(
					$namespace,
					'/stream/settings/upload',
					array(
						'methods'             => 'GET',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\UploadSettingsController();
							return $controller->get( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				register_rest_route(
					$namespace,
					'/stream/settings/upload',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\UploadSettingsController();
							return $controller->save( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				register_rest_route(
					$namespace,
					'/stream/settings/upload/reset',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\UploadSettingsController();
							return $controller->reset( $wp_request );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Webhook endpoint (Public - signature verified in controller).
				register_rest_route(
					$namespace,
					'/stream/webhook/(?P<provider>cloudflare_stream|bunny_stream)',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $wp_request ) {
							$controller = new \FCHubStream\App\Http\Controllers\VideoUploadController();
							return $controller->webhook( $wp_request );
						},
						'permission_callback' => '__return_true', // Public endpoint - signature verification in controller.
						'args'                => array(
							'provider' => array(
								'required' => true,
								'type'     => 'string',
							),
						),
					)
				);

				error_log( '[FCHub Stream] WordPress REST routes registered' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			},
			20
		);
	}

	/**
	 * Require route file.
	 *
	 * Loads API routes with proper namespace grouping.
	 *
	 * @since 1.0.0
	 *
	 * @param object $router FluentCommunity router instance.
	 */
	public function require_route_file( $router ) {
		$namespace  = $this->app['__namespace__'] . '\App\Http\Controllers';
		$route_file = $this->app['path.http'] . 'Routes/api.php';

		// Set namespace and require route file directly.
		$router->namespace( $namespace );
		if ( file_exists( $route_file ) ) {
			require_once $route_file;
		}
	}
}
