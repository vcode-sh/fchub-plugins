<?php
/**
 * Unit tests for Portal Integration.
 *
 * Tests the PortalIntegration class which handles integration with FluentCommunity Portal,
 * including hook registration, asset management, shortcode processing, and video rendering.
 *
 * @package FCHub_Stream
 * @subpackage Tests
 * @since 0.0.1
 */

namespace FCHubStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FCHubStream\App\Hooks\PortalIntegration;
use FCHubStream\App\Services\StreamConfigService;

/**
 * Unit tests for Portal Integration.
 *
 * @since 0.0.1
 *
 * @covers \FCHubStream\App\Hooks\PortalIntegration
 */
class PortalIntegrationTest extends TestCase {

	/**
	 * PortalIntegration instance for testing.
	 *
	 * @since 0.0.1
	 * @var PortalIntegration
	 */
	private $portal_integration;

	/**
	 * Set up test environment.
	 *
	 * Runs before each test method.
	 * Initializes PortalIntegration instance and resets global state.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset global WordPress arrays.
		global $wp_filters, $wp_actions, $wp_options;
		$wp_filters = array();
		$wp_actions = array();
		$wp_options = array();

		// Define plugin constants if not defined.
		if ( ! defined( 'FCHUB_STREAM_DIR' ) ) {
			define( 'FCHUB_STREAM_DIR', dirname( __DIR__, 2 ) . '/' );
		}
		if ( ! defined( 'FCHUB_STREAM_URL' ) ) {
			define( 'FCHUB_STREAM_URL', 'https://example.com/wp-content/plugins/fchub-stream/' );
		}

		// Create instance.
		$this->portal_integration = new PortalIntegration();
	}

	/**
	 * Tear down test environment.
	 *
	 * Runs after each test method.
	 * Cleans up test data and resets global state.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		parent::tearDown();

		// Reset global WordPress arrays.
		global $wp_filters, $wp_actions, $wp_options;
		$wp_filters = array();
		$wp_actions = array();
		$wp_options = array();
	}

	/**
	 * Test that PortalIntegration can be instantiated.
	 *
	 * Verifies that the PortalIntegration class can be instantiated
	 * without errors and returns a proper object instance.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::__construct
	 *
	 * @return void
	 */
	public function test_can_instantiate() {
		$this->assertInstanceOf( PortalIntegration::class, $this->portal_integration );
	}

	/**
	 * Test hook registration.
	 *
	 * Verifies that the register() method properly registers all WordPress
	 * filters and actions used by PortalIntegration.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::register
	 *
	 * @return void
	 */
	public function test_register_hooks() {
		global $wp_filters, $wp_actions;

		// Register hooks.
		$this->portal_integration->register();

		// Check filters are registered.
		$this->assertArrayHasKey( 'fluent_community/portal_data_vars', $wp_filters );
		$this->assertArrayHasKey( 'fluent_community/portal_vars', $wp_filters );
		$this->assertArrayHasKey( 'fluent_community/general_portal_vars', $wp_filters );
		$this->assertArrayHasKey( 'fluent_community/feed/new_feed_data', $wp_filters );
		$this->assertArrayHasKey( 'fluent_community/feed_api_response', $wp_filters );
		$this->assertArrayHasKey( 'fluent_community/feeds_api_response', $wp_filters );
		$this->assertArrayHasKey( 'fluent_community/support_attachment_types', $wp_filters );
		$this->assertArrayHasKey( 'wp_kses_allowed_html', $wp_filters );

		// Check actions are registered.
		$this->assertArrayHasKey( 'fluent_community/comment_added', $wp_actions );
	}

	/**
	 * Test add_portal_scripts method.
	 *
	 * Verifies that portal scripts are correctly added to the data_vars array
	 * with proper URLs and cache busting.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::add_portal_scripts
	 *
	 * @return void
	 */
	public function test_add_portal_scripts() {
		$data_vars = array();

		$result = $this->portal_integration->add_portal_scripts( $data_vars );

		// Check that js_files key is added.
		$this->assertArrayHasKey( 'js_files', $result );

		// Check that fchub_stream_portal is added.
		if ( isset( $result['js_files']['fchub_stream_portal'] ) ) {
			$this->assertArrayHasKey( 'url', $result['js_files']['fchub_stream_portal'] );
			$this->assertArrayHasKey( 'deps', $result['js_files']['fchub_stream_portal'] );
			$this->assertStringContainsString( 'fchub-stream-portal.js', $result['js_files']['fchub_stream_portal']['url'] );
		}
	}

	/**
	 * Test add_portal_vars method.
	 *
	 * Verifies that portal configuration variables are correctly added,
	 * including provider settings and upload configuration.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::add_portal_vars
	 *
	 * @return void
	 */
	public function test_add_portal_vars() {
		global $wp_options;

		// Mock upload settings.
		$wp_options['fchub_stream_upload_settings'] = array(
			'max_file_size'             => 500,
			'allowed_formats'           => array( 'mp4', 'mov', 'webm' ),
			'max_duration_seconds'      => 3600,
			'polling_interval'          => 30,
			'enable_upload_from_portal' => true,
		);

		$vars   = array();
		$result = $this->portal_integration->add_portal_vars( $vars );

		// Check that fchubStreamSettings is added.
		$this->assertArrayHasKey( 'fchubStreamSettings', $result );
		$this->assertArrayHasKey( 'enabled', $result['fchubStreamSettings'] );
		$this->assertArrayHasKey( 'provider', $result['fchubStreamSettings'] );
		$this->assertArrayHasKey( 'rest_url', $result['fchubStreamSettings'] );
		$this->assertArrayHasKey( 'rest_nonce', $result['fchubStreamSettings'] );
		$this->assertArrayHasKey( 'upload', $result['fchubStreamSettings'] );

		// Check upload settings.
		$upload = $result['fchubStreamSettings']['upload'];
		$this->assertArrayHasKey( 'max_file_size', $upload );
		$this->assertArrayHasKey( 'allowed_formats', $upload );
		$this->assertArrayHasKey( 'max_duration_seconds', $upload );
		$this->assertArrayHasKey( 'polling_interval', $upload );
	}

	/**
	 * Test add_video_types method.
	 *
	 * Verifies that video MIME types are correctly added to supported
	 * attachment types for FluentCommunity.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::add_video_types
	 *
	 * @return void
	 */
	public function test_add_video_types() {
		$types = array();

		$result = $this->portal_integration->add_video_types( $types );

		// Check that video types are added.
		$this->assertContains( 'video/mp4', $result );
		$this->assertContains( 'video/quicktime', $result );
		$this->assertContains( 'video/webm', $result );
		$this->assertContains( 'video/x-msvideo', $result );
	}

	/**
	 * Test allow_iframe_in_kses method.
	 *
	 * Verifies that iframe and div tags with necessary attributes are
	 * allowed in wp_kses_post for video embedding.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::allow_iframe_in_kses
	 *
	 * @return void
	 */
	public function test_allow_iframe_in_kses() {
		$allowed_tags = array();

		$result = $this->portal_integration->allow_iframe_in_kses( $allowed_tags, 'post' );

		// Check that iframe is allowed.
		$this->assertArrayHasKey( 'iframe', $result );
		$this->assertArrayHasKey( 'src', $result['iframe'] );
		$this->assertArrayHasKey( 'allowfullscreen', $result['iframe'] );

		// Check that div is allowed.
		$this->assertArrayHasKey( 'div', $result );
		$this->assertArrayHasKey( 'data-video-id', $result['div'] );
		$this->assertArrayHasKey( 'data-provider', $result['div'] );
	}

	/**
	 * Test allow_iframe_in_kses method with non-post context.
	 *
	 * Verifies that iframe tags are not added when context is not 'post'.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::allow_iframe_in_kses
	 *
	 * @return void
	 */
	public function test_allow_iframe_in_kses_non_post_context() {
		$allowed_tags = array();

		$result = $this->portal_integration->allow_iframe_in_kses( $allowed_tags, 'comment' );

		// Check that iframe is NOT added for non-post context.
		$this->assertEmpty( $result );
	}

	/**
	 * Test process_shortcodes_before_save with media object.
	 *
	 * Verifies that shortcodes in media object are processed correctly
	 * before saving a feed, creating proper media_preview metadata.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::process_shortcodes_before_save
	 *
	 * @return void
	 */
	public function test_process_shortcodes_before_save_with_media() {
		$data = array(
			'message' => 'Test post',
			'meta'    => array(),
		);

		$request_data = array(
			'media' => array(
				'html'  => '[fchub_stream:test-video-123 provider="cloudflare_stream"]',
				'image' => 'https://example.com/thumbnail.jpg',
			),
		);

		$result = $this->portal_integration->process_shortcodes_before_save( $data, $request_data );

		// Check that media_preview is created.
		$this->assertArrayHasKey( 'meta', $result );
		$this->assertArrayHasKey( 'media_preview', $result['meta'] );

		$media_preview = $result['meta']['media_preview'];
		$this->assertEquals( 'iframe_html', $media_preview['type'] );
		$this->assertEquals( 'test-video-123', $media_preview['video_id'] );
		$this->assertEquals( 'pending', $media_preview['status'] );
		$this->assertEquals( 'video', $media_preview['content_type'] );
	}

	/**
	 * Test process_shortcodes_before_save with message shortcode.
	 *
	 * Verifies that shortcodes in message text are processed correctly
	 * and removed from the message.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::process_shortcodes_before_save
	 *
	 * @return void
	 */
	public function test_process_shortcodes_before_save_with_message() {
		$data = array(
			'message'          => 'Check this out: [fchub_stream:test-video-456]',
			'message_rendered' => 'Check this out: [fchub_stream:test-video-456]',
			'meta'             => array(),
		);

		$request_data = array();

		$result = $this->portal_integration->process_shortcodes_before_save( $data, $request_data );

		// Check that shortcode is removed from message.
		$this->assertEquals( 'Check this out:', $result['message'] );
		$this->assertEquals( 'Check this out:', $result['message_rendered'] );

		// Check that media_preview is created.
		$this->assertArrayHasKey( 'media_preview', $result['meta'] );
		$this->assertEquals( 'test-video-456', $result['meta']['media_preview']['video_id'] );
	}

	/**
	 * Test process_shortcodes_before_save with no shortcode.
	 *
	 * Verifies that data is returned unchanged when no shortcode is present.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::process_shortcodes_before_save
	 *
	 * @return void
	 */
	public function test_process_shortcodes_before_save_no_shortcode() {
		$data = array(
			'message' => 'Regular post without video',
			'meta'    => array(),
		);

		$request_data = array();

		$result = $this->portal_integration->process_shortcodes_before_save( $data, $request_data );

		// Check that data is unchanged.
		$this->assertEquals( $data, $result );
	}

	/**
	 * Test process_shortcodes_in_response with valid feed.
	 *
	 * Verifies that API responses for single feeds are processed correctly,
	 * replacing shortcodes with player HTML.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::process_shortcodes_in_response
	 *
	 * @return void
	 */
	public function test_process_shortcodes_in_response() {
		$data = array(
			'feed' => array(
				'id'               => 1,
				'message_rendered' => 'Watch this: [fchub_stream:test-video-789]',
				'meta'             => array(),
			),
		);

		$result = $this->portal_integration->process_shortcodes_in_response( $data, array() );

		// Check that shortcode is replaced.
		$this->assertStringContainsString( 'fchub-stream-player-wrapper', $result['feed']['message_rendered'] );
		$this->assertStringNotContainsString( '[fchub_stream:', $result['feed']['message_rendered'] );
	}

	/**
	 * Test process_shortcodes_in_response with non-feed data.
	 *
	 * Verifies that non-feed API responses (e.g., chat) are returned unchanged.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::process_shortcodes_in_response
	 *
	 * @return void
	 */
	public function test_process_shortcodes_in_response_non_feed() {
		$data = array(
			'messages' => array(
				array(
					'id'      => 1,
					'content' => 'Chat message',
				),
			),
		);

		$result = $this->portal_integration->process_shortcodes_in_response( $data, array() );

		// Check that data is unchanged.
		$this->assertEquals( $data, $result );
	}

	/**
	 * Test process_shortcodes_in_feeds with valid feeds.
	 *
	 * Verifies that API responses for multiple feeds are processed correctly.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::process_shortcodes_in_feeds
	 *
	 * @return void
	 */
	public function test_process_shortcodes_in_feeds() {
		$data = array(
			'feeds' => array(
				'data' => array(
					array(
						'id'               => 1,
						'message_rendered' => 'Post with video: [fchub_stream:video-001]',
						'meta'             => array(),
					),
					array(
						'id'               => 2,
						'message_rendered' => 'Regular post',
						'meta'             => array(),
					),
				),
			),
		);

		$result = $this->portal_integration->process_shortcodes_in_feeds( $data, array() );

		// Check that first feed's shortcode is replaced.
		$this->assertStringContainsString( 'fchub-stream-player-wrapper', $result['feeds']['data'][0]['message_rendered'] );

		// Check that second feed is unchanged.
		$this->assertEquals( 'Regular post', $result['feeds']['data'][1]['message_rendered'] );
	}

	/**
	 * Test process_shortcodes_in_feeds with non-feeds data.
	 *
	 * Verifies that non-feeds API responses are returned unchanged.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::process_shortcodes_in_feeds
	 *
	 * @return void
	 */
	public function test_process_shortcodes_in_feeds_non_feeds() {
		$data = array(
			'activities' => array(
				array(
					'id'   => 1,
					'type' => 'comment',
				),
			),
		);

		$result = $this->portal_integration->process_shortcodes_in_feeds( $data, array() );

		// Check that data is unchanged.
		$this->assertEquals( $data, $result );
	}

	/**
	 * Test add_portal_css output.
	 *
	 * Verifies that portal CSS is output correctly with proper selectors
	 * for video player styling.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::add_portal_css
	 *
	 * @return void
	 */
	public function test_add_portal_css() {
		ob_start();
		$this->portal_integration->add_portal_css();
		$output = ob_get_clean();

		// Check that CSS is output.
		$this->assertStringContainsString( '<style>', $output );
		$this->assertStringContainsString( '.fchub-stream-player-wrapper', $output );
		$this->assertStringContainsString( '.fcom_top_media', $output );
		$this->assertStringContainsString( '</style>', $output );
	}

	/**
	 * Test portal vars with disabled upload from portal.
	 *
	 * Verifies that when portal upload is disabled, the enabled flag is false.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::add_portal_vars
	 *
	 * @return void
	 */
	public function test_portal_vars_with_disabled_upload() {
		global $wp_options;

		// Mock upload settings with portal upload disabled.
		$wp_options['fchub_stream_upload_settings'] = array(
			'enable_upload_from_portal' => false,
		);

		$vars   = array();
		$result = $this->portal_integration->add_portal_vars( $vars );

		// Check that upload is disabled.
		$this->assertFalse( $result['fchubStreamSettings']['enabled'] );
	}

	/**
	 * Test shortcode pattern matching.
	 *
	 * Verifies that various shortcode formats are correctly matched and extracted.
	 *
	 * @since 0.0.1
	 *
	 * @covers \FCHubStream\App\Hooks\PortalIntegration::process_shortcodes_before_save
	 *
	 * @return void
	 */
	public function test_shortcode_pattern_matching() {
		$test_cases = array(
			'[fchub_stream:abc123]'         => 'abc123',
			'[fchub_stream:test-video-456]' => 'test-video-456',
			'[fchub_stream:video_789]'      => 'video_789',
			'[fchub_stream:xyz provider="cloudflare_stream"]' => 'xyz',
			'[fchub_stream:bunny-vid provider="bunny_stream"]' => 'bunny-vid',
		);

		foreach ( $test_cases as $shortcode => $expected_id ) {
			$data = array(
				'message'          => "Video: $shortcode",
				'message_rendered' => "Video: $shortcode",
				'meta'             => array(),
			);

			$result = $this->portal_integration->process_shortcodes_before_save( $data, array() );

			$this->assertArrayHasKey( 'media_preview', $result['meta'], "Failed for shortcode: $shortcode" );
			$this->assertEquals( $expected_id, $result['meta']['media_preview']['video_id'], "Wrong ID for shortcode: $shortcode" );
		}
	}
}
