<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use FChubHub\Catalogue\CatalogueValidator;
use FChubHub\Tests\Support\CatalogueFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class CatalogueValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_hub_test_filters'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_fchub_hub_test_filters'] = [];

        parent::tearDown();
    }

    public function testAcceptsTheBundledCatalogueThatShipsWithThePlugin(): void
    {
        $decoded = json_decode((string) file_get_contents(FCHUB_HUB_PATH . 'resources/catalog.json'), true);
        self::assertIsArray($decoded);

        $catalogue = (new CatalogueValidator())->validate($decoded);

        self::assertSame(1, $catalogue['schema_version']);
        self::assertCount(6, $catalogue['products']);
        self::assertSame('fchub/fchub.php', $catalogue['hub']['plugin_file']);
    }

    public function testNormalisesEachProductToTheExactDesignKeys(): void
    {
        $catalogue = (new CatalogueValidator())->validate(CatalogueFixtures::raw());

        self::assertSame(
            [
                'name',
                'description',
                'version',
                'plugin_file',
                'requires_wp',
                'requires_php',
                'dependencies',
                'docs_url',
                'release_url',
                'package_url',
                'checksum_url',
                'admin_path',
                'status',
            ],
            array_keys($catalogue['products']['fchub-memberships'])
        );

        self::assertArrayNotHasKey('docs_path', $catalogue['products']['fchub-memberships']);
    }

    public function testKeepsTheHubRecordOutOfTheRenderedProductMap(): void
    {
        $catalogue = (new CatalogueValidator())->validate(CatalogueFixtures::raw());

        self::assertArrayNotHasKey('fchub', $catalogue['products']);
        self::assertArrayNotHasKey('hub', $catalogue['products']);
        self::assertSame(['fchub-p24', 'fchub-memberships'], array_keys($catalogue['products']));
        self::assertSame('1.0.0', $catalogue['hub']['version']);
    }

    public function testValidatingAlreadyNormalisedOutputChangesNothing(): void
    {
        // The last-known-good option stores normalised output and is validated
        // again on read. If normalisation were not idempotent, every cached
        // catalogue would be rejected on the next page load.
        $validator = new CatalogueValidator();
        $once = $validator->validate(CatalogueFixtures::raw());

        self::assertSame($once, $validator->validate($once));
    }

    #[DataProvider('invalidCatalogues')]
    public function testRejectsInvalidCatalogue(array $catalogue, string $code): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($code);

        (new CatalogueValidator())->validate($catalogue);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidCatalogues(): array
    {
        return [
            'schema version other than one' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['schema_version'] = 2;

                    return $catalogue;
                }),
                'catalogue_schema_invalid',
            ],
            'schema version as a string' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['schema_version'] = '1';

                    return $catalogue;
                }),
                'catalogue_schema_invalid',
            ],
            'unexpected top level key' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['scripts'] = ['https://evil.example/payload.js'];

                    return $catalogue;
                }),
                'catalogue_schema_invalid',
            ],
            'missing hub record' => [
                self::mutate(static function (array $catalogue): array {
                    unset($catalogue['hub']);

                    return $catalogue;
                }),
                'catalogue_schema_invalid',
            ],
            'empty product map' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products'] = [];

                    return $catalogue;
                }),
                'catalogue_products_invalid',
            ],
            'unknown slug' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-stream'] = CatalogueFixtures::product(
                        'fchub-stream',
                        'Stream',
                        '1.0.0',
                        '6.4',
                        '8.1',
                        'admin.php?page=fchub-stream'
                    );

                    return $catalogue;
                }),
                'catalogue_slug_unknown',
            ],
            'plugin file that does not match the slug' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['plugin_file'] = 'fchub-p24/loader.php';

                    return $catalogue;
                }),
                'catalogue_plugin_file_invalid',
            ],
            'plugin file escaping its own directory' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['plugin_file'] = '../../wp-config.php';

                    return $catalogue;
                }),
                'catalogue_plugin_file_invalid',
            ],
            'unexpected product key' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['install_hook'] = 'https://evil.example/run.php';

                    return $catalogue;
                }),
                'catalogue_product_invalid',
            ],
            'missing product key' => [
                self::mutate(static function (array $catalogue): array {
                    unset($catalogue['products']['fchub-p24']['checksum_url']);

                    return $catalogue;
                }),
                'catalogue_product_invalid',
            ],
            'non https package url' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['package_url'] = str_replace(
                        'https://',
                        'http://',
                        $catalogue['products']['fchub-p24']['package_url']
                    );

                    return $catalogue;
                }),
                'catalogue_url_insecure',
            ],
            'non https docs url' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['docs_url'] = 'http://fchub.co/docs/fchub-p24';

                    return $catalogue;
                }),
                'catalogue_url_insecure',
            ],
            'docs host other than fchub.co' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['docs_url'] = 'https://fchub.co.evil.example/docs/fchub-p24';

                    return $catalogue;
                }),
                'catalogue_docs_host_invalid',
            ],
            'package host outside github' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['package_url'] = 'https://evil.example/fchub-p24-1.0.3.zip';

                    return $catalogue;
                }),
                'catalogue_package_host_invalid',
            ],
            'checksum host outside github' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['checksum_url'] = 'https://github.com.evil.example/hash.sha256';

                    return $catalogue;
                }),
                'catalogue_package_host_invalid',
            ],
            'release host outside github' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['release_url'] = 'https://evil.example/releases/tag/v1';

                    return $catalogue;
                }),
                'catalogue_package_host_invalid',
            ],
            'url that cannot be parsed' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['docs_url'] = 'not-a-url';

                    return $catalogue;
                }),
                'catalogue_url_invalid',
            ],
            'nonsense product version' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['version'] = 'latest';

                    return $catalogue;
                }),
                'catalogue_version_invalid',
            ],
            'zero product version' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['version'] = '0';

                    return $catalogue;
                }),
                'catalogue_version_invalid',
            ],
            'nonsense php requirement' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['requires_php'] = 'anything modern';

                    return $catalogue;
                }),
                'catalogue_version_invalid',
            ],
            'status outside the stable allow list' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['status'] = 'beta';

                    return $catalogue;
                }),
                'catalogue_status_invalid',
            ],
            'dependencies that are not a plain list of slugs' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['dependencies'] = ['fluent cart'];

                    return $catalogue;
                }),
                'catalogue_dependencies_invalid',
            ],
            'admin path pointing at another origin' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['admin_path'] = 'https://evil.example/steal';

                    return $catalogue;
                }),
                'catalogue_admin_path_invalid',
            ],
            'admin path traversing out of wp-admin' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['admin_path'] = '../../wp-config.php';

                    return $catalogue;
                }),
                'catalogue_admin_path_invalid',
            ],
            'hub plugin file that is not the hub' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['hub']['plugin_file'] = 'fchub-p24/fchub-p24.php';

                    return $catalogue;
                }),
                'catalogue_hub_invalid',
            ],
            'unexpected hub key' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['hub']['upgrade_command'] = 'rm -rf /';

                    return $catalogue;
                }),
                'catalogue_hub_invalid',
            ],
            'hub package host outside github' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['hub']['package_url'] = 'https://evil.example/fchub-1.0.0.zip';

                    return $catalogue;
                }),
                'catalogue_package_host_invalid',
            ],
            'admin path with a trailing newline' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['admin_path'] = "admin.php?page=fluent-cart\n";

                    return $catalogue;
                }),
                'catalogue_admin_path_invalid',
            ],
            'version with a trailing newline' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['version'] = "1.0.3\n";

                    return $catalogue;
                }),
                'catalogue_version_invalid',
            ],
            'dependency with a trailing newline' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['dependencies'] = ["fluentcart\n"];

                    return $catalogue;
                }),
                'catalogue_dependencies_invalid',
            ],
            'name longer than the cap' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['name'] = str_repeat('a', 121);

                    return $catalogue;
                }),
                'catalogue_text_too_long',
            ],
            'description longer than the cap' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['description'] = str_repeat('a', 401);

                    return $catalogue;
                }),
                'catalogue_text_too_long',
            ],
            'description that is nothing but markup' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['products']['fchub-p24']['description'] = '<script>alert(1)</script>';

                    return $catalogue;
                }),
                'catalogue_product_invalid',
            ],
            'hub package url over plain http' => [
                self::mutate(static function (array $catalogue): array {
                    $catalogue['hub']['package_url'] = str_replace('https://', 'http://', $catalogue['hub']['package_url']);

                    return $catalogue;
                }),
                'catalogue_url_insecure',
            ],
        ];
    }

    public function testStripsMarkupFromTheTwoFieldsThatGetRendered(): void
    {
        $catalogue = CatalogueFixtures::raw();
        $catalogue['products']['fchub-p24']['name'] = 'Przelewy24<img src=x onerror=alert(1)>';
        $catalogue['products']['fchub-p24']['description'] = "Takes\tmoney. <b>Politely.</b>";

        $product = (new CatalogueValidator())->validate($catalogue)['products']['fchub-p24'];

        self::assertStringNotContainsString('<', $product['name']);
        self::assertStringNotContainsString('onerror', $product['name']);
        self::assertStringNotContainsString('<', $product['description']);
        self::assertStringNotContainsString("\t", $product['description']);
    }

    public function testEscapesABareLessThanRatherThanLettingItThrough(): void
    {
        $catalogue = CatalogueFixtures::raw();
        $catalogue['products']['fchub-p24']['description'] = 'Works when stock < 5 units.';

        $description = (new CatalogueValidator())->validate($catalogue)['products']['fchub-p24']['description'];

        self::assertStringNotContainsString('<', $description);
        self::assertStringContainsString('&lt;', $description);
    }

    public function testTextAtExactlyTheCapIsStillAccepted(): void
    {
        $catalogue = CatalogueFixtures::raw();
        $catalogue['products']['fchub-p24']['name'] = str_repeat('a', 120);
        $catalogue['products']['fchub-p24']['description'] = str_repeat('b', 400);

        $product = (new CatalogueValidator())->validate($catalogue)['products']['fchub-p24'];

        self::assertSame(120, mb_strlen($product['name']));
        self::assertSame(400, mb_strlen($product['description']));
    }

    public function testTheCapIsMeasuredAfterSanitisingRatherThanBefore(): void
    {
        // Sanitising can lengthen a string: a bare `<` becomes `&lt;`, so 120
        // characters in can be 123 characters out. What gets stored is what
        // must be bounded.
        $catalogue = CatalogueFixtures::raw();
        $catalogue['products']['fchub-p24']['name'] = str_repeat('a', 119) . '<';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('catalogue_text_too_long');

        (new CatalogueValidator())->validate($catalogue);
    }

    public function testTheAllowedPackageHostsFilterCanAdmitALocalHarnessHost(): void
    {
        $catalogue = CatalogueFixtures::raw();
        $catalogue['products']['fchub-p24']['package_url'] = 'https://catalogue/fchub-p24-1.0.3.zip';
        $catalogue['products']['fchub-p24']['checksum_url'] = 'https://catalogue/fchub-p24-1.0.3.zip.sha256';

        add_filter('fchub/catalogue/allowed_package_hosts', static fn (array $hosts): array => [...$hosts, 'catalogue']);

        $validated = (new CatalogueValidator())->validate($catalogue);

        self::assertSame('https://catalogue/fchub-p24-1.0.3.zip', $validated['products']['fchub-p24']['package_url']);
    }

    public function testAFilterCannotEmptyTheAllowedPackageHostList(): void
    {
        $catalogue = CatalogueFixtures::raw();
        $catalogue['products']['fchub-p24']['package_url'] = 'https://evil.example/payload.zip';

        add_filter('fchub/catalogue/allowed_package_hosts', static fn (): array => []);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('catalogue_package_host_invalid');

        (new CatalogueValidator())->validate($catalogue);
    }

    public function testTheAllowHttpFilterOnlyRelaxesTheSchemeForTheUrlItIsGiven(): void
    {
        $catalogue = CatalogueFixtures::raw();
        $catalogue['products']['fchub-p24']['package_url'] = 'http://catalogue/fchub-p24-1.0.3.zip';
        $catalogue['products']['fchub-p24']['checksum_url'] = 'http://catalogue/fchub-p24-1.0.3.zip.sha256';
        $catalogue['products']['fchub-memberships']['package_url'] = 'http://github.com/anything.zip';

        add_filter('fchub/catalogue/allowed_package_hosts', static fn (array $hosts): array => [...$hosts, 'catalogue']);
        add_filter('fchub/catalogue/allow_http', static function (bool $allow, string $url): bool {
            return wp_parse_url($url, PHP_URL_HOST) === 'catalogue';
        }, 10, 2);

        // The harness host is admitted over HTTP; the very next URL, on an
        // allow-listed host but the wrong scheme, still fails.
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('catalogue_url_insecure');

        (new CatalogueValidator())->validate($catalogue);
    }

    public function testHttpsRemainsTheDefaultWithoutAnyFilters(): void
    {
        $catalogue = CatalogueFixtures::raw();
        $catalogue['products']['fchub-p24']['package_url'] = 'http://github.com/fchub-p24-1.0.3.zip';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('catalogue_url_insecure');

        (new CatalogueValidator())->validate($catalogue);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    private static function mutate(callable $mutator): array
    {
        return $mutator(CatalogueFixtures::raw());
    }
}
