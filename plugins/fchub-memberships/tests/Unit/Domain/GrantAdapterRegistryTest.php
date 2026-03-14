<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantAdapterRegistryTest extends PluginTestCase
{
    public function test_resolve_returns_configured_adapter_instance(): void
    {
        $registry = new GrantAdapterRegistry([
            'fake' => FakeGrantAdapter::class,
        ]);

        $adapter = $registry->resolve('fake');

        self::assertInstanceOf(FakeGrantAdapter::class, $adapter);
    }

    public function test_resolve_returns_null_for_unknown_provider(): void
    {
        $registry = new GrantAdapterRegistry();

        self::assertNull($registry->resolve('missing-provider'));
    }
}

final class FakeGrantAdapter
{
}
