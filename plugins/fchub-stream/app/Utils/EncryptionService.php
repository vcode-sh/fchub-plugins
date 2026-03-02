<?php
/**
 * Encryption service for sensitive data.
 *
 * @package FCHubStream
 * @subpackage Utils
 * @since 1.0.0
 */

namespace FCHubStream\App\Utils;

/**
 * Encryption service for sensitive data.
 *
 * Provides AES-256-CBC encryption for API keys and tokens using
 * WordPress authentication salts as encryption keys. This service
 * protects sensitive configuration data such as API tokens and
 * webhook secrets for video streaming providers.
 *
 * Security Implementation:
 * - Algorithm: AES-256-CBC (Advanced Encryption Standard with 256-bit key)
 * - Key Derivation: Uses WordPress wp_salt() function with unique scheme
 * - IV Generation: Derived from WordPress salts (16 bytes for AES-256-CBC)
 * - Encoding: Base64 encoding for safe storage in WordPress options
 *
 * Critical Security Warnings:
 * - Changing WordPress salts will break decryption of existing encrypted data
 * - All previously encrypted API keys and tokens will become unrecoverable
 * - The same salt scheme must be used for both encryption and decryption
 * - Requires OpenSSL PHP extension for encryption operations
 *
 * Dependencies:
 * - WordPress wp_salt() function for key derivation
 * - OpenSSL PHP extension (openssl_encrypt/openssl_decrypt functions)
 *
 * @package FCHub_Stream
 * @subpackage Utils
 * @since 1.0.0
 */
class EncryptionService {
	/**
	 * Get encryption key from WordPress salts.
	 *
	 * Derives a cryptographic key from WordPress salts using a unique
	 * scheme identifier. The wp_salt() function generates a consistent
	 * salt based on WordPress security constants.
	 *
	 * Warning: Changing WordPress salts or the scheme identifier will
	 * break decryption of all existing encrypted data.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @return string Encryption key derived from WordPress salts.
	 */
	private static function get_encryption_key() {
		// Check if wp_salt() is available (WordPress must be loaded).
		if ( ! function_exists( 'wp_salt' ) ) {
			error_log( '[FCHub Stream] wp_salt() not available - WordPress not fully loaded' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		return \wp_salt( 'fchub_stream_encryption' );
	}

	/**
	 * Get initialization vector from WordPress salts.
	 *
	 * Derives a 16-byte initialization vector (IV) from WordPress salts.
	 * AES-256-CBC requires a 16-byte IV for proper operation. The IV
	 * is truncated to exactly 16 bytes to meet algorithm requirements.
	 *
	 * Warning: Changing the IV scheme will break decryption of all
	 * existing encrypted data.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @return string 16-byte initialization vector for AES-256-CBC.
	 */
	private static function get_iv() {
		// Check if wp_salt() is available (WordPress must be loaded).
		if ( ! function_exists( 'wp_salt' ) ) {
			error_log( '[FCHub Stream] wp_salt() not available - WordPress not fully loaded' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		$salt = \wp_salt( 'fchub_stream_iv' );
		return substr( $salt, 0, 16 ); // AES-256-CBC requires 16-byte IV.
	}

	/**
	 * Encrypt sensitive data using AES-256-CBC.
	 *
	 * Encrypts plain text data using AES-256-CBC algorithm with
	 * WordPress salt-derived encryption key and initialization vector.
	 * The encrypted data is base64 encoded for safe storage in
	 * WordPress options table.
	 *
	 * Security Features:
	 * - AES-256-CBC encryption algorithm
	 * - Key derived from WordPress salts
	 * - Base64 encoding for storage safety
	 * - OpenSSL implementation for cryptographic security
	 *
	 * Use Cases:
	 * - API tokens for Cloudflare Stream and Bunny.net
	 * - Webhook secrets for video processing callbacks
	 * - Any sensitive configuration credentials
	 *
	 * @since 1.0.0
	 *
	 * @param string $data Plain text data to encrypt (API keys, tokens, secrets).
	 *
	 * @return string|false Base64 encoded encrypted data on success, false on failure, empty string for empty input.
	 */
	public static function encrypt( $data ) {
		if ( empty( $data ) ) {
			return '';
		}

		$key = self::get_encryption_key();
		$iv  = self::get_iv();

		// If wp_salt() is not available, we can't encrypt.
		if ( false === $key || false === $iv ) {
			error_log( '[FCHub Stream] Cannot encrypt - wp_salt() not available (WordPress not fully loaded)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			error_log( '[FCHub Stream] OpenSSL not available for encryption' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$encrypted = openssl_encrypt(
			$data,
			'AES-256-CBC',
			$key,
			0,
			$iv
		);

		if ( false === $encrypted ) {
			error_log( '[FCHub Stream] Encryption failed: ' . openssl_error_string() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return base64_encode( $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt sensitive data encrypted with AES-256-CBC.
	 *
	 * Decrypts base64 encoded data that was encrypted using the
	 * encrypt() method. Uses the same WordPress salt-derived key
	 * and initialization vector as the encryption process.
	 *
	 * Security Requirements:
	 * - WordPress salts must not have changed since encryption
	 * - Data must be properly base64 encoded
	 * - OpenSSL extension must be available
	 *
	 * Common Failure Scenarios:
	 * - WordPress salts have been regenerated
	 * - Data was not encrypted with this service
	 * - Base64 encoding is corrupted
	 * - OpenSSL extension is not available
	 *
	 * @since 1.0.0
	 *
	 * @param string $encrypted_data Base64 encoded encrypted data to decrypt.
	 *
	 * @return string|false Decrypted plain text data on success, false on failure, empty string for empty input.
	 */
	public static function decrypt( $encrypted_data ) {
		if ( empty( $encrypted_data ) ) {
			return '';
		}

		$key = self::get_encryption_key();
		$iv  = self::get_iv();

		// If wp_salt() is not available, we can't decrypt.
		if ( false === $key || false === $iv ) {
			error_log( '[FCHub Stream] Cannot decrypt - wp_salt() not available (WordPress not fully loaded)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			error_log( '[FCHub Stream] OpenSSL not available for decryption' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$decoded = base64_decode( $encrypted_data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			error_log( '[FCHub Stream] Failed to decode base64 encrypted data' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$decrypted = openssl_decrypt(
			$decoded,
			'AES-256-CBC',
			$key,
			0,
			$iv
		);

		if ( false === $decrypted ) {
			error_log( '[FCHub Stream] Decryption failed: ' . openssl_error_string() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return $decrypted;
	}

	/**
	 * Check if encryption functionality is available.
	 *
	 * Verifies that the OpenSSL PHP extension is loaded and the
	 * required encryption/decryption functions are available.
	 * Should be called before attempting encryption operations
	 * to ensure compatibility.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if OpenSSL encryption is available, false otherwise.
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}
}
