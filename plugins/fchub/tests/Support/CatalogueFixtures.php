<?php

declare(strict_types=1);

namespace FChubHub\Tests\Support;

use FChubHub\Catalogue\CatalogueValidator;

/**
 * Hand-written catalogue payloads for the FCHub suite. Deliberately not read
 * from resources/catalog.json: these fixtures must stay stable while the real
 * bundled catalogue keeps moving with every product release. One test does
 * validate the real file, and it is the only one that should.
 */
final class CatalogueFixtures
{
    /**
     * A valid catalogue in the shape the endpoint and the bundled file ship —
     * including the docs_path key the validator is expected to drop.
     *
     * @return array<string, mixed>
     */
    public static function raw(): array
    {
        return [
            'schema_version' => 1,
            'hub' => [
                'version' => '1.0.0',
                'plugin_file' => 'fchub/fchub.php',
                'release_url' => 'https://github.com/vcode-sh/fchub-plugins/releases/tag/fchub/v1.0.0',
                'package_url' => 'https://github.com/vcode-sh/fchub-plugins/releases/download/fchub/v1.0.0/fchub-1.0.0.zip',
                'checksum_url' => 'https://github.com/vcode-sh/fchub-plugins/releases/download/fchub/v1.0.0/fchub-1.0.0.zip.sha256',
            ],
            'products' => [
                'fchub-p24' => self::product(
                    'fchub-p24',
                    'Przelewy24',
                    '1.0.3',
                    '6.4',
                    '8.1',
                    'admin.php?page=fluent-cart#/settings/payment-methods'
                ),
                'fchub-memberships' => self::product(
                    'fchub-memberships',
                    'Memberships',
                    '1.4.0',
                    '6.7',
                    '8.3',
                    'admin.php?page=fchub-memberships'
                ),
            ],
        ];
    }

    /**
     * The same catalogue after the validator has had its way with it.
     *
     * @return array<string, mixed>
     */
    public static function normalised(): array
    {
        return (new CatalogueValidator())->validate(self::raw());
    }

    /**
     * A raw catalogue advertising a different FCHub release, for the updater.
     *
     * @return array<string, mixed>
     */
    public static function withHubVersion(string $version): array
    {
        $catalogue = self::raw();

        $catalogue['hub']['version'] = $version;
        $catalogue['hub']['release_url'] = 'https://github.com/vcode-sh/fchub-plugins/releases/tag/fchub/v' . $version;
        $catalogue['hub']['package_url'] = 'https://github.com/vcode-sh/fchub-plugins/releases/download/fchub/v'
            . $version . '/fchub-' . $version . '.zip';
        $catalogue['hub']['checksum_url'] = $catalogue['hub']['package_url'] . '.sha256';

        return $catalogue;
    }

    /**
     * @return array<string, mixed>
     */
    public static function product(
        string $slug,
        string $name,
        string $version,
        string $requiresWp,
        string $requiresPhp,
        string $adminPath
    ): array {
        $tag = $slug . '/v' . $version;
        $package = 'https://github.com/vcode-sh/fchub-plugins/releases/download/' . $tag . '/' . $slug . '-' . $version . '.zip';

        return [
            'name' => $name,
            'description' => $name . ' does exactly what the name suggests, with fewer support tickets.',
            'status' => 'stable',
            'plugin_file' => $slug . '/' . $slug . '.php',
            'requires_wp' => $requiresWp,
            'requires_php' => $requiresPhp,
            'dependencies' => ['fluentcart'],
            'docs_path' => '/docs/' . $slug,
            'admin_path' => $adminPath,
            'version' => $version,
            'docs_url' => 'https://fchub.co/docs/' . $slug,
            'release_url' => 'https://github.com/vcode-sh/fchub-plugins/releases/tag/' . $tag,
            'package_url' => $package,
            'checksum_url' => $package . '.sha256',
        ];
    }
}
