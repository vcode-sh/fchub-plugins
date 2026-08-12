<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\FluentCart;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class DownloadDeliveryContractTest extends InstalledContractTestCase
{
    public function testStagedLocalDownloadResolvesThroughInstalledServiceToExactBytes(): void
    {
        $result = $this->runRuntimeContract('local-download-delivery');

        self::assertSame('local', $result['driver']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}--manual\.pdf\z/D', $result['file_path']);
        self::assertSame($result['manifest_sha256'], $result['delivered_sha256']);
        self::assertSame($result['manifest_bytes'], $result['delivered_bytes']);
        self::assertTrue($result['download_service_resolved_staged_path']);
        self::assertFalse($result['unstaged_basename_exists']);
        self::assertSame(['download_limit' => '2', 'download_expiry' => ''], $result['settings']);
        self::assertTrue($result['installed_expiry_unit_is_months']);
        self::assertTrue($result['rollback_removed_unchanged_file']);
    }
}
