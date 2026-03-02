<?php
/**
 * Plugin deactivation handler.
 *
 * Handles cleanup on plugin deactivation for both single-site and
 * network-wide installations.
 *
 * @package FCHub_Stream
 * @subpackage Hooks\Handlers
 * @since 1.0.0
 */

namespace FCHubStream\App\Hooks\Handlers;

/**
 * Class DeactivationHandler
 *
 * Manages plugin deactivation cleanup including transient removal
 * and temporary data cleanup.
 *
 * @since 1.0.0
 */
class DeactivationHandler {
	/**
	 * Handle plugin deactivation.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether this is a network-wide deactivation.
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
				self::cleanup();
			}

			switch_to_blog( $old_blog );
		} else {
			self::cleanup();
		}
	}

	/**
	 * Perform cleanup tasks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function cleanup() {
		self::clear_transients();
	}

	/**
	 * Clear plugin transients.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function clear_transients() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_fchub_stream_%'
			OR option_name LIKE '_transient_timeout_fchub_stream_%'"
		);

		if ( is_multisite() ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"DELETE FROM {$wpdb->sitemeta}
				WHERE meta_key LIKE '_site_transient_fchub_stream_%'
				OR meta_key LIKE '_site_transient_timeout_fchub_stream_%'"
			);
		}
	}
}
