<?php
/**
 * Logger utility for PHP
 *
 * Logs only when WP_DEBUG is enabled, except for errors which are always logged.
 *
 * @package FCHubStream
 * @subpackage Utils
 * @since 1.0.0
 */

namespace FCHubStream\App\Utils;

/**
 * Check if debug logging is enabled.
 *
 * @return bool True if WP_DEBUG is enabled, false otherwise.
 */
function is_debug_enabled(): bool {
	return defined( 'WP_DEBUG' ) && WP_DEBUG;
}

/**
 * Log debug message (only when WP_DEBUG is enabled).
 *
 * @param string $message Message to log.
 * @return void
 */
function log_debug( string $message ): void {
	if ( is_debug_enabled() ) {
		error_log( '[FCHub Stream] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Log error message (always logged, even in production).
 *
 * @param string $message Error message to log.
 * @return void
 */
function log_error( string $message ): void {
	error_log( '[FCHub Stream] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Log warning message (only when WP_DEBUG is enabled).
 *
 * @param string $message Warning message to log.
 * @return void
 */
function log_warning( string $message ): void {
	if ( is_debug_enabled() ) {
		error_log( '[FCHub Stream] WARNING: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Log info message (only when WP_DEBUG is enabled).
 *
 * @param string $message Info message to log.
 * @return void
 */
function log_info( string $message ): void {
	if ( is_debug_enabled() ) {
		error_log( '[FCHub Stream] INFO: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
