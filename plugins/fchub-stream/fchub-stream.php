<?php
/**
 * Plugin Name: FCHub - Stream
 * Plugin URI: https://github.com/vcode-sh/fchub-plugins
 * Description: Video streaming for FluentCommunity. Direct uploads to Cloudflare Stream or Bunny.net. Built because WordPress media library and video don't mix.
 * Version: 1.0.2
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-stream
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to: 6.9
 * Requires PHP: 8.3
 * Update URI: https://fchub.co/fchub-stream
 *
 * @package FCHub_Stream
 * @since 0.0.1
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @since 0.0.1
 */
define( 'FCHUB_STREAM_VERSION', '1.0.2' );

/**
 * Plugin mode (production/development).
 *
 * @since 0.0.1
 */
define( 'FCHUB_STREAM_MODE', 'production' );

/**
 * Plugin URL.
 *
 * @since 0.0.1
 */
define( 'FCHUB_STREAM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin directory path.
 *
 * @since 0.0.1
 */
define( 'FCHUB_STREAM_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin main file path.
 *
 * @since 0.0.1
 */
define( 'FCHUB_STREAM_FILE', __FILE__ );

require_once __DIR__ . '/lib/GitHubUpdater.php';
FCHub_GitHub_Updater::register( 'fchub-stream', plugin_basename( __FILE__ ), FCHUB_STREAM_VERSION );

// Require Composer autoloader.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';
}

/**
 * Bootstrap the plugin.
 *
 * This function loads the plugin bootstrap file and executes the initialization.
 * The bootstrap file returns a callable that handles plugin registration.
 *
 * @since 0.0.1
 */
call_user_func(
	function ( $bootstrap ) {
		$bootstrap( __FILE__ );
	},
	require __DIR__ . '/boot/app.php'
);
