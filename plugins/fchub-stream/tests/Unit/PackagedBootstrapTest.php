<?php
/**
 * Unit tests for the packaged plugin bootstrap.
 *
 * @package FCHub_Stream
 * @subpackage Tests
 * @since 1.0.3
 */

namespace FCHubStream\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Verifies that the release package boots without development dependencies.
 *
 * @since 1.0.3
 */
class PackagedBootstrapTest extends TestCase {
	/**
	 * Temporary release package directory.
	 *
	 * @since 1.0.3
	 * @var string
	 */
	private $package_dir;

	/**
	 * Create an isolated package directory.
	 *
	 * @since 1.0.3
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->package_dir = sys_get_temp_dir() . '/fchub-stream-package-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->package_dir, 0700, true );
	}

	/**
	 * Remove the isolated package directory.
	 *
	 * @since 1.0.3
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->remove_directory( $this->package_dir );

		parent::tearDown();
	}

	/**
	 * The distributed plugin must load its classes without Composer artifacts.
	 *
	 * @since 1.0.3
	 *
	 * @return void
	 */
	public function test_release_package_bootstraps_without_composer_artifacts() {
		$plugin_dir = dirname( __DIR__, 2 );
		$files      = array(
			'fchub-stream.php',
			'boot/app.php',
			'app/Core/Application.php',
			'app/Utils/Logger.php',
			'lib/GitHubUpdater.php',
		);

		foreach ( $files as $file ) {
			$destination = $this->package_dir . '/' . $file;
			if ( ! is_dir( dirname( $destination ) ) ) {
				mkdir( dirname( $destination ), 0700, true );
			}
			copy( $plugin_dir . '/' . $file, $destination );
		}

		$this->assertFileDoesNotExist( $this->package_dir . '/composer.json' );
		$this->assertDirectoryDoesNotExist( $this->package_dir . '/vendor' );

		$harness = $this->package_dir . '/verify-bootstrap.php';
		file_put_contents(
			$harness,
			<<<'PHP'
<?php
define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'HOUR_IN_SECONDS', 3600 );

function plugin_basename( $file ) {
	return 'fchub-stream/' . basename( $file );
}

function plugin_dir_url() {
	return 'https://example.test/wp-content/plugins/fchub-stream/';
}

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

function add_filter() {}
function add_action() {}
function register_activation_hook() {}
function register_deactivation_hook() {}

require __DIR__ . '/fchub-stream.php';

if ( ! class_exists( 'FCHubStream\\App\\Core\\Application' ) ) {
	fwrite( STDERR, "Application class was not autoloaded.\n" );
	exit( 2 );
}

if ( ! function_exists( 'FCHubStream\\App\\Utils\\log_debug' ) ) {
	fwrite( STDERR, "Logger functions were not loaded.\n" );
	exit( 3 );
}

$reflection  = new ReflectionClass( 'FCHubStream\\App\\Core\\Application' );
$application = $reflection->newInstanceWithoutConstructor();
$method      = $reflection->getMethod( 'set_app_level_namespace' );
$method->invoke( $application );

if ( 'FCHubStream' !== $application['__namespace__'] ) {
	fwrite( STDERR, "Application namespace was not initialised.\n" );
	exit( 4 );
}

echo "FCHubStream\n";
PHP
		);

		$process = proc_open(
			array( PHP_BINARY, $harness ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->package_dir
		);

		$this->assertIsResource( $process );

		$stdout    = stream_get_contents( $pipes[1] );
		$stderr    = stream_get_contents( $pipes[2] );
		$exit_code = proc_close( $process );

		$this->assertSame( 0, $exit_code, "Packaged bootstrap failed:\n{$stderr}" );
		$this->assertSame( "FCHubStream\n", $stdout );
	}

	/**
	 * Recursively remove a test directory.
	 *
	 * @since 1.0.3
	 *
	 * @param string $directory Directory to remove.
	 * @return void
	 */
	private function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $directory );
	}
}
