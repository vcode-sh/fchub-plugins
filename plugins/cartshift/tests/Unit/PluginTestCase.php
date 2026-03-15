<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit;

use PHPUnit\Framework\TestCase;

abstract class PluginTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_options'] = [];
        $GLOBALS['_cartshift_test_actions'] = [];
        $GLOBALS['_cartshift_test_filters'] = [];
        $GLOBALS['_cartshift_test_queries'] = [];
        $GLOBALS['_cartshift_test_deleted_posts'] = [];
        $GLOBALS['_cartshift_test_deleted_terms'] = [];
        $GLOBALS['_cartshift_test_post_meta'] = [];
        $GLOBALS['_cartshift_test_as_scheduled'] = [];
        $GLOBALS['_cartshift_test_as_unscheduled'] = [];
        $GLOBALS['_cartshift_test_wc_products'] = [];
    }
}
