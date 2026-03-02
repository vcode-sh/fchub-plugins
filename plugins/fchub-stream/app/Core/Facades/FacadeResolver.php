<?php
/**
 * Facade Resolver.
 *
 * Handles dynamic facade class creation and resolution for the plugin.
 * Automatically generates facade classes using SPL autoloader.
 *
 * @package FCHub_Stream
 * @subpackage Core\Facades
 * @since 1.0.0
 */

namespace FCHubStream\App\Core\Facades;

use FluentCommunity\Framework\Support\Facade;
use FCHubStream\App\Core\Application;

/**
 * Facade Resolver Class.
 *
 * Dynamically creates facade classes using anonymous classes and SPL autoloader.
 * Provides convenient static access to container services.
 *
 * @since 1.0.0
 */
class FacadeResolver {

	/**
	 * Application instance.
	 *
	 * @since 1.0.0
	 * @var Application
	 */
	protected $app;

	/**
	 * Constructor.
	 *
	 * Initializes the facade resolver with application instance.
	 *
	 * @since 1.0.0
	 *
	 * @param Application $app Application instance.
	 */
	public function __construct( Application $app ) {
		$this->app = $app;
	}

	/**
	 * Register facade resolver.
	 *
	 * Sets up autoloader for facade classes and configures facade application.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		Facade::setFacadeApplication( $this->app );

		$resolver = $this;

		spl_autoload_register(
			function ( $class_name ) use ( $resolver ) {

				$fqn    = __NAMESPACE__;
				$ns     = substr( $fqn, 0, strpos( $fqn, '\\' ) );
				$facade = $ns . '\Facade';

				if ( str_contains( $class_name, $facade ) ) {
					$resolver->create_facade_for( $facade, $class_name );
				}
			}
		);
	}

	/**
	 * Create facade for class.
	 *
	 * Dynamically creates a facade class using an anonymous class.
	 * The anonymous class extends the base Facade class and returns
	 * the accessor name for container resolution.
	 *
	 * @since 1.0.0
	 *
	 * @param string $facade     Facade namespace.
	 * @param string $class_name Class name.
	 */
	public function create_facade_for( $facade, $class_name ) {
		$facade_accessor = $this->resolve_facade_accessor( $facade, $class_name );

		$anonymous_class = new class( $facade_accessor ) extends Facade {

			/**
			 * Facade accessor name.
			 *
			 * @var string
			 */
			protected static $facade_accessor;

			/**
			 * Constructor.
			 *
			 * @param string $facade_accessor Facade accessor name.
			 */
			public function __construct( $facade_accessor ) {
				self::$facade_accessor = $facade_accessor;
			}

			/**
			 * Get facade accessor.
			 *
			 * @return string Facade accessor name.
			 */
			protected static function getFacadeAccessor() {
				return self::$facade_accessor;
			}
		};

		class_alias( get_class( $anonymous_class ), $class_name, true );
	}

	/**
	 * Resolve facade accessor.
	 *
	 * Determines the container binding name for a facade.
	 * Normalizes the class name and checks if it's bound in the container.
	 *
	 * @since 1.0.0
	 *
	 * @param string $facade     Facade namespace.
	 * @param string $class_name Class name.
	 *
	 * @return string|null Accessor name or null if not bound.
	 */
	protected function resolve_facade_accessor( $facade, $class_name ) {
		$name = strtolower( trim( str_replace( $facade, '', $class_name ), '\\' ) );

		if ( 'route' === $name ) {
			$name = 'router';
		}

		if ( $this->app->bound( $name ) ) {
			return $name;
		}

		return null;
	}
}
