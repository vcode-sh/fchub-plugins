<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssetRightsContractTest extends TestCase
{
    public function testBundledArtworkHasRedistributionRightsOrUsesOwnedGlyph(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $thirdPartyAsset = $pluginRoot . '/assets/fakturownia.webp';

        if (file_exists($thirdPartyAsset)) {
            $manifestPath = $pluginRoot . '/assets/rights.json';

            self::assertFileExists(
                $manifestPath,
                'Third-party artwork requires a non-secret public rights manifest.'
            );

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            self::assertIsArray($manifest);
            self::assertSame('assets/fakturownia.webp', $manifest['asset_path'] ?? null);

            foreach (['rights_holder', 'license', 'permission_date', 'public_evidence_url'] as $field) {
                self::assertNotEmpty($manifest[$field] ?? null, "Missing rights field: {$field}");
            }

            self::assertMatchesRegularExpression(
                '#^https://#',
                (string) ($manifest['public_evidence_url'] ?? '')
            );

            return;
        }

        $ownedAsset = $pluginRoot . '/assets/fchub-fakturownia.svg';
        self::assertFileExists($ownedAsset);

        $sources = [
            $pluginRoot . '/fchub-fakturownia.php',
            $pluginRoot . '/app/Integration/FakturowniaIntegration.php',
            $pluginRoot . '/app/Integration/FakturowniaSettings.php',
        ];

        foreach ($sources as $sourcePath) {
            $source = (string) file_get_contents($sourcePath);
            self::assertStringContainsString('assets/fchub-fakturownia.svg', $source);
            self::assertStringNotContainsString('assets/fakturownia.webp', $source);
        }
    }
}
