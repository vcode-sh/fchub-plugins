<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\LoadedTargetSettingsInspector;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedTargetSettingsInspectorTest extends PluginTestCase
{
    public function testGatewayRegistrationHasAnIndependentOrderInvariantFingerprint(): void
    {
        $options = static fn (string $key): mixed => $key === 'woocommerce_currency' ? 'PLN' : null;
        $uploads = static fn (): array => [
            'basedir' => '/srv/wordpress/wp-content/uploads',
            'baseurl' => 'https://target.invalid/wp-content/uploads',
            'error' => false,
        ];
        $stripe = new class {};
        $replacement = new class {};

        $beforeInspector = new LoadedTargetSettingsInspector(
            $options,
            $uploads,
            static fn (): array => ['stripe' => $stripe, 'offline_payment' => 'OfflineGateway'],
        );
        $reorderedInspector = new LoadedTargetSettingsInspector(
            $options,
            $uploads,
            static fn (): array => ['offline_payment' => 'OfflineGateway', 'stripe' => $stripe],
        );
        $afterInspector = new LoadedTargetSettingsInspector(
            $options,
            $uploads,
            static fn (): array => ['stripe' => $replacement, 'offline_payment' => 'OfflineGateway'],
        );

        self::assertSame(
            $beforeInspector->fingerprint(),
            $afterInspector->fingerprint(),
            'Gateway replacement leaked into the settings fingerprint.',
        );
        self::assertSame(
            $beforeInspector->gatewayFingerprint(),
            $reorderedInspector->gatewayFingerprint(),
            'Gateway registration order changed the semantic fingerprint.',
        );
        self::assertNotSame(
            $beforeInspector->gatewayFingerprint(),
            $afterInspector->gatewayFingerprint(),
            'Replacing a gateway implementation did not invalidate preparation.',
        );
    }

    public function testGatewayObjectsAreNeverInvokedOrSerialisedIntoTheFingerprint(): void
    {
        $gateway = new class {
            public function __serialize(): array
            {
                throw new \RuntimeException('Gateway state must not be serialised.');
            }

            public function isEnabled(): bool
            {
                throw new \RuntimeException('Gateway methods must not run while fingerprinting registration.');
            }
        };

        $fingerprint = (new LoadedTargetSettingsInspector(
            static fn (string $key): mixed => null,
            static fn (): array => [],
            static fn (): array => ['hostile' => $gateway],
        ))->gatewayFingerprint();

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $fingerprint);
    }
}
