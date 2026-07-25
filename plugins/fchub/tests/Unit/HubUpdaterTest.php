<?php

declare(strict_types=1);

namespace {
    // The repository ships a global FCHub_GitHub_Updater used by the product
    // plugins. Whichever copy loads first wins, which makes its behaviour a
    // function of alphabetical luck. FCHub must never touch it — so the suite
    // puts a harmless stand-in in the room first and checks the hub updater
    // carries on regardless.
    if (!class_exists('FCHub_GitHub_Updater', false)) {
        class FCHub_GitHub_Updater
        {
            /** @var list<string> */
            public static array $handled = [];

            public function __construct(string $pluginFile = '')
            {
                self::$handled[] = $pluginFile;
            }
        }
    }
}

namespace FChubHub\Tests\Unit {

    use FChubHub\Catalogue\CatalogueRepository;
    use FChubHub\Core\Plugin;
    use FChubHub\Support\HubUpdater;
    use FChubHub\Tests\Support\CatalogueFixtures;
    use PHPUnit\Framework\TestCase;
    use UnexpectedValueException;

    final class HubUpdaterTest extends TestCase
    {
        private const HOOK = 'update_plugins_fchub.co';
        private const HUB_FILE = 'fchub/fchub.php';

        protected function setUp(): void
        {
            parent::setUp();

            CatalogueRepository::resetSharedInstanceForTests();
            $GLOBALS['_fchub_hub_test_filters'] = [];
            $GLOBALS['_fchub_hub_test_action_registrations'] = [];
            \FCHub_GitHub_Updater::$handled = [];
        }

        protected function tearDown(): void
        {
            // Plugin::boot() registers real hooks and builds the shared
            // repository. Leaving either behind would hand the next class an
            // update filter, and a memoised catalogue, it never asked for.
            CatalogueRepository::resetSharedInstanceForTests();
            $GLOBALS['_fchub_hub_test_filters'] = [];
            $GLOBALS['_fchub_hub_test_action_registrations'] = [];
            \FCHub_GitHub_Updater::$handled = [];

            parent::tearDown();
        }

        public function testOffersTheHubUpdateWhenTheCatalogueAdvertisesANewerRelease(): void
        {
            $update = $this->runFilter(false, self::HUB_FILE, '2.1.0');

            self::assertIsArray($update);
            self::assertSame('fchub', $update['slug']);
            self::assertSame('2.1.0', $update['version']);
            self::assertSame(self::HUB_FILE, $update['plugin']);
            self::assertSame(
                'https://github.com/vcode-sh/fchub-plugins/releases/download/fchub/v2.1.0/fchub-2.1.0.zip',
                $update['package']
            );
            self::assertSame(
                'https://github.com/vcode-sh/fchub-plugins/releases/tag/fchub/v2.1.0',
                $update['url']
            );
        }

        public function testTheHarmlessGlobalUpdaterIsPresentAndStaysOutOfIt(): void
        {
            self::assertTrue(class_exists('FCHub_GitHub_Updater', false));

            $update = $this->runFilter(false, self::HUB_FILE, '2.1.0');

            self::assertIsArray($update);
            self::assertSame([], \FCHub_GitHub_Updater::$handled, 'FCHub must never construct the shared updater.');
        }

        public function testTheUpdaterSourceNeverReferencesTheSharedGlobalUpdater(): void
        {
            $source = (string) file_get_contents(FCHUB_HUB_PATH . 'app/Support/HubUpdater.php');

            self::assertStringNotContainsString('FCHub_GitHub_Updater', $source);
            self::assertStringNotContainsString('GitHubUpdater', $source);
        }

        public function testSaysNothingWhenTheCatalogueMatchesTheInstalledVersion(): void
        {
            self::assertFalse($this->runFilter(false, self::HUB_FILE, FCHUB_HUB_VERSION));
        }

        public function testSaysNothingWhenTheCatalogueIsBehindTheInstalledVersion(): void
        {
            self::assertFalse($this->runFilter(false, self::HUB_FILE, '0.9.0'));
        }

        public function testNeverAnswersForAnyPluginOtherThanFchub(): void
        {
            $existing = ['version' => '9.9.9', 'package' => 'https://example.com/p24.zip'];

            self::assertSame(
                $existing,
                $this->runFilter($existing, 'fchub-p24/fchub-p24.php', '2.1.0')
            );
        }

        public function testABrokenCatalogueLeavesTheUpdateScreenAlone(): void
        {
            $repository = new CatalogueRepository(
                fetch: static fn (): ?array => null,
                readOption: static fn (string $name, mixed $default = null): mixed => $default,
                writeOption: static function (): void {
                },
                deleteOption: static function (): void {
                },
                readTransient: static fn (): mixed => false,
                writeTransient: static function (): void {
                },
                clock: static fn (): int => 1785000000,
                bundledPath: FCHUB_HUB_PATH . 'resources/no-such-catalogue.json'
            );

            $updater = new HubUpdater($repository);
            $updater->hook();

            // Prove the repository really would explode if nobody caught it.
            $this->assertRepositoryThrows($repository);

            self::assertFalse($this->callHook(false, self::HUB_FILE));
        }

        public function testBootRegistersTheHubUpdaterOnItsOwnHostnameHook(): void
        {
            Plugin::boot();

            self::assertArrayHasKey(self::HOOK, $GLOBALS['_fchub_hub_test_filters']);
            self::assertNotEmpty($GLOBALS['_fchub_hub_test_filters'][self::HOOK]);
        }

        private function assertRepositoryThrows(CatalogueRepository $repository): void
        {
            try {
                $repository->get();
                self::fail('The repository was expected to reject an unreadable bundled catalogue.');
            } catch (UnexpectedValueException $exception) {
                self::assertStringContainsString('catalogue_bundled_unreadable', $exception->getMessage());
            }
        }

        private function runFilter(mixed $update, string $pluginFile, string $hubVersion): mixed
        {
            $catalogue = CatalogueFixtures::withHubVersion($hubVersion);

            $repository = new CatalogueRepository(
                fetch: static fn (): array => [
                    'code' => 200,
                    'body' => (string) json_encode($catalogue),
                    'etag' => '',
                ],
                readOption: static fn (string $name, mixed $default = null): mixed => $default,
                writeOption: static function (): void {
                },
                deleteOption: static function (): void {
                },
                readTransient: static fn (): mixed => false,
                writeTransient: static function (): void {
                },
                clock: static fn (): int => 1785000000
            );

            (new HubUpdater($repository))->hook();

            return $this->callHook($update, $pluginFile);
        }

        private function callHook(mixed $update, string $pluginFile): mixed
        {
            $callbacks = $GLOBALS['_fchub_hub_test_filters'][self::HOOK] ?? [];
            self::assertNotEmpty($callbacks, 'HubUpdater must hook ' . self::HOOK . '.');

            return $callbacks[0]($update, ['Version' => FCHUB_HUB_VERSION], $pluginFile);
        }
    }
}
