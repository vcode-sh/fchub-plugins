<?php
/**
 * Stream Configuration Defaults.
 *
 * Provides default configuration structure for all stream providers.
 * Defines the complete schema for stream configuration including
 * provider settings, upload defaults, and webhook configuration.
 *
 * @package FCHub_Stream
 * @subpackage Models
 * @since 1.0.0
 */

namespace FCHubStream\App\Models;

/**
 * Configuration Defaults Class.
 *
 * Centralized definition of default configuration values for the plugin.
 * Ensures consistent configuration structure across all providers.
 *
 * @since 1.0.0
 */
class ConfigDefaults {

	/**
	 * Get default configuration structure.
	 *
	 * Returns the complete default configuration for all stream providers
	 * including Cloudflare Stream, Bunny.net, upload defaults, and webhook settings.
	 *
	 * Configuration includes:
	 * - Cloudflare Stream settings (account ID, API token, webhook secret)
	 * - Bunny.net Stream settings (library ID, API key, collection ID)
	 * - Upload defaults (max duration, allowed formats, max file size)
	 * - Webhook configuration for video processing callbacks
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Default configuration structure.
	 *
	 *     @type string $provider        Active provider ('cloudflare' or 'bunny').
	 *     @type array  $cloudflare {
	 *         Cloudflare Stream configuration.
	 *
	 *         @type string      $account_id         Cloudflare account ID.
	 *         @type string      $api_token          Encrypted API token.
	 *         @type string      $customer_subdomain Customer subdomain (e.g., 'customer-abc123').
	 *         @type string      $webhook_secret     Encrypted webhook secret.
	 *         @type bool        $enabled            Whether provider is enabled.
	 *         @type string|null $configured_at      Configuration timestamp.
	 *         @type string|null $last_tested_at     Last connection test timestamp.
	 *         @type string|null $test_status        Test result (success/error/pending).
	 *         @type string|null $test_error         Error message from last test.
	 *     }
	 *     @type array  $bunny {
	 *         Bunny.net Stream configuration.
	 *
	 *         @type string      $library_id     Bunny.net Stream library ID.
	 *         @type string      $api_key        Encrypted API key.
	 *         @type string      $collection_id  Optional collection ID.
	 *         @type bool        $enabled        Whether provider is enabled.
	 *         @type string|null $configured_at  Configuration timestamp.
	 *         @type string|null $last_tested_at Last connection test timestamp.
	 *         @type string|null $test_status    Test result (success/error/pending).
	 *         @type string|null $test_error     Error message from last test.
	 *     }
	 *     @type array  $defaults {
	 *         Upload default settings.
	 *
	 *         @type int   $max_duration_seconds Maximum video duration in seconds.
	 *         @type array $allowed_formats      Allowed video file formats.
	 *         @type int   $max_file_size_mb     Maximum file size in megabytes.
	 *     }
	 *     @type array  $webhook {
	 *         Webhook configuration.
	 *
	 *         @type bool   $enabled Whether webhooks are enabled.
	 *         @type string $url     Webhook endpoint URL.
	 *         @type string $secret  Webhook verification secret.
	 *     }
	 * }
	 */
	public static function get() {
		return array(
			'provider'      => 'cloudflare',
			'cloudflare'    => self::get_cloudflare_defaults(),
			'bunny'         => self::get_bunny_defaults(),
			'defaults'      => self::get_upload_defaults(),
			'comment_video' => self::get_comment_video_defaults(),
			'webhook'       => self::get_webhook_defaults(),
		);
	}

	/**
	 * Get Cloudflare provider defaults.
	 *
	 * Returns default configuration structure for Cloudflare Stream provider.
	 *
	 * @since 1.0.0
	 *
	 * @return array Cloudflare provider defaults.
	 */
	public static function get_cloudflare_defaults() {
		return array(
			'account_id'         => '',
			'api_token'          => '',
			'customer_subdomain' => '',
			'webhook_secret'     => '',
			'enabled'            => false,
			'configured_at'      => null,
			'last_tested_at'     => null,
			'test_status'        => null, // success|error|pending.
			'test_error'         => null,
		);
	}

	/**
	 * Get Bunny.net provider defaults.
	 *
	 * Returns default configuration structure for Bunny.net Stream provider.
	 *
	 * @since 1.0.0
	 *
	 * @return array Bunny.net provider defaults.
	 */
	public static function get_bunny_defaults() {
		return array(
			'library_id'     => '',
			'api_key'        => '',
			'collection_id'  => '',
			'enabled'        => false,
			'configured_at'  => null,
			'last_tested_at' => null,
			'test_status'    => null, // success|error|pending.
			'test_error'     => null,
		);
	}

	/**
	 * Get upload defaults.
	 *
	 * Returns default settings for video uploads including
	 * duration limits, allowed formats, and file size restrictions.
	 *
	 * @since 1.0.0
	 *
	 * @return array Upload defaults.
	 */
	public static function get_upload_defaults() {
		return array(
			'max_duration_seconds' => 3600, // 1 hour.
			'allowed_formats'      => array( 'mp4', 'mov', 'webm' ),
			'max_file_size_mb'     => 500,
		);
	}

	/**
	 * Get comment video defaults.
	 *
	 * Returns default configuration for video uploads in comments.
	 * Only controls enabled/disabled flag - all other settings (size, format, duration)
	 * are inherited from main upload settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Comment video defaults.
	 *
	 *     @type bool $enabled Whether video uploads in comments are enabled.
	 * }
	 */
	public static function get_comment_video_defaults() {
		return array(
			'enabled' => true,
		);
	}

	/**
	 * Get webhook defaults.
	 *
	 * Returns default configuration for webhook integration.
	 *
	 * @since 1.0.0
	 *
	 * @return array Webhook defaults.
	 */
	public static function get_webhook_defaults() {
		return array(
			'enabled' => false,
			'url'     => '',
			'secret'  => '',
		);
	}

}
