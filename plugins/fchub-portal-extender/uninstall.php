<?php
/**
 * Fired when the plugin is uninstalled.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('fchub_portal_endpoints');
