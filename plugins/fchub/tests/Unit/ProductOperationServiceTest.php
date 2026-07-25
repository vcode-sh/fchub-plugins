<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use Closure;
use FChubHub\Catalogue\CatalogueRepository;
use FChubHub\Catalogue\ProductStateResolver;
use FChubHub\Operations\OperationError;
use FChubHub\Operations\ProductOperationService;
use FChubHub\Operations\VerifiedPackageDownloader;
use FChubHub\Tests\Support\CatalogueFixtures;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The operation service with every WordPress moving part replaced by a fake:
 * no upgrader, no filesystem outside the temporary directory, and nothing that
 * could contact GitHub. What is being tested is the order of the guards, not
 * WordPress's ability to unzip a file.
 */
final class ProductOperationServiceTest extends TestCase
{
    private const MEMBERSHIPS = 'fchub-memberships/fchub-memberships.php';
    private const P24 = 'fchub-p24/fchub-p24.php';
    private const FLUENTCART = 'fluent-cart/fluent-cart.php';

    private const PACKAGE_BODY = 'a perfectly ordinary archive';

    /** @var array<string, array<string, mixed>> */
    private array $installed = [];

    /** @var list<string> */
    private array $active = [];

    /** @var list<array{zip: string, overwrite: bool, plugin_file: string}> */
    private array $installCalls = [];

    /** @var list<string> */
    private array $activateCalls = [];

    /** @var list<string> */
    private array $deactivateCalls = [];

    /** @var list<string> */
    private array $downloadedPackages = [];

    /** @var list<string> Everything the injected diagnostics sink received. */
    private array $logged = [];

    /** When true the fake downloader serves no checksum, as an old release would. */
    private bool $checksumMissing = false;

    private int $inventoryRefreshes = 0;

    /** @var list<string> The order operations actually happened in. */
    private array $journal = [];

    /** Version the fake upgrader claims to have put on disk. Null means "the catalogue version". */
    private ?string $installsVersion = null;

    /** When true the fake upgrader reports success and quietly installs nothing. */
    private bool $installsNothing = false;

    /** @var true|WP_Error What the fake upgrader returns. */
    private mixed $installResult = true;

    private mixed $activationResult = null;

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        CatalogueRepository::resetSharedInstanceForTests();

        $GLOBALS['_fchub_hub_test_filters'] = [];
        $GLOBALS['_fchub_hub_test_capabilities'] = [
            'manage_options',
            'install_plugins',
            'activate_plugins',
            'update_plugins',
        ];

        $this->installed = [];
        $this->active = [self::FLUENTCART];
        $this->installCalls = [];
        $this->activateCalls = [];
        $this->deactivateCalls = [];
        $this->downloadedPackages = [];
        $this->logged = [];
        $this->checksumMissing = false;
        $this->inventoryRefreshes = 0;
        $this->journal = [];
        $this->installsVersion = null;
        $this->installsNothing = false;
        $this->installResult = true;
        $this->activationResult = null;
        $this->temporary = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        CatalogueRepository::resetSharedInstanceForTests();
        $GLOBALS['_fchub_hub_test_filters'] = [];
        $GLOBALS['_fchub_hub_test_capabilities'] = [];

        parent::tearDown();
    }

    public function testTheCapabilityMapIsExactlyTheAgreedOne(): void
    {
        $agreed = [
            'install' => 'install_plugins',
            'install-and-activate' => ['install_plugins', 'activate_plugins'],
            'activate' => 'activate_plugins',
            'update' => 'update_plugins',
            'deactivate' => 'activate_plugins',
        ];

        $normalised = array_map(
            static fn ($capabilities): array => (array) $capabilities,
            $agreed
        );

        self::assertSame($normalised, ProductOperationService::CAPABILITIES);
    }

    public function testUserCanChecksEveryCapabilityAnActionNeeds(): void
    {
        $GLOBALS['_fchub_hub_test_capabilities'] = ['install_plugins'];

        self::assertTrue(ProductOperationService::userCan('install'));
        self::assertFalse(
            ProductOperationService::userCan('install-and-activate'),
            'Installing and switching on needs both capabilities, not the friendlier one.'
        );
        self::assertFalse(ProductOperationService::userCan('activate'));
        self::assertFalse(ProductOperationService::userCan('update'));
    }

    public function testAnUnrecognisedActionIsNeverPermitted(): void
    {
        self::assertFalse(ProductOperationService::userCan('delete-everything'));
    }

    public function testAnUnknownSlugIsRefusedWithoutTouchingTheNetwork(): void
    {
        $error = $this->refused(fn () => $this->service()->install('definitely-not-ours'));

        self::assertSame('product_unknown', $error->code());
        self::assertSame([], $this->downloadedPackages);
        self::assertSame([], $this->installCalls);
    }

    public function testInstallRequiresTheInstallCapability(): void
    {
        $GLOBALS['_fchub_hub_test_capabilities'] = ['manage_options'];

        $error = $this->refused(fn () => $this->service()->install('fchub-memberships'));

        self::assertSame('insufficient_capability', $error->code());
        self::assertSame([], $this->downloadedPackages);
    }

    public function testInstallAndActivateRequiresBothCapabilities(): void
    {
        $GLOBALS['_fchub_hub_test_capabilities'] = ['manage_options', 'install_plugins'];

        $error = $this->refused(fn () => $this->service()->installAndActivate('fchub-memberships'));

        self::assertSame('insufficient_capability', $error->code());
        self::assertSame([], $this->installCalls);
    }

    public function testActivateRequiresTheActivateCapability(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];
        $GLOBALS['_fchub_hub_test_capabilities'] = ['manage_options', 'install_plugins', 'update_plugins'];

        $error = $this->refused(fn () => $this->service()->activate('fchub-memberships'));

        self::assertSame('insufficient_capability', $error->code());
        self::assertSame([], $this->activateCalls);
    }

    public function testUpdateRequiresTheUpdateCapability(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.3.0']];
        $GLOBALS['_fchub_hub_test_capabilities'] = ['manage_options', 'install_plugins', 'activate_plugins'];

        $error = $this->refused(fn () => $this->service()->update('fchub-memberships'));

        self::assertSame('insufficient_capability', $error->code());
        self::assertSame([], $this->installCalls);
    }

    public function testDeactivateRequiresTheActivateCapability(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];
        $this->active = [self::MEMBERSHIPS, self::FLUENTCART];
        $GLOBALS['_fchub_hub_test_capabilities'] = ['manage_options', 'install_plugins', 'update_plugins'];

        $error = $this->refused(fn () => $this->service()->deactivate('fchub-memberships'));

        self::assertSame('insufficient_capability', $error->code());
        self::assertSame([], $this->deactivateCalls);
    }

    public function testAnIncompatiblePhpVersionStopsAnInstallBeforeAnyDownload(): void
    {
        $error = $this->refused(fn () => $this->service(phpVersion: '8.2.0')->install('fchub-memberships'));

        self::assertSame('product_incompatible', $error->code());
        self::assertSame('Memberships needs PHP 8.3 before it can be activated.', $error->publicMessage());
        self::assertSame([], $this->downloadedPackages, 'Nothing is downloaded for a product that cannot run here.');
    }

    public function testAnOldWordPressStopsAnInstallWithTheExactRequirement(): void
    {
        $error = $this->refused(fn () => $this->service(wpVersion: '6.5')->install('fchub-memberships'));

        self::assertSame('product_incompatible', $error->code());
        self::assertSame('Memberships needs WordPress 6.7 before it can be activated.', $error->publicMessage());
    }

    public function testAMissingFluentCartStopsAnInstallAndSaysSoInPlainEnglish(): void
    {
        $this->active = [];

        $error = $this->refused(fn () => $this->service()->install('fchub-memberships'));

        self::assertSame('product_incompatible', $error->code());
        self::assertSame(
            'Memberships needs FluentCart to be installed and active first.',
            $error->publicMessage()
        );
        self::assertSame([], $this->downloadedPackages);
    }

    public function testARequirementFchubCannotCheckStopsTheOperationHonestly(): void
    {
        $catalogue = CatalogueFixtures::raw();
        $catalogue['products']['fchub-memberships']['dependencies'] = ['some-future-platform'];

        $error = $this->refused(fn () => $this->service(catalogue: $catalogue)->install('fchub-memberships'));

        self::assertSame('product_incompatible', $error->code());
        self::assertSame(
            'Memberships has a requirement FCHub cannot check here, so it was left alone.',
            $error->publicMessage()
        );
    }

    public function testASuccessfulInstallSwitchesNothingOn(): void
    {
        $result = $this->service()->install('fchub-memberships');

        self::assertSame([['zip', 'overwrite:no']], $this->summarisedInstalls());
        self::assertSame([], $this->activateCalls, 'Install means install. Nothing more.');
        self::assertSame(1, $this->inventoryRefreshes);
        self::assertSame('Memberships is installed and ready when you are.', $result['notice']);
        self::assertSame('fchub-memberships', $result['slug']);
    }

    public function testTheTemporaryPackageIsDeletedAfterASuccessfulInstall(): void
    {
        $this->service()->install('fchub-memberships');

        self::assertCount(1, $this->downloadedPackages);
        self::assertFileDoesNotExist($this->downloadedPackages[0]);
    }

    public function testTheUpgraderIsHandedTheVerifiedPackageAndNothingElse(): void
    {
        // The one link in the chain that would otherwise be taken on faith: a
        // defect that verified one file and installed another would satisfy
        // every other assertion in this class.
        $this->service()->install('fchub-memberships');

        self::assertCount(1, $this->installCalls);
        self::assertSame($this->downloadedPackages[0], $this->installCalls[0]['zip']);
    }

    public function testAnInstallWithNoChecksumAvailableIsRecordedForTheOperator(): void
    {
        $this->checksumMissing = true;

        $result = $this->service()->install('fchub-memberships');

        self::assertSame(
            ['checksum_unavailable: no checksum for fchub-memberships 1.4.0, installing anyway'],
            $this->logged,
            'The one path where FCHub installs something it could not verify is not allowed to be silent.'
        );
        self::assertStringNotContainsString('checksum', $result['notice'], 'The customer gets a notice, not a lecture.');
    }

    public function testAVerifiedInstallLogsNothingAtAll(): void
    {
        $this->service()->install('fchub-memberships');

        self::assertSame([], $this->logged);
    }

    public function testTheUnverifiedInstallIsRecordedEvenWhenTheUpgraderThenRefusesIt(): void
    {
        // The event worth recording is the decision to hand unverified bytes to
        // the upgrader. Whether the upgrader accepts them is a separate
        // outcome, and the line must not claim an install that never happened.
        $this->checksumMissing = true;
        $this->installResult = new WP_Error('fs_unavailable', 'Could not access filesystem');

        $error = $this->refused(fn () => $this->service()->install('fchub-memberships'));

        self::assertSame('installation_failed', $error->code());
        self::assertSame(
            ['checksum_unavailable: no checksum for fchub-memberships 1.4.0, installing anyway'],
            $this->logged
        );
        self::assertStringNotContainsString('installed without', $this->logged[0], 'Past tense would be a lie here.');
    }

    public function testInstallAndActivateInstallsFirstAndThenSwitchesOn(): void
    {
        $result = $this->service()->installAndActivate('fchub-memberships');

        self::assertSame(['install', 'activate'], $this->journal);
        self::assertSame([self::MEMBERSHIPS], $this->activateCalls);
        self::assertSame('Memberships is installed and switched on.', $result['notice']);
    }

    public function testAFailedUpgraderReportsAFriendlyErrorAndDeletesTheTemporaryPackage(): void
    {
        $this->installResult = new WP_Error('fs_unavailable', 'Could not access filesystem');

        $error = $this->refused(fn () => $this->service()->install('fchub-memberships'));

        self::assertSame('installation_failed', $error->code());
        self::assertSame(
            'WordPress could not install that package. No other product was touched.',
            $error->publicMessage()
        );
        self::assertCount(1, $this->downloadedPackages);
        self::assertFileDoesNotExist($this->downloadedPackages[0], 'A failed install still owns its temporary file.');
    }

    public function testAMissingPluginFileAfterInstallIsReportedAsAFailure(): void
    {
        // The upgrader claimed success but nothing FCHub recognises turned up.
        $this->installsNothing = true;

        $error = $this->refused(fn () => $this->service()->install('fchub-memberships'));

        self::assertSame('installation_failed', $error->code());
        self::assertFileDoesNotExist($this->downloadedPackages[0]);
    }

    public function testInstallingSomethingAlreadyInstalledIsRefused(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];

        $error = $this->refused(fn () => $this->service()->install('fchub-memberships'));

        self::assertSame('product_already_installed', $error->code());
        self::assertSame([], $this->downloadedPackages);
    }

    public function testActivatingAnAlreadyActiveProductIsRefused(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];
        $this->active = [self::MEMBERSHIPS, self::FLUENTCART];

        $error = $this->refused(fn () => $this->service()->activate('fchub-memberships'));

        self::assertSame('product_already_active', $error->code());
        self::assertSame('Memberships is already switched on.', $error->publicMessage());
        self::assertSame([], $this->activateCalls);
    }

    public function testActivatingSomethingThatIsNotInstalledIsRefused(): void
    {
        $error = $this->refused(fn () => $this->service()->activate('fchub-memberships'));

        self::assertSame('product_not_installed', $error->code());
        self::assertSame([], $this->activateCalls);
    }

    public function testActivationIsRefusedWhenTheProductCannotRunHere(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];

        $error = $this->refused(fn () => $this->service(phpVersion: '8.2.0')->activate('fchub-memberships'));

        self::assertSame('product_incompatible', $error->code());
        self::assertSame([], $this->activateCalls);
    }

    public function testAWordPressActivationErrorBecomesAFriendlySentence(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];
        $this->activationResult = new WP_Error('plugin_php_incompatible', 'The plugin requires a newer PHP');

        $error = $this->refused(fn () => $this->service()->activate('fchub-memberships'));

        self::assertSame('activation_failed', $error->code());
        self::assertSame('Memberships could not be switched on, so nothing was changed.', $error->publicMessage());
    }

    public function testAnInstallThatCannotBeSwitchedOnSaysWhatActuallyHappened(): void
    {
        $this->activationResult = new WP_Error('plugin_php_incompatible', 'The plugin requires a newer PHP');

        $error = $this->refused(fn () => $this->service()->installAndActivate('fchub-memberships'));

        self::assertSame('activation_failed', $error->code());
        self::assertSame(
            'Memberships is installed, but it could not be switched on.',
            $error->publicMessage(),
            'Half an operation is still half an operation.'
        );
        self::assertArrayHasKey(self::MEMBERSHIPS, $this->installed);
    }

    public function testActivateSwitchesOnAnInstalledProduct(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];

        $result = $this->service()->activate('fchub-memberships');

        self::assertSame([self::MEMBERSHIPS], $this->activateCalls);
        self::assertSame([], $this->installCalls);
        self::assertSame('Memberships is switched on.', $result['notice']);
    }

    public function testUpdateOverwritesThePackageAndConfirmsTheInstalledVersion(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.3.0']];
        $this->active = [self::MEMBERSHIPS, self::FLUENTCART];

        $result = $this->service()->update('fchub-memberships');

        self::assertSame([['zip', 'overwrite:yes']], $this->summarisedInstalls());
        self::assertSame('1.4.0', $this->installed[self::MEMBERSHIPS]['Version']);
        self::assertSame('Memberships is now on 1.4.0.', $result['notice']);
        self::assertSame([], $this->activateCalls, 'An update never changes whether a product is switched on.');
    }

    public function testTheUpgraderIsToldWhichPluginFileAnUpdateIsReplacing(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.3.0']];

        $this->service()->update('fchub-memberships');

        // Without this the package options below have nothing to name, and the
        // whole rollback guarantee quietly turns itself off.
        self::assertSame(self::MEMBERSHIPS, $this->installCalls[0]['plugin_file']);
    }

    public function testAnUpdateGivesCoreTheTempBackupItRollsBackFrom(): void
    {
        // The one path that can leave a customer's site missing a plugin: with
        // overwrite_package set, core deletes the product's directory before it
        // copies, and only restores it when a temp_backup exists.
        $options = (ProductOperationService::packageOptions(true, self::MEMBERSHIPS))([
            'package' => '/tmp/fchub-memberships-1.4.0.zip',
            'hook_extra' => ['type' => 'plugin', 'action' => 'install'],
        ]);

        self::assertSame(
            [
                'slug' => 'fchub-memberships',
                'src' => WP_PLUGIN_DIR,
                'dir' => 'plugins',
            ],
            $options['hook_extra']['temp_backup']
        );

        // An update through FCHub has to reach upgrader_process_complete as the
        // same event an update through the Plugins screen does.
        self::assertSame('update', $options['hook_extra']['action']);
        self::assertSame(self::MEMBERSHIPS, $options['hook_extra']['plugin']);
        self::assertSame('plugin', $options['hook_extra']['type'], 'The keys core set survive untouched.');
        self::assertSame('/tmp/fchub-memberships-1.4.0.zip', $options['package']);
    }

    public function testAFreshInstallIsHandedToCoreExactlyAsCoreBuiltIt(): void
    {
        // Nothing to restore and nothing being updated. Claiming otherwise
        // would ask core to back up a directory that does not exist yet.
        $original = ['package' => '/tmp/fchub-memberships-1.4.0.zip', 'hook_extra' => ['type' => 'plugin']];

        self::assertSame($original, (ProductOperationService::packageOptions(false, self::MEMBERSHIPS))($original));
        self::assertSame($original, (ProductOperationService::packageOptions(true, ''))($original));
    }

    public function testUpdateFailsWhenTheFilesAreNotTheReleaseWeExpected(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.3.0']];
        $this->installsVersion = '1.3.9';

        $error = $this->refused(fn () => $this->service()->update('fchub-memberships'));

        self::assertSame('version_mismatch', $error->code());
        self::assertStringContainsString('1.4.0', $error->publicMessage());
        self::assertStringNotContainsString('1.3.9', $error->publicMessage(), 'The message states what we expected, not what arrived.');
    }

    public function testUpdateIsRefusedWhenThereIsNothingNewerToInstall(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];

        $error = $this->refused(fn () => $this->service()->update('fchub-memberships'));

        self::assertSame('update_unavailable', $error->code());
        self::assertSame([], $this->downloadedPackages);
    }

    public function testUpdateIsRefusedForSomethingThatIsNotInstalled(): void
    {
        $error = $this->refused(fn () => $this->service()->update('fchub-memberships'));

        self::assertSame('product_not_installed', $error->code());
    }

    public function testDeactivateSwitchesOffACatalogueProduct(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];
        $this->active = [self::MEMBERSHIPS, self::FLUENTCART];

        $result = $this->service()->deactivate('fchub-memberships');

        self::assertSame([self::MEMBERSHIPS], $this->deactivateCalls);
        self::assertSame('Memberships is switched off. Its data is exactly where you left it.', $result['notice']);
    }

    public function testAnIncompatibleProductCanStillBeSwitchedOff(): void
    {
        // Trapping an administrator with a plugin they cannot disable would be
        // a bold interpretation of calm.
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];
        $this->active = [self::MEMBERSHIPS, self::FLUENTCART];

        $this->service(phpVersion: '8.2.0')->deactivate('fchub-memberships');

        self::assertSame([self::MEMBERSHIPS], $this->deactivateCalls);
    }

    public function testDeactivatingSomethingAlreadyInactiveIsRefused(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];

        $error = $this->refused(fn () => $this->service()->deactivate('fchub-memberships'));

        self::assertSame('product_not_active', $error->code());
        self::assertSame([], $this->deactivateCalls);
    }

    public function testDeactivateCanNeverSwitchOffFchubItself(): void
    {
        $this->installed = ['fchub/fchub.php' => ['Version' => '1.0.0']];
        $this->active = ['fchub/fchub.php', self::FLUENTCART];

        $error = $this->refused(fn () => $this->service()->deactivate('fchub'));

        self::assertSame('product_unknown', $error->code(), 'FCHub is not one of its own products.');
        self::assertSame([], $this->deactivateCalls);
        self::assertContains('fchub/fchub.php', $this->active, 'The hub is still standing.');
    }

    public function testNoOperationAtAllWillActOnTheHubSlug(): void
    {
        $this->installed = ['fchub/fchub.php' => ['Version' => '1.0.0']];
        $this->active = ['fchub/fchub.php', self::FLUENTCART];

        foreach (['install', 'installAndActivate', 'activate', 'update', 'deactivate'] as $operation) {
            $error = $this->refused(fn () => $this->service()->{$operation}('fchub'));

            self::assertSame('product_unknown', $error->code(), $operation . ' must refuse the hub slug.');
        }

        self::assertSame([], $this->downloadedPackages);
        self::assertSame([], $this->installCalls);
        self::assertSame([], $this->activateCalls);
        self::assertSame([], $this->deactivateCalls);
    }

    public function testOneFailedOperationLeavesEveryOtherProductAlone(): void
    {
        $this->installed = [self::P24 => ['Version' => '1.0.3']];
        $this->active = [self::P24, self::FLUENTCART];
        $this->installResult = new WP_Error('fs_unavailable', 'Could not access filesystem');

        $this->refused(fn () => $this->service()->install('fchub-memberships'));

        self::assertSame([self::P24 => ['Version' => '1.0.3']], $this->installed);
        self::assertSame([self::P24, self::FLUENTCART], $this->active);
        self::assertSame([], $this->deactivateCalls);
    }

    public function testSnapshotCarriesTheCatalogueSourceAndEveryProductState(): void
    {
        $this->installed = [self::P24 => ['Version' => '1.0.3']];
        $this->active = [self::P24, self::FLUENTCART];

        $snapshot = $this->service()->snapshot();

        self::assertSame('remote', $snapshot['source']);
        self::assertSame(['fchub-p24', 'fchub-memberships'], array_keys($snapshot['states']));
        self::assertSame('active', $snapshot['states']['fchub-p24']['lifecycle']);
        self::assertSame('not_installed', $snapshot['states']['fchub-memberships']['lifecycle']);
        self::assertArrayHasKey('products', $snapshot['catalogue']);
    }

    /**
     * @param array<string, mixed>|null $catalogue
     */
    private function service(
        string $phpVersion = '8.3.0',
        string $wpVersion = '6.7',
        ?array $catalogue = null
    ): ProductOperationService {
        $payload = $catalogue ?? CatalogueFixtures::raw();

        $repository = new CatalogueRepository(
            fetch: static fn (): array => ['code' => 200, 'body' => (string) json_encode($payload), 'etag' => ''],
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

        return new ProductOperationService(
            repository: $repository,
            resolver: new ProductStateResolver($phpVersion, $wpVersion),
            downloader: new VerifiedPackageDownloader($this->fakeDownloader()),
            installedPlugins: fn (): array => $this->installed,
            activePlugins: fn (): array => $this->active,
            installer: fn (string $zip, bool $overwrite, string $pluginFile): mixed => $this->fakeInstall(
                $zip,
                $overwrite,
                $pluginFile
            ),
            activator: fn (string $pluginFile): mixed => $this->fakeActivate($pluginFile),
            deactivator: function (string $pluginFile): void {
                $this->deactivateCalls[] = $pluginFile;
                $this->active = array_values(array_diff($this->active, [$pluginFile]));
            },
            refreshInventory: function (): void {
                $this->inventoryRefreshes++;
            },
            logger: function (string $message): void {
                $this->logged[] = $message;
            }
        );
    }

    private function fakeDownloader(): Closure
    {
        return function (string $url) {
            $isChecksum = str_ends_with($url, '.sha256');

            if ($isChecksum && $this->checksumMissing) {
                return new WP_Error('http_404', 'Not Found');
            }

            $path = (string) tempnam(sys_get_temp_dir(), 'fchub-op-');

            file_put_contents(
                $path,
                $isChecksum ? hash('sha256', self::PACKAGE_BODY) : self::PACKAGE_BODY
            );

            $this->temporary[] = $path;

            if (!$isChecksum) {
                $this->downloadedPackages[] = $path;
            }

            return $path;
        };
    }

    private function fakeInstall(string $zip, bool $overwrite, string $pluginFile = ''): mixed
    {
        $this->installCalls[] = ['zip' => $zip, 'overwrite' => $overwrite, 'plugin_file' => $pluginFile];
        $this->journal[] = 'install';

        if ($this->installResult !== true) {
            return $this->installResult;
        }

        if (!$this->installsNothing) {
            $this->installed[self::MEMBERSHIPS] = ['Version' => $this->installsVersion ?? '1.4.0'];
        }

        return true;
    }

    private function fakeActivate(string $pluginFile): mixed
    {
        $this->activateCalls[] = $pluginFile;
        $this->journal[] = 'activate';

        if ($this->activationResult !== null) {
            return $this->activationResult;
        }

        $this->active[] = $pluginFile;

        return null;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function summarisedInstalls(): array
    {
        return array_map(
            static fn (array $call): array => ['zip', 'overwrite:' . ($call['overwrite'] ? 'yes' : 'no')],
            $this->installCalls
        );
    }

    private function refused(Closure $operation): OperationError
    {
        try {
            $operation();
        } catch (OperationError $error) {
            return $error;
        }

        self::fail('The operation was expected to be refused.');
    }
}
