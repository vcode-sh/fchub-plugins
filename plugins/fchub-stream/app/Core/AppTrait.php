<?php
/**
 * App Trait
 *
 * Provides static instance management for the application container.
 *
 * @package FCHub_Stream
 * @since 1.0.0
 */

namespace FCHubStream\App\Core;

/**
 * AppTrait
 *
 * Singleton pattern trait for managing the application instance.
 * Provides static access to the container and its services.
 *
 * @since 1.0.0
 */
trait AppTrait {

	/**
	 * Application instance.
	 *
	 * @since 1.0.0
	 * @var Application|null
	 */
	protected static $instance = null;

	/**
	 * Set the application instance.
	 *
	 * @since 1.0.0
	 *
	 * @param Application $app Application instance to set.
	 *
	 * @return void
	 */
	public static function set_instance( $app ) {
		static::$instance = $app;
	}

	/**
	 * Get the application instance or a specific module.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $module Optional. Module name to retrieve. Default null.
	 *
	 * @return Application|mixed Application instance or module.
	 */
	public static function get_instance( $module = null ) {
		if ( $module ) {
			return static::$instance[ $module ];
		}

		return static::$instance;
	}

	/**
	 * Make a module from the container.
	 *
	 * Alias for get_instance method.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $module Optional. Module name to retrieve. Default null.
	 *
	 * @return Application|mixed Application instance or module.
	 */
	public static function make( $module = null ) {
		return static::get_instance( $module );
	}

	/**
	 * Handle static method calls.
	 *
	 * Magic method to retrieve modules via static method calls.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method Method name (interpreted as module name).
	 * @param array  $params Method parameters (unused).
	 *
	 * @return mixed Module instance.
	 */
	public static function __callStatic( $method, $params ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return static::get_instance( $method );
	}
}
