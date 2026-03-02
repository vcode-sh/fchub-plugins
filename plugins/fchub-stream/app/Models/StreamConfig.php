<?php
/**
 * Stream configuration model.
 *
 * Manages stream provider configuration stored in WordPress options table.
 * Provides CRUD operations for configuration data with automatic sanitization
 * and default value merging.
 *
 * @package FCHubStream
 * @subpackage Models
 * @since 1.0.0
 */

namespace FCHubStream\App\Models;

/**
 * Stream Configuration Model.
 *
 * Handles CRUD operations for stream provider configuration.
 * Delegates sanitization to ConfigTransformer and defaults to ConfigDefaults.
 *
 * Storage:
 * - Stored as WordPress option (fchub_stream_config)
 * - Sensitive data encrypted via EncryptionService
 * - Merged with defaults on retrieval
 *
 * @package FCHub_Stream
 * @subpackage Models
 * @since 1.0.0
 */
class StreamConfig {
	/**
	 * WordPress option name for configuration storage.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION_NAME = 'fchub_stream_config';

	/**
	 * Get default configuration structure.
	 *
	 * Returns the complete default configuration for all stream providers.
	 * Delegates to ConfigDefaults for consistency.
	 *
	 * @since 1.0.0
	 *
	 * @return array Default configuration structure.
	 */
	public static function get_defaults() {
		return ConfigDefaults::get();
	}

	/**
	 * Get configuration from database.
	 *
	 * Retrieves stream configuration from WordPress options and merges
	 * with defaults to ensure all required keys exist. Performs deep
	 * merge for nested provider configurations.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Complete configuration array with all defaults merged.
	 *     See ConfigDefaults::get() for structure details.
	 * }
	 */
	public static function get() {
		$config   = get_option( self::OPTION_NAME, array() );
		$defaults = ConfigDefaults::get();

		return ConfigTransformer::merge_with_defaults( $config, $defaults );
	}

	/**
	 * Save configuration to database.
	 *
	 * Sanitizes and saves stream configuration to WordPress options.
	 * Performs comprehensive sanitization of all configuration values
	 * while preserving encrypted credentials.
	 *
	 * Note: update_option() returns false if value hasn't changed (not an error).
	 * This method distinguishes between "no change" and actual save failures.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Configuration data to save.
	 *
	 * @return bool True on successful save, false on failure.
	 */
	public static function save( array $config ) {
		// Sanitize before saving.
		$sanitized = ConfigTransformer::sanitize( $config );

		// Get current value before saving to detect actual changes.
		$current = get_option( self::OPTION_NAME, array() );

		// Save to database.
		$result = update_option( self::OPTION_NAME, $sanitized, false );

		// update_option() returns false if value hasn't changed.
		// Check if value actually changed by comparing sanitized arrays.
		if ( false === $result ) {
			// Compare arrays to see if value actually changed.
			// If arrays are equal, update_option() correctly returned false (no change).
			// If arrays differ, this is a real error.
			$current_sanitized = ConfigTransformer::sanitize( $current );
			// Use JSON for deep comparison since arrays can have nested structures.
			// JSON is safer than serialize() and avoids PHP Object Injection vulnerabilities.
			if ( wp_json_encode( $sanitized ) === wp_json_encode( $current_sanitized ) ) {
				// Value hasn't changed - this is OK, not an error.
				return true;
			}
			// Arrays differ but update_option returned false - this is an error.
			return false;
		}

		return true;
	}

	/**
	 * Delete configuration from database.
	 *
	 * Removes the stream configuration from WordPress options.
	 * This action cannot be undone.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on successful deletion, false on failure.
	 */
	public static function delete() {
		return delete_option( self::OPTION_NAME );
	}

	/**
	 * Check if configuration exists in database.
	 *
	 * Determines whether stream configuration has been saved to
	 * WordPress options table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if configuration exists, false otherwise.
	 */
	public static function exists() {
		return false !== get_option( self::OPTION_NAME );
	}
}
