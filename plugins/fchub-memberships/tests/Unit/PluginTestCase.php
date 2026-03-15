<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit;

use PHPUnit\Framework\TestCase;

abstract class PluginTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_actions'] = [];
        $GLOBALS['_fchub_test_filters'] = [];
        $GLOBALS['_fchub_test_routes'] = [];
        $GLOBALS['_fchub_test_options'] = [];
        $GLOBALS['_fchub_test_queries'] = [];
        $GLOBALS['_fchub_test_scheduled_events'] = [];
        $GLOBALS['_fchub_test_cleared_events'] = [];
        $GLOBALS['_fchub_test_activation_hooks'] = [];
        $GLOBALS['_fchub_test_deactivation_hooks'] = [];
        $GLOBALS['_fchub_test_mails'] = [];
        $GLOBALS['_fchub_test_remote_posts'] = [];
        $GLOBALS['_fchub_test_remote_post_result'] = ['response' => ['code' => 200]];
        $GLOBALS['_fchub_test_is_admin'] = false;
        $GLOBALS['_fchub_test_is_singular'] = true;
        $GLOBALS['_fchub_test_current_user_can'] = true;
        $GLOBALS['_fchub_test_user_can'] = [];
        $GLOBALS['_fchub_test_cache'] = [];
        $GLOBALS['_fchub_test_users'] = [];
        $GLOBALS['_fchub_test_users_by_email'] = [];
        $GLOBALS['_fchub_test_current_user_id'] = 0;
        $GLOBALS['_fchub_test_current_user'] = (object) ['ID' => 0, 'user_email' => ''];
        $GLOBALS['_fchub_test_posts'] = [];
        $GLOBALS['_fchub_test_posts_by_type'] = [];
        $GLOBALS['_fchub_test_terms'] = [];
        $GLOBALS['_fchub_test_terms_by_taxonomy'] = [];
        $GLOBALS['_fchub_test_post_types'] = [];
        $GLOBALS['_fchub_test_post_type_objects'] = [];
        $GLOBALS['_fchub_test_taxonomies'] = [];
        $GLOBALS['_fchub_test_taxonomy_objects'] = [];
        $GLOBALS['_fchub_test_get_object_taxonomies'] = [];
        $GLOBALS['_fchub_test_post_terms'] = [];
        $GLOBALS['_fchub_test_redirects'] = [];
        $GLOBALS['_fchub_test_enqueued_styles'] = [];
        $GLOBALS['_fchub_test_meta_boxes'] = [];
        $GLOBALS['_fchub_test_menu_pages'] = [];
        $GLOBALS['_fchub_test_shortcodes'] = [];
        $GLOBALS['_fchub_test_enqueued_scripts'] = [];
        $GLOBALS['_fchub_test_inline_scripts'] = [];
        $GLOBALS['_fchub_test_removed_actions'] = [];
        $GLOBALS['_fchub_test_transients'] = [];
        $GLOBALS['_fchub_test_deleted_transients'] = [];
        $GLOBALS['_fchub_test_fc_logs'] = [];
        $GLOBALS['_fchub_test_fc_error_logs'] = [];
        $GLOBALS['_fchub_test_dbdelta'] = [];
        $GLOBALS['_fchub_test_wpdb_overrides'] = [];
        $GLOBALS['_fchub_test_wpdb_suppress_errors'] = false;
        $GLOBALS['wpdb'] = new \wpdb();
    }
}
