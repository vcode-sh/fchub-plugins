<?php
/**
 * Stream Configuration Transformer.
 *
 * Handles transformation operations on stream configuration including
 * sanitization, merging with defaults, and data validation.
 *
 * @package FCHub_Stream
 * @subpackage Models
 * @since 1.0.0
 */

namespace FCHubStream\App\Models;

/**
 * Configuration Transformer Class.
 *
 * Provides transformation utilities for stream configuration data
 * including comprehensive sanitization and intelligent merging.
 *
 * @since 1.0.0
 */
class ConfigTransformer {

	/**
	 * Merge configuration with defaults.
	 *
	 * Performs deep merge of configuration with default values to ensure
	 * all required keys exist. Handles nested arrays properly.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config   Configuration data to merge.
	 * @param array $defaults Default configuration structure.
	 *
	 * @return array Complete configuration with all defaults merged.
	 */
	public static function merge_with_defaults( array $config, array $defaults ) {
		if ( empty( $config ) ) {
			return $defaults;
		}

		$merged = array_merge( $defaults, $config );

		// Deep merge for nested arrays.
		if ( isset( $config['cloudflare'] ) ) {
			$merged['cloudflare'] = array_merge( $defaults['cloudflare'], $config['cloudflare'] );
		}

		if ( isset( $config['bunny'] ) ) {
			$merged['bunny'] = array_merge( $defaults['bunny'], $config['bunny'] );
		}

		if ( isset( $config['defaults'] ) ) {
			$merged['defaults'] = array_merge( $defaults['defaults'], $config['defaults'] );
		}

		if ( isset( $config['comment_video'] ) ) {
			$merged['comment_video'] = array_merge( $defaults['comment_video'], $config['comment_video'] );
		}

		if ( isset( $config['webhook'] ) ) {
			$merged['webhook'] = array_merge( $defaults['webhook'], $config['webhook'] );
		}

		return $merged;
	}

	/**
	 * Sanitize configuration data.
	 *
	 * Performs comprehensive sanitization of configuration values while
	 * preserving encrypted credentials (which should not be sanitized).
	 * Ensures data types are correct and prevents XSS attacks.
	 *
	 * Sanitization rules:
	 * - Text fields: sanitize_text_field()
	 * - URLs: esc_url_raw()
	 * - Numbers: absint()
	 * - Booleans: type cast to bool
	 * - Encrypted fields: preserved as-is
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Raw configuration data.
	 *
	 * @return array Sanitized configuration data.
	 */
	public static function sanitize( array $config ) {
		$sanitized = array();

		// Provider.
		if ( isset( $config['provider'] ) ) {
			$sanitized['provider'] = sanitize_text_field( $config['provider'] );
		}

		// Cloudflare settings.
		if ( isset( $config['cloudflare'] ) && is_array( $config['cloudflare'] ) ) {
			$sanitized['cloudflare'] = self::sanitize_cloudflare( $config['cloudflare'] );
		}

		// Bunny.net settings.
		if ( isset( $config['bunny'] ) && is_array( $config['bunny'] ) ) {
			$sanitized['bunny'] = self::sanitize_bunny( $config['bunny'] );
		}

		// Defaults.
		if ( isset( $config['defaults'] ) && is_array( $config['defaults'] ) ) {
			$sanitized['defaults'] = self::sanitize_defaults( $config['defaults'] );
		}

		// Comment video settings.
		if ( isset( $config['comment_video'] ) && is_array( $config['comment_video'] ) ) {
			$sanitized['comment_video'] = self::sanitize_comment_video( $config['comment_video'] );
		}

		// Webhook settings.
		if ( isset( $config['webhook'] ) && is_array( $config['webhook'] ) ) {
			$sanitized['webhook'] = self::sanitize_webhook( $config['webhook'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize Cloudflare configuration.
	 *
	 * Sanitizes Cloudflare-specific settings while preserving encrypted credentials.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $cloudflare Raw Cloudflare configuration.
	 *
	 * @return array Sanitized Cloudflare configuration.
	 */
	private static function sanitize_cloudflare( array $cloudflare ) {
		return array(
			'account_id'         => isset( $cloudflare['account_id'] )
				? sanitize_text_field( $cloudflare['account_id'] )
				: '',
			'api_token'          => isset( $cloudflare['api_token'] )
				? $cloudflare['api_token'] // Don't sanitize encrypted token.
				: '',
			'customer_subdomain' => isset( $cloudflare['customer_subdomain'] )
				? self::normalize_customer_subdomain( (string) $cloudflare['customer_subdomain'] )
				: '',
			'webhook_secret'     => isset( $cloudflare['webhook_secret'] )
				? $cloudflare['webhook_secret'] // Don't sanitize encrypted secret.
				: '',
			'enabled'            => isset( $cloudflare['enabled'] )
				? (bool) $cloudflare['enabled']
				: false,
			'configured_at'      => isset( $cloudflare['configured_at'] )
				? sanitize_text_field( $cloudflare['configured_at'] )
				: current_time( 'mysql' ),
			'last_tested_at'     => isset( $cloudflare['last_tested_at'] )
				? sanitize_text_field( $cloudflare['last_tested_at'] )
				: null,
			'test_status'        => isset( $cloudflare['test_status'] )
				? sanitize_text_field( $cloudflare['test_status'] )
				: null,
			'test_error'         => isset( $cloudflare['test_error'] )
				? sanitize_text_field( $cloudflare['test_error'] )
				: null,
		);
	}

	/**
	 * Sanitize Bunny.net configuration.
	 *
	 * Sanitizes Bunny.net-specific settings while preserving encrypted credentials.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $bunny Raw Bunny.net configuration.
	 *
	 * @return array Sanitized Bunny.net configuration.
	 */
	private static function sanitize_bunny( array $bunny ) {
		return array(
			'library_id'     => isset( $bunny['library_id'] )
				? sanitize_text_field( $bunny['library_id'] )
				: '',
			'api_key'        => isset( $bunny['api_key'] )
				? $bunny['api_key'] // Don't sanitize encrypted key.
				: '',
			'collection_id'  => isset( $bunny['collection_id'] )
				? sanitize_text_field( $bunny['collection_id'] )
				: '',
			'enabled'        => isset( $bunny['enabled'] )
				? (bool) $bunny['enabled']
				: false,
			'configured_at'  => isset( $bunny['configured_at'] )
				? sanitize_text_field( $bunny['configured_at'] )
				: current_time( 'mysql' ),
			'last_tested_at' => isset( $bunny['last_tested_at'] )
				? sanitize_text_field( $bunny['last_tested_at'] )
				: null,
			'test_status'    => isset( $bunny['test_status'] )
				? sanitize_text_field( $bunny['test_status'] )
				: null,
			'test_error'     => isset( $bunny['test_error'] )
				? sanitize_text_field( $bunny['test_error'] )
				: null,
		);
	}

	/**
	 * Sanitize upload defaults configuration.
	 *
	 * Sanitizes upload settings ensuring proper data types.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $defaults Raw upload defaults configuration.
	 *
	 * @return array Sanitized upload defaults configuration.
	 */
	private static function sanitize_defaults( array $defaults ) {
		return array(
			'max_duration_seconds' => isset( $defaults['max_duration_seconds'] )
				? absint( $defaults['max_duration_seconds'] )
				: 3600,
			'allowed_formats'      => isset( $defaults['allowed_formats'] )
				? array_map( 'sanitize_text_field', (array) $defaults['allowed_formats'] )
				: array( 'mp4', 'mov', 'webm' ),
			'max_file_size_mb'     => isset( $defaults['max_file_size_mb'] )
				? absint( $defaults['max_file_size_mb'] )
				: 500,
		);
	}

	/**
	 * Sanitize webhook configuration.
	 *
	 * Sanitizes webhook settings with proper URL and text sanitization.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $webhook Raw webhook configuration.
	 *
	 * @return array Sanitized webhook configuration.
	 */
	private static function sanitize_webhook( array $webhook ) {
		return array(
			'enabled' => isset( $webhook['enabled'] )
				? (bool) $webhook['enabled']
				: false,
			'url'     => isset( $webhook['url'] )
				? esc_url_raw( $webhook['url'] )
				: '',
			'secret'  => isset( $webhook['secret'] )
				? sanitize_text_field( $webhook['secret'] )
				: '',
		);
	}

	/**
	 * Normalize customer subdomain.
	 *
	 * Extracts only the subdomain part from customer_subdomain value.
	 * Handles both full URLs and domain-only formats.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string $customer_subdomain Raw customer subdomain value.
	 *
	 * @return string Normalized subdomain (e.g., 'customer-abc123').
	 */
	private static function normalize_customer_subdomain( string $customer_subdomain ): string {
		$normalized = sanitize_text_field( $customer_subdomain );

		// If contains full URL, extract just the subdomain part.
		if ( preg_match( '/https?:\/\/(customer-[a-z0-9]+)\.cloudflarestream\.com/', $normalized, $matches ) ) {
			return $matches[1];
		}

		// If contains domain without protocol, extract subdomain.
		if ( preg_match( '/^(customer-[a-z0-9]+)\.cloudflarestream\.com/', $normalized, $matches ) ) {
			return $matches[1];
		}

		// Otherwise assume it's already just the subdomain (customer-xxx).
		return $normalized;
	}

	/**
	 * Sanitize comment video configuration.
	 *
	 * Sanitizes comment video settings (only enabled flag).
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param array $comment_video Raw comment video configuration.
	 *
	 * @return array Sanitized comment video configuration.
	 */
	private static function sanitize_comment_video( array $comment_video ) {
		return array(
			'enabled' => isset( $comment_video['enabled'] )
				? (bool) $comment_video['enabled']
				: true,
		);
	}

}
