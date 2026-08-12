<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\CLI;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class LegacyEntryPointInstalledTest extends InstalledContractTestCase
{
    public function testInstalledGenericDryRunRefusesWithTheExactV2Replacement(): void
    {
        $result = $this->runWpCliCommand(['cartshift', 'migrate', '--entities=product', '--dry-run']);
        $output = $result['stdout'] . $result['stderr'];

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('legacy_generic_migration_closed', $output);
        self::assertStringContainsString('wp cartshift transfer prepare', $output);
    }

    public function testInstalledSubscriptionStageRefusesBeforeReceiptOrConfirmationParsing(): void
    {
        $result = $this->runWpCliCommand([
            'cartshift',
            'subscriptions',
            'stage',
            '--receipt=/tmp/does-not-exist.ndjson',
            '--confirm',
        ]);
        $output = $result['stdout'] . $result['stderr'];

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('legacy_subscription_v1_write_closed', $output);
        self::assertStringContainsString('wp cartshift transfer stage', $output);
    }
}
