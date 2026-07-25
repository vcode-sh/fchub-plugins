<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use FChubHub\Catalogue\DescriptorRegistry;
use FChubHub\Tests\Support\CatalogueFixtures;
use PHPUnit\Framework\TestCase;

final class DescriptorRegistryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_hub_test_filters'] = [];
        $this->catalogue = CatalogueFixtures::normalised();
    }

    protected function tearDown(): void
    {
        $GLOBALS['_fchub_hub_test_filters'] = [];

        parent::tearDown();
    }

    public function testWithoutAnyDescriptorsTheRegistryIsEmpty(): void
    {
        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testAcceptsAValidDescriptorForAKnownProduct(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'admin_path' => 'admin.php?page=fchub-memberships#/plans',
                'health' => [
                    'status' => 'healthy',
                    'message' => 'Memberships is ready.',
                ],
            ],
        ]);

        self::assertSame([
            'fchub-memberships' => [
                'admin_path' => 'admin.php?page=fchub-memberships#/plans',
                'health' => [
                    'status' => 'healthy',
                    'message' => 'Memberships is ready.',
                ],
            ],
        ], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testADescriptorMayCarryHealthWithoutAnAdminPath(): void
    {
        $this->describe([
            'fchub-p24' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-p24/fchub-p24.php',
                'health' => ['status' => 'attention'],
            ],
        ]);

        self::assertSame([
            'fchub-p24' => [
                'admin_path' => null,
                'health' => ['status' => 'attention', 'message' => null],
            ],
        ], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testIgnoresASlugThatIsNotInTheCatalogue(): void
    {
        $this->describe([
            'fchub-stream' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-stream/fchub-stream.php',
                'health' => ['status' => 'healthy'],
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testIgnoresADescriptorWhosePluginFileDoesNotMatchTheCatalogue(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'impostor/impostor.php',
                'health' => ['status' => 'healthy', 'message' => 'Trust me.'],
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testIgnoresADescriptorWithTheWrongSchemaVersion(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 2,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'health' => ['status' => 'healthy'],
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testRejectsADescriptorTryingToOverrideTrustedReleaseFields(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'version' => '99.0.0',
                'package_url' => 'https://evil.example/payload.zip',
                'checksum_url' => 'https://evil.example/payload.zip.sha256',
                'docs_url' => 'https://evil.example/docs',
                'requires_php' => '5.6',
                'health' => ['status' => 'healthy'],
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testRejectsAHealthStatusOutsideTheAllowList(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'health' => ['status' => 'on fire'],
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testRejectsUnexpectedKeysInsideHealth(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'health' => ['status' => 'healthy', 'action_url' => 'https://evil.example/fix'],
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testSanitisesTheHealthMessage(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'health' => [
                    'status' => 'attention',
                    'message' => "  Two plans <script>alert('x')</script> need\na look.  ",
                ],
            ],
        ]);

        $message = (new DescriptorRegistry())->collect($this->catalogue)['fchub-memberships']['health']['message'];

        // Asserts the properties sanitisation must guarantee, not the exact
        // output of the stub — otherwise the stand-in quietly becomes the thing
        // under test and any drift from real sanitize_text_field() goes unseen.
        self::assertIsString($message);
        self::assertStringNotContainsString('<', $message);
        self::assertStringNotContainsString('script', $message);
        self::assertStringNotContainsString("\n", $message);
        self::assertSame(trim($message), $message);
        self::assertDoesNotMatchRegularExpression('/\s{2,}/', $message);
        self::assertStringContainsString('Two plans', $message);
    }

    public function testRejectsAnOverLongHealthMessage(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'health' => ['status' => 'attention', 'message' => str_repeat('a', 201)],
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testAcceptsAHealthMessageAtExactlyTheCap(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'health' => ['status' => 'attention', 'message' => str_repeat('a', 200)],
            ],
        ]);

        $message = (new DescriptorRegistry())->collect($this->catalogue)['fchub-memberships']['health']['message'];

        self::assertSame(200, mb_strlen((string) $message));
    }

    public function testTheMessageCapIsMeasuredAfterSanitising(): void
    {
        // Sanitising can lengthen a string — a bare `<` becomes `&lt;` — so a
        // message under the cap on the way in can be over it on the way out.
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'health' => ['status' => 'attention', 'message' => str_repeat('a', 199) . '<'],
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testRejectsAnAdminPathPointingAtAnotherOrigin(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'admin_path' => 'https://evil.example/steal',
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testRejectsAnAdminPathTraversingOutOfWpAdmin(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-memberships/fchub-memberships.php',
                'admin_path' => '../../wp-config.php',
            ],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testIgnoresNonArrayDescriptorsAndNumericKeys(): void
    {
        $this->describe([
            'fchub-memberships' => 'healthy, promise',
            0 => ['schema_version' => 1, 'plugin_file' => 'fchub-p24/fchub-p24.php'],
        ]);

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testIgnoresAFilterThatReturnsSomethingOtherThanAnArray(): void
    {
        add_filter('fchub/products', static fn (): string => 'not an array');

        self::assertSame([], (new DescriptorRegistry())->collect($this->catalogue));
    }

    public function testOneBrokenDescriptorDoesNotDiscardAGoodOne(): void
    {
        $this->describe([
            'fchub-memberships' => [
                'schema_version' => 1,
                'plugin_file' => 'impostor/impostor.php',
            ],
            'fchub-p24' => [
                'schema_version' => 1,
                'plugin_file' => 'fchub-p24/fchub-p24.php',
                'health' => ['status' => 'healthy'],
            ],
        ]);

        self::assertSame(['fchub-p24'], array_keys((new DescriptorRegistry())->collect($this->catalogue)));
    }

    /**
     * @param array<int|string, mixed> $descriptors
     */
    private function describe(array $descriptors): void
    {
        add_filter('fchub/products', static fn (array $existing): array => $descriptors + $existing);
    }
}
