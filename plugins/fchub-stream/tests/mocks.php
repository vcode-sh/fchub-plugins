<?php
/**
 * Mock classes and functions for FCHub Stream tests.
 *
 * This file contains additional mock classes and functions that may be needed
 * for testing specific plugin functionality. The main mock implementations
 * are located in bootstrap.php.
 *
 * @package FCHub_Stream
 * @subpackage Tests
 * @since 0.0.1
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mock WordPress functions needed for PortalIntegration tests.
 *
 * @since 0.0.1
 */

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Mock add_action function.
	 *
	 * @since 0.0.1
	 *
	 * @param string   $hook     Action hook name.
	 * @param callable $callback Callback function.
	 * @param int      $priority Action priority.
	 * @param int      $args     Number of arguments.
	 * @return void
	 */
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		// Mock add_action - store in global for testing.
		global $wp_actions;
		if ( ! isset( $wp_actions ) ) {
			$wp_actions = array();
		}
		if ( ! isset( $wp_actions[ $hook ] ) ) {
			$wp_actions[ $hook ] = array();
		}
		$wp_actions[ $hook ][] = array(
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $args,
		);
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Mock has_action function.
	 *
	 * @since 0.0.1
	 *
	 * @param string        $hook     Action hook name.
	 * @param callable|null $callback Optional. Callback to check for.
	 * @return bool Whether action is registered.
	 */
	function has_action( $hook, $callback = null ) {
		global $wp_actions;
		if ( ! isset( $wp_actions[ $hook ] ) ) {
			return false;
		}
		if ( null === $callback ) {
			return true;
		}
		foreach ( $wp_actions[ $hook ] as $action ) {
			if ( $action['callback'] === $callback ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * Mock has_filter function.
	 *
	 * @since 0.0.1
	 *
	 * @param string        $hook     Filter hook name.
	 * @param callable|null $callback Optional. Callback to check for.
	 * @return bool Whether filter is registered.
	 */
	function has_filter( $hook, $callback = null ) {
		global $wp_filters;
		if ( ! isset( $wp_filters[ $hook ] ) ) {
			return false;
		}
		if ( null === $callback ) {
			return true;
		}
		foreach ( $wp_filters[ $hook ] as $filter ) {
			if ( $filter['callback'] === $callback ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Mock wp_create_nonce function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $action Nonce action.
	 * @return string Mock nonce.
	 */
	function wp_create_nonce( $action ) {
		return 'mock_nonce_' . md5( $action );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Mock rest_url function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $path REST path.
	 * @return string Mock REST URL.
	 */
	function rest_url( $path = '' ) {
		return 'https://example.com/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Mock get_option function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed Option value.
	 */
	function get_option( $option, $default = false ) {
		global $wp_options;
		if ( ! isset( $wp_options ) ) {
			$wp_options = array();
		}
		return $wp_options[ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Mock update_option function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return bool Always returns true.
	 */
	function update_option( $option, $value ) {
		global $wp_options;
		if ( ! isset( $wp_options ) ) {
			$wp_options = array();
		}
		$wp_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Mock sanitize_text_field function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $str String to sanitize.
	 * @return string Sanitized string.
	 */
	function sanitize_text_field( $str ) {
		return strip_tags( $str );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Mock esc_attr function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Mock esc_url function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $url URL to escape.
	 * @return string Escaped URL.
	 */
	function esc_url( $url ) {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Mock is_wp_error function.
	 *
	 * @since 0.0.1
	 *
	 * @param mixed $thing Variable to check.
	 * @return bool Whether it's a WP_Error.
	 */
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Mock WP_Error class.
	 *
	 * @since 0.0.1
	 */
	class WP_Error {
		/**
		 * Error codes.
		 *
		 * @since 0.0.1
		 * @var array
		 */
		public $errors = array();

		/**
		 * Error data.
		 *
		 * @since 0.0.1
		 * @var array
		 */
		public $error_data = array();

		/**
		 * Constructor.
		 *
		 * @since 0.0.1
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( ! empty( $code ) ) {
				$this->errors[ $code ][] = $message;
			}
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * Get error message.
		 *
		 * @since 0.0.1
		 *
		 * @param string $code Optional. Error code.
		 * @return string Error message.
		 */
		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			return $this->errors[ $code ][0] ?? '';
		}

		/**
		 * Get error code.
		 *
		 * @since 0.0.1
		 *
		 * @return string Error code.
		 */
		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes[0] ?? '';
		}
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Mock __ function for translations.
	 *
	 * @since 0.0.1
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string Translated text (returns original).
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Mock wp_json_encode function.
	 *
	 * @since 0.0.1
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options Optional. Options.
	 * @param int   $depth   Optional. Depth.
	 * @return string|false JSON string or false on failure.
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'filemtime' ) ) {
	/**
	 * Mock filemtime function.
	 *
	 * @since 0.0.1
	 *
	 * @param string $filename File path.
	 * @return int Mock timestamp.
	 */
	function filemtime( $filename ) {
		return time();
	}
}

/**
 * Initialize global WordPress arrays for testing.
 *
 * @since 0.0.1
 */
global $wp_filters, $wp_actions, $wp_options;
$wp_filters = array();
$wp_actions = array();
$wp_options = array();
