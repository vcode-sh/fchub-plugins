<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Audit\TransferAuditReport;
use CartShift\Domain\Transfer\Package\LoadedWooExportRuntime;
use CartShift\Domain\Transfer\Package\SourceInstanceRegistry;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeReport;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedWooExportRuntimeTest extends PluginTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-runtime-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) unlink($file);
        rmdir($this->root);
        parent::tearDown();
    }

    public function testDescriptorRequiresPriorOwnerBindingAndHashesNormalisedUrlAndSettings(): void
    {
        $registry = new SourceInstanceRegistry($this->root . '/sources.json');
        $settings = ['woocommerce_currency' => 'PLN'];
        $binding = str_repeat('9', 64);
        $runtime = $this->runtime($settings, $binding);

        try {
            $runtime->descriptor($this->root, $this->audit(), $registry);
            self::fail('An unbound source namespace was exported.');
        } catch (\RuntimeException $exception) {
            self::assertSame('source_instance_binding_missing', $exception->getMessage());
        }

        $registry->bindOwnerApproved('shop-alpha', $binding, SourceInstanceRegistry::approval('shop-alpha', $binding));
        $first = $runtime->descriptor($this->root, $this->audit(), $registry);
        $settings['woocommerce_currency'] = 'EUR';
        $second = $runtime->descriptor($this->root, $this->audit(), $registry);

        self::assertSame($binding, $first['source_instance_fingerprint']);
        self::assertSame(hash('sha256', 'https://shop.test/base/'), $first['source_url_hash']);
        self::assertNotSame($first['source_settings_fingerprint'], $second['source_settings_fingerprint']);
        self::assertSame('2026-08-10T12:00:00Z', $first['created_at_utc']);

        $moved = $this->runtime($settings, str_repeat('8', 64));
        $this->expectExceptionMessage('Source key is not bound to this source instance.');
        $moved->descriptor($this->root, $this->audit(), $registry);
    }

    /** @param array<string, scalar|null> $settings */
    private function runtime(array &$settings, string $sourceInstance): LoadedWooExportRuntime
    {
        $inspector = new class implements TransferRuntimeInspector {
            public function inspect(string $role): TransferRuntimeReport
            {
                return new TransferRuntimeReport(
                    $role,
                    str_repeat('3', 64),
                    ['cartshift' => '1.5.0', 'woocommerce' => '11.0.0', 'wcs' => '8.7.1'],
                    [],
                    [],
                    [],
                );
            }
        };

        return new LoadedWooExportRuntime(
            $inspector,
            static function (string $key) use (&$settings): mixed { return $settings[$key] ?? null; },
            static fn (): string => 'HTTPS://SHOP.TEST:443/base',
            static fn (): string => '2026-08-10T12:00:00Z',
            static fn (): string => $sourceInstance,
        );
    }

    private function audit(): TransferAuditReport
    {
        return TransferAuditReport::create(
            'shop-alpha',
            str_repeat('1', 64),
            str_repeat('3', 64),
            true,
            [],
            ['products' => ['simple' => 1]],
            [],
            str_repeat('2', 64),
        );
    }
}
