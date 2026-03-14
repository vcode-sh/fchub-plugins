<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Core;

use FChubMemberships\Core\Container;
use FChubMemberships\Core\Contracts\ModuleInterface;
use FChubMemberships\Core\FeatureFlags;
use FChubMemberships\Core\ModuleRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ModuleRegistryTest extends PluginTestCase
{
    public function test_registry_boots_only_enabled_modules(): void
    {
        $container = new Container();
        $container->instance(FeatureFlags::class, new FeatureFlags([
            'enabled' => true,
            'disabled' => false,
        ]));

        $registry = new ModuleRegistry($container);
        $calls = [];

        $registry
            ->add(new class($calls) implements ModuleInterface {
                /** @var array<int, string> */
                private array $calls;

                public function __construct(array &$calls)
                {
                    $this->calls = &$calls;
                }

                public function key(): string
                {
                    return 'enabled';
                }

                public function register(Container $container): void
                {
                    $this->calls[] = 'enabled';
                }
            })
            ->add(new class($calls) implements ModuleInterface {
                /** @var array<int, string> */
                private array $calls;

                public function __construct(array &$calls)
                {
                    $this->calls = &$calls;
                }

                public function key(): string
                {
                    return 'disabled';
                }

                public function register(Container $container): void
                {
                    $this->calls[] = 'disabled';
                }
            });

        $registry->boot();

        self::assertSame(['enabled'], $calls);
    }

    public function test_registry_rejects_duplicate_keys(): void
    {
        $container = new Container();
        $container->instance(FeatureFlags::class, new FeatureFlags());

        $registry = new ModuleRegistry($container);
        $module = new class() implements ModuleInterface {
            public function key(): string
            {
                return 'duplicate';
            }

            public function register(Container $container): void
            {
            }
        };

        $registry->add($module);

        $this->expectException(\InvalidArgumentException::class);
        $registry->add($module);
    }
}
