<?php
/**
 * Admin interface management.
 *
 * Handles WordPress admin integration including menu registration,
 * settings pages, and Vue-based admin UI rendering for stream configuration.
 *
 * @package FCHub_Stream
 * @subpackage Admin
 * @since 1.0.0
 */

namespace FCHubStream\App\Admin;

/**
 * Admin class.
 *
 * Manages WordPress admin interface integration for the FCHub Stream plugin.
 * Provides menu registration, page rendering, and Vue app integration.
 *
 * @since 1.0.0
 */
class Admin {
	/**
	 * Plugin name identifier.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_name Plugin identifier.
	 * @param string $version Plugin version.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register admin menu pages.
	 *
	 * Adds top-level menu and submenu pages for stream configuration.
	 * Creates a dedicated "Stream" menu with Welcome and Settings subpages.
	 *
	 * @since 1.0.0
	 * @hook admin_menu
	 *
	 * @return void
	 */
	public function add_plugin_admin_menu() {
		// Add top-level menu positioned right after Settings (Settings is at position 80).
		add_menu_page(
			__( 'FCHub Stream', 'fchub-stream' ),
			__( 'FCHub Stream', 'fchub-stream' ),
			'manage_options',
			'fchub-stream',
			array( $this, 'display_admin_page' ),
			'dashicons-video-alt3',
			80.1
		);

		// Add submenu pages.
		add_submenu_page(
			'fchub-stream',
			__( 'Welcome', 'fchub-stream' ),
			__( 'Welcome', 'fchub-stream' ),
			'manage_options',
			'fchub-stream',
			array( $this, 'display_admin_page' )
		);

		add_submenu_page(
			'fchub-stream',
			__( 'Settings', 'fchub-stream' ),
			__( 'Settings', 'fchub-stream' ),
			'manage_options',
			'fchub-stream-settings',
			array( $this, 'display_settings_page' )
		);
	}

	/**
	 * Display admin page (Welcome).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function display_admin_page() {
		$this->load_vue_app( 'welcome' );
	}

	/**
	 * Display settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function display_settings_page() {
		$this->load_vue_app( 'settings' );
	}

	/**
	 * Load Vue app with specific component.
	 *
	 * @since 1.0.0
	 *
	 * @param string $component Component name to load ('welcome' or 'settings').
	 *
	 * @return void
	 */
	private function load_vue_app( $component ) {
		add_filter(
			'fchub_stream_admin_component',
			function () use ( $component ) {
				return $component;
			},
			999
		);

		// Load Vue app template (will exit after rendering).
		require_once FCHUB_STREAM_DIR . 'admin/admin-vue.php';
	}
}
