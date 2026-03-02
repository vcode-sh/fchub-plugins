<?php
/**
 * FluentCommunity Portal Integration
 *
 * Handles integration with FluentCommunity Portal for video upload functionality.
 * Provides hooks for injecting portal scripts, processing shortcodes, and managing
 * video content display.
 *
 * @package FCHubStream
 * @subpackage Hooks
 * @since 1.0.0
 */

namespace FCHubStream\App\Hooks;

use FCHubStream\App\Hooks\PortalIntegration\AssetManager;
use FCHubStream\App\Hooks\PortalIntegration\ConfigProvider;
use FCHubStream\App\Hooks\PortalIntegration\ShortcodeProcessor;
use FCHubStream\App\Hooks\PortalIntegration\VideoPlayerRenderer;
use FCHubStream\App\Hooks\PortalIntegration\VideoValidator;

/**
 * Class PortalIntegration
 *
 * Manages integration hooks for FluentCommunity Portal.
 * Coordinates specialized classes for asset management, configuration,
 * shortcode processing, video rendering, and validation.
 *
 * @since 1.0.0
 */
class PortalIntegration {

	/**
	 * Asset manager instance.
	 *
	 * @since 1.0.0
	 * @var AssetManager
	 */
	private $asset_manager;

	/**
	 * Configuration provider instance.
	 *
	 * @since 1.0.0
	 * @var ConfigProvider
	 */
	private $config_provider;

	/**
	 * Shortcode processor instance.
	 *
	 * @since 1.0.0
	 * @var ShortcodeProcessor
	 */
	private $shortcode_processor;

	/**
	 * Video player renderer instance.
	 *
	 * @since 1.0.0
	 * @var VideoPlayerRenderer
	 */
	private $player_renderer;

	/**
	 * Video validator instance.
	 *
	 * @since 1.0.0
	 * @var VideoValidator
	 */
	private $video_validator;

	/**
	 * Constructor.
	 *
	 * Initializes all specialized classes in dependency order.
	 * VideoValidator has no dependencies, so it's created first.
	 * VideoPlayerRenderer and AssetManager have no dependencies on other new classes.
	 * ConfigProvider only depends on StreamConfigService.
	 * ShortcodeProcessor depends on VideoPlayerRenderer and VideoValidator.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Initialize in dependency order.
		$this->video_validator = new VideoValidator();
		$this->player_renderer = new VideoPlayerRenderer();
		$this->asset_manager   = new AssetManager();
		$this->config_provider = new ConfigProvider();

		// ShortcodeProcessor depends on player_renderer and video_validator.
		$this->shortcode_processor = new ShortcodeProcessor(
			$this->player_renderer,
			$this->video_validator
		);
	}

	/**
	 * Register all Portal integration hooks.
	 *
	 * Delegates hook registration to specialized classes.
	 * Each class registers its own WordPress filters and actions.
	 *
	 * NOTE: ConfigProvider is also registered early in boot/app.php (plugins_loaded)
	 * to ensure portal_vars hook is available before portal_loaded fires.
	 * ConfigProvider::register() checks for duplicates, so it's safe to call here too.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		$this->asset_manager->register();
		$this->config_provider->register(); // Safe to call - checks for duplicates internally.
		$this->shortcode_processor->register();
		$this->player_renderer->register();
	}
}
