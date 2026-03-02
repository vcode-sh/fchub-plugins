<?php
/**
 * Unit tests for Stream Configuration.
 *
 * Tests the encryption service, configuration management,
 * validation, and sanitization for the Stream Config system.
 *
 * @package FCHub_Stream
 * @subpackage Tests
 * @since 0.0.1
 */

namespace FCHubStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FCHubStream\App\Utils\EncryptionService;
use FCHubStream\App\Models\StreamConfig;
use FCHubStream\App\Services\StreamConfigService;

/**
 * Unit tests for Stream Configuration.
 *
 * @since 0.0.1
 *
 * @covers \FCHubStream\App\Utils\EncryptionService
 * @covers \FCHubStream\App\Models\StreamConfig
 * @covers \FCHubStream\App\Services\StreamConfigService
 */
class StreamConfigTest extends TestCase {
	/**
	 * Test encryption service availability.
	 *
	 * Verifies that the EncryptionService is properly initialized
	 * and available for use in the plugin.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Utils\EncryptionService::is_available
	 *
	 * @return void
	 */
	public function test_encryption_service_is_available() {
		$this->assertTrue( EncryptionService::is_available() );
	}

	/**
	 * Test encryption and decryption.
	 *
	 * Verifies that sensitive data can be encrypted and decrypted correctly
	 * using the EncryptionService, ensuring data integrity.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Utils\EncryptionService::encrypt
	 * @covers \FCHubStream\App\Utils\EncryptionService::decrypt
	 *
	 * @return void
	 */
	public function test_encryption_decryption() {
		$original  = 'test-api-token-12345';
		$encrypted = EncryptionService::encrypt( $original );

		$this->assertNotFalse( $encrypted );
		$this->assertNotEquals( $original, $encrypted );

		$decrypted = EncryptionService::decrypt( $encrypted );

		$this->assertEquals( $original, $decrypted );
	}

	/**
	 * Test empty string encryption.
	 *
	 * Verifies that encrypting and decrypting empty strings
	 * is handled correctly without errors.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Utils\EncryptionService::encrypt
	 * @covers \FCHubStream\App\Utils\EncryptionService::decrypt
	 *
	 * @return void
	 */
	public function test_empty_string_encryption() {
		$encrypted = EncryptionService::encrypt( '' );
		$this->assertEquals( '', $encrypted );

		$decrypted = EncryptionService::decrypt( '' );
		$this->assertEquals( '', $decrypted );
	}

	/**
	 * Test configuration defaults.
	 *
	 * Verifies that StreamConfig returns the expected default
	 * configuration structure with all required fields.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Models\StreamConfig::getDefaults
	 *
	 * @return void
	 */
	public function test_config_defaults() {
		$defaults = StreamConfig::get_defaults();

		$this->assertArrayHasKey( 'provider', $defaults );
		$this->assertArrayHasKey( 'cloudflare', $defaults );
		$this->assertArrayHasKey( 'defaults', $defaults );
		$this->assertEquals( 'cloudflare', $defaults['provider'] );
	}

	/**
	 * Test configuration validation.
	 *
	 * Verifies that StreamConfigService properly validates configuration
	 * data, accepting valid configs and rejecting invalid ones.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Services\StreamConfigService::validate
	 *
	 * @return void
	 */
	public function test_config_validation() {
		// Valid config.
		$valid_config = array(
			'provider'   => 'cloudflare',
			'cloudflare' => array(
				'account_id' => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
				'api_token'  => 'test-token',
			),
		);

		$validation = StreamConfigService::validate( $valid_config );
		$this->assertTrue( $validation['valid'] );
		$this->assertEmpty( $validation['errors'] );

		// Invalid account ID format.
		$invalid_config = array(
			'provider'   => 'cloudflare',
			'cloudflare' => array(
				'account_id' => 'invalid-format',
				'api_token'  => 'test-token',
			),
		);

		$validation = StreamConfigService::validate( $invalid_config );
		$this->assertFalse( $validation['valid'] );
		$this->assertNotEmpty( $validation['errors'] );
	}

	/**
	 * Test configuration sanitization.
	 *
	 * Verifies that StreamConfig properly sanitizes user input,
	 * removing XSS attempts and normalizing data types.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Models\StreamConfig::save
	 * @covers \FCHubStream\App\Models\StreamConfig::get
	 *
	 * @return void
	 */
	public function test_config_sanitization() {
		$config = array(
			'provider'   => 'cloudflare',
			'cloudflare' => array(
				'account_id' => '<script>alert("xss")</script>a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
				'api_token'  => 'test-token',
				'enabled'    => '1',
			),
			'defaults'   => array(
				'max_duration_seconds' => '3600',
				'max_file_size_mb'     => '500',
			),
		);

		StreamConfig::save( $config );
		$saved = StreamConfig::get();

		// Check that script tags are removed.
		$this->assertStringNotContainsString( '<script>', $saved['cloudflare']['account_id'] );

		// Check that enabled is boolean.
		$this->assertIsBool( $saved['cloudflare']['enabled'] );

		// Check that numbers are integers.
		$this->assertIsInt( $saved['defaults']['max_duration_seconds'] );
		$this->assertIsInt( $saved['defaults']['max_file_size_mb'] );
	}
}

