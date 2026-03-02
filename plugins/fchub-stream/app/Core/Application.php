<?php
/**
 * Core Application Container
 *
 * Manages dependency injection, service bindings, and bootstraps the plugin.
 * Implements ArrayAccess for convenient container access.
 *
 * @package FCHub_Stream
 * @since 1.0.0
 */

namespace FCHubStream\App\Core;

use ArrayAccess;
use FluentCommunity\Framework\Foundation\Config;
use FCHubStream\App\Core\Routing\RouteRegistrar;
use FCHubStream\App\Core\Facades\FacadeResolver;

/**
 * Application Container Class
 *
 * Core dependency injection container that extends FluentCommunity framework.
 * Handles plugin bootstrapping, route registration, and facade resolution.
 *
 * @since 1.0.0
 */
class Application implements ArrayAccess {

	/**
	 * FluentCommunity application instance.
	 *
	 * @since 1.0.0
	 * @var object|null
	 */
	protected $app = null;

	/**
	 * Main plugin file path.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	protected $file = null;

	/**
	 * Plugin base URL.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	protected $base_url = null;

	/**
	 * Plugin base path.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	protected $base_path = null;

	/**
	 * Service bindings container.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $bindings = array();

	/**
	 * Methods to pass through to parent application.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $passthru = array(
		'addAction',
		'addFilter',
		'addShortcode',
	);

	/**
	 * Cached composer.json data.
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	protected static $composer = null;

	/**
	 * Constructor.
	 *
	 * Initializes the application container and bootstraps the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param object $app  FluentCommunity application instance.
	 * @param string $file Main plugin file path.
	 */
	public function __construct( $app, $file ) {
		$this->init( $app, $file );
		$this->set_app_level_namespace();
		$this->bootstrap_application();

		// Register facade resolver.
		$facade_resolver = new FacadeResolver( $this );
		$facade_resolver->register();
	}

	/**
	 * Initialize core properties.
	 *
	 * Sets up the application instance, file path, base path, and base URL.
	 *
	 * @since 1.0.0
	 *
	 * @param object $app  FluentCommunity application instance.
	 * @param string $file Main plugin file path.
	 */
	protected function init( $app, $file ) {
		$this->app       = $app;
		$this->file      = $file;
		$this->base_path = plugin_dir_path( $file );
		$this->base_url  = plugin_dir_url( $file );
	}

	/**
	 * Set application-level namespace from composer.json.
	 *
	 * @since 1.0.0
	 */
	protected function set_app_level_namespace() {
		$composer = $this->get_composer();

		$this->bindings['__namespace__'] = $composer['extra']['wpfluent']['namespace']['current'];
	}

	/**
	 * Get composer.json data.
	 *
	 * Loads and caches the composer.json file contents.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $section Optional. Specific section to retrieve. Default null.
	 *
	 * @return array|mixed Full composer data or specific section.
	 */
	public function get_composer( $section = null ) {
		if ( is_null( static::$composer ) ) {
			static::$composer = json_decode(
				file_get_contents( $this->base_path . 'composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				true
			);
		}

		return $section ? static::$composer[ $section ] : static::$composer;
	}

	/**
	 * Bootstrap the application.
	 *
	 * Runs the full bootstrap sequence including bindings, config, textdomain, and files.
	 *
	 * @since 1.0.0
	 */
	protected function bootstrap_application() {
		$this->bind_app_instance();
		$this->bind_paths_and_urls();
		$this->load_config_if_exists();
		$this->register_textdomain();
		$this->require_common_files( $this );
	}

	/**
	 * Bind the application instance to the container.
	 *
	 * @since 1.0.0
	 */
	protected function bind_app_instance() {
		App::set_instance( $this );
		$this->instance( 'app', $this );
		$this->instance( __CLASS__, $this );
	}

	/**
	 * Bind paths and URLs to the container.
	 *
	 * @since 1.0.0
	 */
	protected function bind_paths_and_urls() {
		$this->bind_urls();
		$this->base_paths();
	}

	/**
	 * Bind asset URLs to the container.
	 *
	 * @since 1.0.0
	 */
	protected function bind_urls() {
		$this->bindings['url.assets'] = $this->base_url . 'assets/';
		$this->bindings['url.dist']   = $this->base_url . 'dist/';
	}

	/**
	 * Bind base paths to the container.
	 *
	 * Sets up all directory paths used throughout the plugin.
	 *
	 * @since 1.0.0
	 */
	protected function base_paths() {
		$this->bindings['path']             = $this->base_path;
		$this->bindings['path.app']         = $this->base_path . 'app/';
		$this->bindings['path.hooks']       = $this->bindings['path.app'] . 'Hooks/';
		$this->bindings['path.http']        = $this->bindings['path.app'] . 'Http/';
		$this->bindings['path.controllers'] = $this->bindings['path.http'] . 'Controllers/';
		$this->bindings['path.config']      = $this->base_path . 'config/';
		$this->bindings['path.assets']      = $this->base_path . 'assets/';
		$this->bindings['path.dist']        = $this->base_path . 'dist/';
		$this->bindings['path.resources']   = $this->base_path . 'resources/';
		$this->bindings['path.views']       = $this->bindings['path.app'] . 'Views/';
	}

	/**
	 * Load configuration files if they exist.
	 *
	 * Scans the config directory and loads all PHP files into the config container.
	 *
	 * @since 1.0.0
	 */
	protected function load_config_if_exists() {
		$data = array();

		if ( is_dir( $this['path.config'] ) ) {
			foreach ( glob( $this['path.config'] . '*.php' ) as $file ) {
				// Use require instead of require_once to avoid TRUE return value on second include.
				$data[ basename( $file, '.php' ) ] = require $file;
			}
		}

		$data['app']['rest_namespace'] = $this->app->config->get( 'app.rest_namespace' );

		$this->bindings['config'] = new Config( $data );
	}

	/**
	 * Register plugin textdomain for translations.
	 *
	 * @since 1.0.0
	 */
	protected function register_textdomain() {
		$this->app->addAction(
			'init',
			function () {
				load_plugin_textdomain(
					$this->config->get( 'app.text_domain' ),
					false,
					$this->text_domain_path()
				);
			}
		);
	}

	/**
	 * Get the textdomain path.
	 *
	 * @since 1.0.0
	 *
	 * @return string Relative path to translation files.
	 */
	protected function text_domain_path() {
		return basename( $this->bindings['path'] ) . $this->config->get( 'app.domain_path' );
	}

	/**
	 * Require common plugin files.
	 *
	 * Loads hooks, filters, bindings, and registers REST routes.
	 *
	 * @since 1.0.0
	 *
	 * @param Application $app Application instance.
	 */
	protected function require_common_files( $app ) {
		require_once $this->base_path . 'app/Hooks/actions.php';
		require_once $this->base_path . 'app/Hooks/filters.php';

		$bindings = $this->base_path . 'boot/bindings.php';
		if ( file_exists( $bindings ) ) {
			require_once $bindings;
		}

		$includes = $this->base_path . 'app/Hooks/includes.php';
		if ( file_exists( $includes ) ) {
			require_once $includes;
		}

		// Register REST API routes.
		$route_registrar = new RouteRegistrar( $this, $app->app );
		$route_registrar->register();
	}

	/**
	 * Add custom action hook.
	 *
	 * Adds an action with the configured hook prefix.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $action    Action name.
	 * @param callable $handler   Handler callback.
	 * @param int      $priority  Optional. Priority. Default 10.
	 * @param int      $num_of_args Optional. Number of arguments. Default 1.
	 *
	 * @return true Always returns true.
	 */
	public function add_custom_action( $action, $handler, $priority = 10, $num_of_args = 1 ) {
		$prefix = $this->config->get( 'app.hook_prefix' );

		return $this->addAction(
			$this->hook( $prefix, $action ),
			$handler,
			$priority,
			$num_of_args
		);
	}

	/**
	 * Do custom action.
	 *
	 * Executes an action with the configured hook prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return mixed Action result.
	 */
	public function do_custom_action() {
		$args = func_get_args();

		$prefix = $this->config->get( 'app.hook_prefix' );

		$args[0] = $this->hook( $prefix, $args[0] );

		return $this->doAction( ...$args );
	}

	/**
	 * Add custom filter hook.
	 *
	 * Adds a filter with the configured hook prefix.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $action    Filter name.
	 * @param callable $handler   Handler callback.
	 * @param int      $priority  Optional. Priority. Default 10.
	 * @param int      $num_of_args Optional. Number of arguments. Default 1.
	 *
	 * @return true Always returns true.
	 */
	public function add_custom_filter( $action, $handler, $priority = 10, $num_of_args = 1 ) {
		$prefix = $this->config->get( 'app.hook_prefix' );

		return $this->addFilter(
			$this->hook( $prefix, $action ),
			$handler,
			$priority,
			$num_of_args
		);
	}

	/**
	 * Apply custom filters.
	 *
	 * Applies filters with the configured hook prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return mixed Filtered value.
	 */
	public function apply_custom_filters() {
		$args = func_get_args();

		$prefix = $this->config->get( 'app.hook_prefix' );

		$args[0] = $this->hook( $prefix, $args[0] );

		return $this->applyFilters( ...$args );
	}

	/**
	 * Get environment.
	 *
	 * Returns 'dev' if WP_DEBUG is true, otherwise returns configured environment.
	 *
	 * @since 1.0.0
	 *
	 * @return string Environment name.
	 */
	public function env() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return 'dev';
		}

		return $this->config->get( 'app.env' );
	}

	/**
	 * Determine if a given offset exists.
	 *
	 * Implements ArrayAccess interface method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Offset key to check.
	 *
	 * @return bool True if offset exists, false otherwise.
	 */
	#[\ReturnTypeWillChange]
	public function offsetExists( $key ) {
		return isset( $this->bindings[ $key ] ) ? true : $this->app->offsetExists( $key );
	}

	/**
	 * Get the value at a given offset.
	 *
	 * Implements ArrayAccess interface method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Offset key to retrieve.
	 *
	 * @return mixed Value at offset.
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $key ) {
		if ( 'view' === $key ) {
			return $this->view;
		}

		if ( isset( $this->bindings[ $key ] ) ) {
			return $this->bindings[ $key ];
		}

		return $this->app->make( $key );
	}

	/**
	 * Set the value at a given offset.
	 *
	 * Implements ArrayAccess interface method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Offset key to set.
	 * @param mixed  $value Value to set.
	 *
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet( $key, $value ) {
		$this->app->offset_set( $key, $value );
	}

	/**
	 * Unset the value at a given offset.
	 *
	 * Implements ArrayAccess interface method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Offset key to unset.
	 *
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetUnset( $key ) {
		$this->app->offset_unset( $key );
	}

	/**
	 * Dynamically access container services.
	 *
	 * Magic method to retrieve services from the container.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Service key to retrieve.
	 *
	 * @return mixed Service instance.
	 */
	public function __get( $key ) {
		if ( 'view' === $key ) {
			$view = $this->app->make( $key );
			$view->setViewPath( $this->bindings['path.views'] );
			return $view;
		}

		if ( isset( $this->bindings[ $key ] ) ) {
			return $this->bindings[ $key ];
		}

		return $this->app[ $key ];
	}

	/**
	 * Dynamically set container services.
	 *
	 * Magic method to set services in the container.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Service key to set.
	 * @param mixed  $value Service instance.
	 *
	 * @return void
	 */
	public function __set( $key, $value ) {
		$this->app[ $key ] = $value;
	}

	/**
	 * Handle dynamic method calls.
	 *
	 * Magic method to proxy calls to the parent application.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method Method name.
	 * @param array  $params Method parameters.
	 *
	 * @return mixed Method result.
	 */
	public function __call( $method, $params ) {
		if ( 'make' === $method && in_array( 'view', $params, true ) ) {
			return $this->view;
		}

		if ( in_array( $method, $this->passthru, true ) ) {
			if ( is_string( $params[1] ) && ! $this->app->hasNamespace( $params[1] ) ) {
				$ns        = substr( __NAMESPACE__, 0, strpos( __NAMESPACE__, '\\' ) );
				$params[1] = $ns . '\App\Hooks\Handlers\\' . $params[1];
			}
		}

		return call_user_func_array( array( $this->app, $method ), $params );
	}
}
