<?php
/**
 * Stream configuration service.
 *
 * Main service coordinating configuration management for all stream providers.
 * Delegates provider-specific logic to CloudflareConfigService and BunnyConfigService.
 * Handles encryption, decryption, and masking of sensitive configuration data.
 *
 * @package FCHub_Stream
 * @subpackage Services
 * @since 1.0.0
 */

namespace FCHubStream\App\Services;

use FCHubStream\App\Models\StreamConfig;
use FCHubStream\App\Utils\EncryptionService;

/**
 * Stream Configuration Service class.
 *
 * Coordinates configuration management across multiple stream providers
 * (Cloudflare Stream, Bunny.net) with encrypted credential storage.
 *
 * @since 1.0.0
 */
class StreamConfigService {

	/**
	 * Get public configuration with masked sensitive data.
	 *
	 * Returns configuration with sensitive fields masked for frontend display.
	 * API tokens, webhook secrets, and API keys are replaced with masked versions
	 * showing only the last few characters.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Configuration array with masked credentials.
	 *
	 *     @type string $provider              Active provider name ('cloudflare' or 'bunny').
	 *     @type array  $cloudflare            Cloudflare configuration with masked data.
	 *     @type array  $bunny                 Bunny.net configuration with masked data.
	 *     @type array  $defaults              Default upload settings.
	 * }
	 */
	public static function get_public(): array {
		$config = StreamConfig::get();

		// Delegate to provider-specific services.
		$config = CloudflareConfigService::mask_sensitive_data( $config );
		$config = BunnyConfigService::mask_sensitive_data( $config );

		return $config;
	}

	/**
	 * Get private configuration with decrypted sensitive data.
	 *
	 * Returns configuration with all sensitive fields decrypted.
	 * WARNING: Only use this internally - never expose to frontend!
	 * Contains plain-text API tokens, webhook secrets, and API keys.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Configuration array with decrypted credentials.
	 *
	 *     @type string $provider              Active provider name ('cloudflare' or 'bunny').
	 *     @type array  $cloudflare            Cloudflare configuration with decrypted tokens.
	 *     @type array  $bunny                 Bunny.net configuration with decrypted keys.
	 *     @type array  $defaults              Default upload settings.
	 * }
	 */
	public static function get_private(): array {
		$config = StreamConfig::get();

		// Decrypt sensitive fields.
		$config = CloudflareConfigService::decrypt_sensitive_data( $config );
		$config = BunnyConfigService::decrypt_sensitive_data( $config );

		return $config;
	}

	/**
	 * Save stream provider configuration.
	 *
	 * Validates and saves configuration data for the selected provider.
	 * Delegates to provider-specific services (CloudflareConfigService, BunnyConfigService).
	 * Optionally tests the connection after saving credentials.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data {
	 *     Configuration data to save.
	 *
	 *     @type string $provider             Provider name ('cloudflare' or 'bunny').
	 *     @type array  $cloudflare           Cloudflare-specific settings.
	 *     @type array  $bunny                Bunny.net-specific settings.
	 *     @type array  $defaults             Default upload settings.
	 * }
	 * @param bool  $test_connection Whether to test API connection after saving. Default false.
	 *
	 * @return array {
	 *     Save operation result.
	 *
	 *     @type bool   $success      Whether save succeeded.
	 *     @type string $message      Success or error message.
	 *     @type array  $test_result  Optional. Connection test result if $test_connection was true.
	 * }
	 */
	public static function save( array $data, bool $test_connection = false ): array {
		$current_config = StreamConfig::get();
		$defaults       = StreamConfig::get_defaults();

		// Ensure default structure exists.
		$current_config = array_merge( $defaults, $current_config );
		if ( isset( $current_config['cloudflare'] ) ) {
			$current_config['cloudflare'] = array_merge( $defaults['cloudflare'], $current_config['cloudflare'] );
		} else {
			$current_config['cloudflare'] = $defaults['cloudflare'];
		}
		if ( isset( $current_config['bunny'] ) ) {
			$current_config['bunny'] = array_merge( $defaults['bunny'], $current_config['bunny'] );
		} else {
			$current_config['bunny'] = $defaults['bunny'];
		}

		$config_to_save = $current_config;

		// Update provider.
		if ( isset( $data['provider'] ) ) {
			$config_to_save['provider'] = sanitize_text_field( $data['provider'] );
		}

		// Delegate to provider-specific services.
		if ( isset( $data['cloudflare'] ) ) {
			$cloudflare_result = CloudflareConfigService::save( $config_to_save, $data['cloudflare'] );
			if ( ! $cloudflare_result['success'] ) {
				return $cloudflare_result;
			}
			$config_to_save = $cloudflare_result['config'];
		}

		if ( isset( $data['bunny'] ) ) {
			$bunny_result = BunnyConfigService::save( $config_to_save, $data['bunny'] );
			if ( ! $bunny_result['success'] ) {
				return $bunny_result;
			}
			$config_to_save = $bunny_result['config'];
		}

		// Update defaults.
		if ( isset( $data['defaults'] ) ) {
			$config_to_save['defaults'] = array_merge( $config_to_save['defaults'], $data['defaults'] );
		}

		// Save to database.
		$saved = StreamConfig::save( $config_to_save );

		if ( ! $saved ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to save configuration.', 'fchub-stream' ),
			);
		}

		$result = array(
			'success' => true,
			'message' => __( 'Configuration saved successfully.', 'fchub-stream' ),
		);

		// Test connection if requested.
		if ( $test_connection ) {
			$provider = $data['provider'] ?? $config_to_save['provider'] ?? 'cloudflare';

			if ( 'bunny' === $provider && isset( $data['bunny'] ) ) {
				$test_result           = BunnyConfigService::test_connection( $config_to_save );
				$result['test_result'] = $test_result;

				// Update test status in config.
				$config_to_save['bunny']['last_tested_at'] = current_time( 'mysql' );
				$config_to_save['bunny']['test_status']    = $test_result['status'];
				$config_to_save['bunny']['test_error']     = 'error' === $test_result['status']
					? $test_result['message']
					: null;
			} else {
				$test_result           = CloudflareConfigService::test_connection( $config_to_save );
				$result['test_result'] = $test_result;

				// Update test status in config.
				$config_to_save['cloudflare']['last_tested_at'] = current_time( 'mysql' );
				$config_to_save['cloudflare']['test_status']    = $test_result['status'];
				$config_to_save['cloudflare']['test_error']     = 'error' === $test_result['status']
					? $test_result['message']
					: null;
			}

			StreamConfig::save( $config_to_save );
		}

		return $result;
	}

	/**
	 * Test Cloudflare API connection.
	 *
	 * Delegates to CloudflareConfigService to verify API credentials
	 * by making a test request to the Cloudflare Stream API.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $config Optional configuration to test. Uses saved config if null.
	 *
	 * @return array {
	 *     Test result.
	 *
	 *     @type string $status   Test status ('success' or 'error').
	 *     @type string $message  Success or error message.
	 * }
	 */
	public static function test_connection( ?array $config = null ): array {
		return CloudflareConfigService::test_connection( $config );
	}

	/**
	 * Test Bunny.net API connection.
	 *
	 * Delegates to BunnyConfigService to verify API credentials
	 * by making a test request to the Bunny.net Stream API.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $config Optional configuration to test. Uses saved config if null.
	 *
	 * @return array {
	 *     Test result.
	 *
	 *     @type string $status   Test status ('success' or 'error').
	 *     @type string $message  Success or error message.
	 * }
	 */
	public static function test_bunny_connection( ?array $config = null ): array {
		return BunnyConfigService::test_connection( $config );
	}

	/**
	 * Get enabled provider name.
	 *
	 * Returns the currently enabled stream provider name with proper suffix.
	 * Checks if the provider is both selected and enabled in configuration.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null Provider name ('cloudflare_stream' or 'bunny_stream'), or null if no provider enabled.
	 */
	public static function get_enabled_provider(): ?string {
		$config = self::get_private();

		// Get selected provider from config.
		$provider = $config['provider'] ?? 'cloudflare';

		// Check if provider is enabled.
		if ( 'cloudflare' === $provider ) {
			$is_enabled = ! empty( $config['cloudflare']['enabled'] );
			return $is_enabled ? 'cloudflare_stream' : null;
		}

		if ( 'bunny' === $provider ) {
			$is_enabled = ! empty( $config['bunny']['enabled'] );
			return $is_enabled ? 'bunny_stream' : null;
		}

		return null;
	}

	/**
	 * Get Cloudflare configuration.
	 *
	 * Returns Cloudflare-specific configuration with decrypted credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return array Cloudflare configuration array.
	 */
	public static function get_cloudflare_config(): array {
		$config = self::get_private();
		return $config['cloudflare'] ?? array();
	}

	/**
	 * Get Bunny.net configuration.
	 *
	 * Returns Bunny.net-specific configuration with decrypted credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return array Bunny.net configuration array.
	 */
	public static function get_bunny_config(): array {
		$config = self::get_private();
		return $config['bunny'] ?? array();
	}

	/**
	 * Get comment video settings.
	 *
	 * Returns configuration specific to video uploads in comments.
	 * Only returns enabled flag - all other settings are inherited from upload settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Comment video settings.
	 *
	 *     @type bool $enabled Whether video uploads in comments are enabled.
	 * }
	 */
	public static function get_comment_video_settings(): array {
		$config   = StreamConfig::get();
		$defaults = StreamConfig::get_defaults();

		// Return comment_video settings with fallback to defaults.
		return $config['comment_video'] ?? $defaults['comment_video'] ?? array(
			'enabled' => true,
		);
	}

	/**
	 * Check if video in comments is enabled.
	 *
	 * Returns true if video uploads in comments are currently enabled
	 * in the plugin settings.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether video in comments is enabled.
	 */
	public static function is_comment_video_enabled(): bool {
		$settings = self::get_comment_video_settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Save comment video settings.
	 *
	 * Updates the comment video configuration settings (only enabled flag).
	 * All other settings (size, format, duration) are inherited from main upload settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array $comment_video_settings {
	 *     Comment video settings to save.
	 *
	 *     @type bool $enabled Whether to enable video in comments.
	 * }
	 *
	 * @return array {
	 *     Save operation result.
	 *
	 *     @type bool   $success  Whether save succeeded.
	 *     @type string $message  Success or error message.
	 * }
	 */
	public static function save_comment_video_settings( array $comment_video_settings ): array {
		$current_config = StreamConfig::get();
		$defaults       = StreamConfig::get_defaults();

		// Ensure default structure exists.
		$current_config = array_merge( $defaults, $current_config );

		// Set comment_video enabled flag.
		$current_config['comment_video'] = array(
			'enabled' => ! empty( $comment_video_settings['enabled'] ),
		);

		// Save to database.
		$saved = StreamConfig::save( $current_config );

		if ( ! $saved ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to save comment video settings.', 'fchub-stream' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Comment video settings saved successfully.', 'fchub-stream' ),
		);
	}

	/**
	 * Validate configuration data.
	 *
	 * Validates provider selection and delegates provider-specific
	 * validation to CloudflareConfigService or BunnyConfigService.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data {
	 *     Configuration data to validate.
	 *
	 *     @type string $provider    Provider name ('cloudflare' or 'bunny').
	 *     @type array  $cloudflare  Cloudflare configuration to validate.
	 *     @type array  $bunny       Bunny.net configuration to validate.
	 * }
	 *
	 * @return array {
	 *     Validation result.
	 *
	 *     @type bool  $valid   Whether configuration is valid.
	 *     @type array $errors  Array of error messages (empty if valid).
	 * }
	 */
	public static function validate( array $data ): array {
		$errors = array();

		// Validate provider.
		if ( isset( $data['provider'] ) && ! in_array( $data['provider'], array( 'cloudflare', 'bunny' ), true ) ) {
			$errors[] = __( 'Invalid provider. Supported: cloudflare, bunny', 'fchub-stream' );
		}

		// Validate Cloudflare settings (if provided).
		if ( isset( $data['cloudflare'] ) ) {
			$cloudflare_errors = CloudflareConfigService::validate( $data['cloudflare'] );
			$errors            = array_merge( $errors, $cloudflare_errors );
		}

		// Validate Bunny.net settings (if provided).
		if ( isset( $data['bunny'] ) ) {
			$bunny_errors = BunnyConfigService::validate( $data['bunny'] );
			$errors       = array_merge( $errors, $bunny_errors );
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}
}
