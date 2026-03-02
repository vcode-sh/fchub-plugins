<?php
/**
 * PHPUnit bootstrap file for FCHub Stream tests.
 *
 * This file initializes the testing environment by:
 * - Loading the Composer autoloader
 * - Loading mock classes and WordPress test libraries
 * - Defining test constants
 * - Mocking WordPress functions and classes
 *
 * @package FCHub_Stream
 * @subpackage Tests
 * @since 0.0.1
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load mock classes.
if ( file_exists( __DIR__ . '/mocks.php' ) ) {
	require_once __DIR__ . '/mocks.php';
}

// WordPress test library (if available).
if ( file_exists( '/tmp/wordpress-tests-lib/includes/functions.php' ) ) {
	require_once '/tmp/wordpress-tests-lib/includes/functions.php';
}

// Load WordPress (if available).
if ( file_exists( '/tmp/wordpress/wp-load.php' ) ) {
	require_once '/tmp/wordpress/wp-load.php';
}

/**
 * Define test constants.
 *
 * @since 0.0.1
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
}

/**
 * Mock WordPress functions if not loaded.
 *
 * @since 0.0.1
 */
if ( ! function_exists( 'wp_create_user' ) ) {
	/**
	 * Mock wp_create_user function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $username User's username.
	 * @param string $password User's password.
	 * @param string $email    User's email address.
	 * @return int Mock user ID.
	 */
	function wp_create_user( $username, $password, $email = '' ) {
		return rand( 1, 1000 );
	}
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
	/**
	 * Mock wp_set_current_user function.
	 *
	 * @since 0.0.1
	 *
	 * @param int $user_id User ID to set as current.
	 * @return object Mock current user object.
	 */
	function wp_set_current_user( $user_id ) {
		global $current_user;
		$current_user = (object) array( 'ID' => $user_id );
		return $current_user;
	}
}

if ( ! function_exists( 'wp_delete_user' ) ) {
	/**
	 * Mock wp_delete_user function.
	 *
	 * @since 0.0.1
	 *
	 * @param int $user_id User ID to delete.
	 * @return bool Always returns true.
	 */
	function wp_delete_user( $user_id ) {
		return true;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Mock WP_REST_Request class.
	 *
	 * @since 0.0.1
	 */
	class WP_REST_Request {
		/**
		 * GET parameters.
		 *
		 * @since 0.0.1
		 * @var array
		 */
		public $get_params = array();

		/**
		 * Single GET parameter.
		 *
		 * @since 0.0.1
		 * @var mixed
		 */
		public $get_param = null;

		/**
		 * JSON parameters.
		 *
		 * @since 0.0.1
		 * @var array
		 */
		public $json_params = array();

		/**
		 * Body parameters.
		 *
		 * @since 0.0.1
		 * @var array
		 */
		public $body_params = array();

		/**
		 * Request route.
		 *
		 * @since 0.0.1
		 * @var string
		 */
		public $route = '';

		/**
		 * Request method.
		 *
		 * @since 0.0.1
		 * @var string
		 */
		public $method = 'GET';

		/**
		 * Get a parameter from the request.
		 *
		 * @since 0.0.1
		 *
		 * @param string $key     Parameter key.
		 * @param mixed  $default Default value.
		 * @return mixed Parameter value or default.
		 */
		public function get_param( $key, $default = null ) {
			return isset( $this->get_params[ $key ] ) ? $this->get_params[ $key ] : $default;
		}

		/**
		 * Get JSON parameters.
		 *
		 * @since 0.0.1
		 *
		 * @return array JSON parameters.
		 */
		public function get_json_params() {
			return $this->json_params;
		}

		/**
		 * Get body parameters.
		 *
		 * @since 0.0.1
		 *
		 * @return array Body parameters.
		 */
		public function get_body_params() {
			return $this->body_params;
		}

		/**
		 * Get request route.
		 *
		 * @since 0.0.1
		 *
		 * @return string Request route.
		 */
		public function get_route() {
			return $this->route;
		}

		/**
		 * Get request method.
		 *
		 * @since 0.0.1
		 *
		 * @return string Request method.
		 */
		public function get_method() {
			return $this->method;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Mock WP_REST_Response class.
	 *
	 * @since 0.0.1
	 */
	class WP_REST_Response {
		/**
		 * Response data.
		 *
		 * @since 0.0.1
		 * @var mixed
		 */
		public $data;

		/**
		 * Response status code.
		 *
		 * @since 0.0.1
		 * @var int
		 */
		public $status;

		/**
		 * Response headers.
		 *
		 * @since 0.0.1
		 * @var array
		 */
		public $headers = array();

		/**
		 * Constructor.
		 *
		 * @since 0.0.1
		 *
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status code.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/**
		 * Get response data.
		 *
		 * @since 0.0.1
		 *
		 * @return mixed Response data.
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Get response status code.
		 *
		 * @since 0.0.1
		 *
		 * @return int HTTP status code.
		 */
		public function get_status() {
			return $this->status;
		}

		/**
		 * Set response status code.
		 *
		 * @since 0.0.1
		 *
		 * @param int $status HTTP status code.
		 * @return WP_REST_Response Current instance for chaining.
		 */
		public function set_status( $status ) {
			$this->status = $status;
			return $this;
		}
	}
}

if ( ! function_exists( 'rest_do_request' ) ) {
	/**
	 * Mock rest_do_request function.
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Mock REST response.
	 */
	function rest_do_request( $request ) {
		return new WP_REST_Response( array( 'message' => 'Mock response' ), 200 );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Mock current_time function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $format Time format.
	 * @return string Formatted current time.
	 */
	function current_time( $format ) {
		return gmdate( $format );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Mock do_action function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $hook Action hook name.
	 * @param mixed  ...$args Action arguments.
	 * @return void
	 */
	function do_action( $hook, ...$args ) {
		// Mock do_action.
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Mock add_filter function.
	 *
	 * @since 0.0.1
	 *
	 * @param string   $hook     Filter hook name.
	 * @param callable $callback Callback function.
	 * @param int      $priority Filter priority.
	 * @param int      $args     Number of arguments.
	 * @return void
	 */
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		// Mock add_filter.
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Mock dbDelta function.
	 *
	 * @since 0.0.1
	 *
	 * @param string|array $queries SQL queries to execute.
	 * @return array Empty array (mock implementation).
	 */
	function dbDelta( $queries ) {
		// Mock dbDelta - just return without executing.
		return array();
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	/**
	 * Mock switch_to_blog function.
	 *
	 * @since 0.0.1
	 *
	 * @param int $blog_id Blog ID to switch to.
	 * @return void
	 */
	function switch_to_blog( $blog_id ) {
		// Mock switch_to_blog.
	}
}

/**
 * Mock global $wpdb if not available.
 *
 * @since 0.0.1
 */
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	/**
	 * Mock wpdb class for database operations.
	 *
	 * @since 0.0.1
	 */
	class MockWpdb {
		/**
		 * Database table prefix.
		 *
		 * @since 0.0.1
		 * @var string
		 */
		public $prefix = 'wp_';

		/**
		 * Blog ID for multisite.
		 *
		 * @since 0.0.1
		 * @var int
		 */
		public $blogid = 1;

		/**
		 * Get a single variable from the database.
		 *
		 * @since 0.0.1
		 *
		 * @param string $query SQL query.
		 * @return mixed|null Query result or null.
		 */
		public function get_var( $query ) {
			return null;
		}

		/**
		 * Execute a database query.
		 *
		 * @since 0.0.1
		 *
		 * @param string $query SQL query.
		 * @return bool Always returns true.
		 */
		public function query( $query ) {
			return true;
		}

		/**
		 * Insert a row into a table.
		 *
		 * @since 0.0.1
		 *
		 * @param string $table Table name.
		 * @param array  $data  Data to insert.
		 * @return bool Always returns true.
		 */
		public function insert( $table, $data ) {
			$this->insert_id = rand( 1, 1000 );
			return true;
		}

		/**
		 * Delete rows from a table.
		 *
		 * @since 0.0.1
		 *
		 * @param string $table Table name.
		 * @param array  $where WHERE conditions.
		 * @return bool Always returns true.
		 */
		public function delete( $table, $where ) {
			return true;
		}

		/**
		 * Get a single row from the database.
		 *
		 * @since 0.0.1
		 *
		 * @param string $query SQL query.
		 * @return object|null Query result or null.
		 */
		public function get_row( $query ) {
			return null;
		}

		/**
		 * Get a column from the database.
		 *
		 * @since 0.0.1
		 *
		 * @param string $query SQL query.
		 * @return array Empty array.
		 */
		public function get_col( $query ) {
			return array();
		}

		/**
		 * Prepare a SQL query for safe execution.
		 *
		 * @since 0.0.1
		 *
		 * @param string $query SQL query with placeholders.
		 * @param mixed  ...$args Values to replace placeholders.
		 * @return string Prepared query (mock returns original).
		 */
		public function prepare( $query, ...$args ) {
			return $query;
		}

		/**
		 * Get database charset collation.
		 *
		 * @since 0.0.1
		 *
		 * @return string Charset collation string.
		 */
		public function get_charset_collate() {
			return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
		}
	}

	$GLOBALS['wpdb'] = new MockWpdb();
}

echo "FCHub Stream Test Bootstrap Loaded\n";

