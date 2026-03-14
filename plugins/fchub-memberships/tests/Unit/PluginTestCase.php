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
        $GLOBALS['_fchub_test_current_user_can'] = true;
        $GLOBALS['wpdb'] = new \wpdb();
    }
}
