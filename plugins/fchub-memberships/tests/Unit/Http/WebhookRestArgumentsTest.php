<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http;

use FChubMemberships\Http\WebhookRestArguments;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookRestArgumentsTest extends PluginTestCase
{
    public function test_delivery_list_schema_freezes_pagination_and_status_bounds(): void
    {
        $arguments = WebhookRestArguments::deliveries();

        self::assertSame(['page', 'per_page', 'status'], array_keys($arguments));
        self::assertSame(1, $arguments['page']['minimum']);
        self::assertSame(1, $arguments['page']['default']);
        self::assertSame(1, $arguments['per_page']['minimum']);
        self::assertSame(100, $arguments['per_page']['maximum']);
        self::assertSame(20, $arguments['per_page']['default']);
        self::assertSame(
            ['', 'pending', 'processing', 'retrying', 'succeeded', 'failed'],
            $arguments['status']['enum']
        );
        self::assertTrue(($arguments['page']['validate_callback'])(1));
        self::assertFalse(($arguments['page']['validate_callback'])(0));
        self::assertTrue(($arguments['per_page']['validate_callback'])(100));
        self::assertFalse(($arguments['per_page']['validate_callback'])(101));
    }

    public function test_retry_schema_accepts_only_a_positive_required_delivery_id(): void
    {
        $arguments = WebhookRestArguments::retry();

        self::assertSame(['id'], array_keys($arguments));
        self::assertTrue($arguments['id']['required']);
        self::assertSame('integer', $arguments['id']['type']);
        self::assertSame(1, $arguments['id']['minimum']);
        self::assertTrue(($arguments['id']['validate_callback'])(7));
        self::assertFalse(($arguments['id']['validate_callback'])(0));
        self::assertFalse(($arguments['id']['validate_callback'])('7.2'));
    }
}
