<?php
/**
 * Application configuration.
 *
 * Defines core application settings for the FCHub Stream plugin,
 * including plugin metadata, REST API configuration, and environment settings.
 *
 * @package FCHub_Stream
 * @subpackage Config
 * @since 0.0.1
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	/**
	 * Plugin display name.
	 *
	 * @since 0.0.1
	 */
	'name'                      => 'FCHub Stream',

	/**
	 * Plugin slug identifier.
	 *
	 * @since 0.0.1
	 */
	'slug'                      => 'fchub-stream',

	/**
	 * Language files directory path.
	 *
	 * @since 0.0.1
	 */
	'domain_path'               => '/language',

	/**
	 * Internationalization text domain.
	 *
	 * @since 0.0.1
	 */
	'text_domain'               => 'fchub-stream',

	/**
	 * WordPress hook prefix for actions and filters.
	 *
	 * @since 0.0.1
	 */
	'hook_prefix'               => 'fchub-stream',

	/**
	 * REST API namespace for FluentCommunity integration.
	 *
	 * @since 0.0.1
	 */
	'rest_namespace'            => 'fluent-community',

	/**
	 * REST API version.
	 *
	 * @since 0.0.1
	 */
	'rest_version'              => 'v2',

	/**
	 * Application environment (dev/production).
	 *
	 * @since 0.0.1
	 */
	'env'                       => 'production',
);
