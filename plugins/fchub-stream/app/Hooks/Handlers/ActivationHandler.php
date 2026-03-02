<?php
/**
 * Plugin activation handler.
 *
 * Manages plugin activation process including database schema creation,
 * multisite support, and version tracking.
 *
 * @package FCHub_Stream
 * @subpackage Hooks\Handlers
 * @since 1.0.0
 */

namespace FCHubStream\App\Hooks\Handlers;

/**
 * Class ActivationHandler
 *
 * Handles all plugin activation tasks including database table creation
 * and setup for both single and multisite installations.
 *
 * @since 1.0.0
 */
class ActivationHandler {
	/**
	 * Handle plugin activation.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether this is a network-wide activation.
	 *
	 * @return void
	 */
	public static function handle( $network_wide = false ) {
		if ( $network_wide ) {
			global $wpdb;
			$old_blog = $wpdb->blogid;

			$blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $blog_ids as $blog_id ) {
				switch_to_blog( $blog_id );
				self::create_db_tables();
			}

			switch_to_blog( $old_blog );
		} else {
			self::create_db_tables();
		}
	}

	/**
	 * Create database tables.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function create_db_tables() {
		$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';

		if ( file_exists( $upgrade_file ) ) {
			require_once $upgrade_file;
		}

		// Database tables will be created here when needed.
	}
}
