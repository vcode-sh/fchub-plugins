<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use FChubHub\Catalogue\ProductStateResolver;
use FChubHub\Operations\ProductOperationService;
use FChubHub\Tests\Support\CatalogueFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ProductStateResolverTest extends TestCase
{
    private const MEMBERSHIPS = 'fchub-memberships/fchub-memberships.php';
    private const P24 = 'fchub-p24/fchub-p24.php';
    private const FLUENTCART = 'fluent-cart/fluent-cart.php';

    /** @var array<string, mixed> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_hub_test_filters'] = [];
        $this->catalogue = CatalogueFixtures::normalised();
        $GLOBALS['_fchub_hub_test_capabilities'] = [
            'manage_options',
            'install_plugins',
            'activate_plugins',
            'update_plugins',
        ];
    }

    protected function tearDown(): void
    {
        // Four capabilities left lying around would silently grant every action
        // to whatever class runs next.
        $GLOBALS['_fchub_hub_test_capabilities'] = [];
        $GLOBALS['_fchub_hub_test_filters'] = [];

        parent::tearDown();
    }

    public function testTheScreensCapabilityMapNeverDriftsFromTheOperationsOne(): void
    {
        // Two copies on purpose — app/Catalogue must not depend on
        // app/Operations — and therefore two copies that need pinning. Drift
        // here is invisible until a customer clicks a button the operation
        // behind it refuses, or wonders where a button they are entitled to went.
        $reflected = new ReflectionClass(ProductStateResolver::class);

        self::assertSame(
            ProductOperationService::CAPABILITIES,
            $reflected->getConstant('ACTION_CAPABILITIES')
        );
    }

    public function testAnActionNeitherMapKnowsIsRefusedRatherThanFatal(): void
    {
        // Reached through reflection because the only way to get here in
        // production is the drift the test above forbids — and a read-only REST
        // route is not the place to find out with an undefined-key fatal.
        $permitted = new ReflectionMethod(ProductStateResolver::class, 'isPermitted');

        self::assertFalse($permitted->invoke(new ProductStateResolver('8.3.0', '6.7'), 'delete'));
    }

    public function testResolvesTheFourDimensionsIndependentlyForAnActiveOutdatedBlockedProduct(): void
    {
        $state = $this->resolveMemberships(
            phpVersion: '8.2.0',
            installed: [self::MEMBERSHIPS => ['Version' => '1.3.0']],
            active: [self::MEMBERSHIPS, self::FLUENTCART]
        );

        self::assertSame('active', $state['lifecycle']);
        self::assertSame('available', $state['update']);
        self::assertSame('blocked', $state['compatibility']);
        self::assertSame('php', $state['compatibility_reason']['requirement']);
        self::assertSame('8.3', $state['compatibility_reason']['required']);
        self::assertSame('8.2.0', $state['compatibility_reason']['current']);
    }

    public function testEveryStateCarriesTheFullShape(): void
    {
        $state = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => '1.4.0']],
            active: [self::MEMBERSHIPS, self::FLUENTCART]
        );

        self::assertSame([
            'slug',
            'lifecycle',
            'update',
            'compatibility',
            'compatibility_reason',
            'health',
            'health_message',
            'installed_version',
            'admin_url',
            'actions',
        ], array_keys($state));

        self::assertSame('fchub-memberships', $state['slug']);
        self::assertSame('current', $state['update']);
        self::assertSame('compatible', $state['compatibility']);
        self::assertNull($state['compatibility_reason']);
        self::assertSame('unknown', $state['health']);
        self::assertNull($state['health_message']);
        self::assertSame('1.4.0', $state['installed_version']);
    }

    public function testANotInstalledProductOffersInstallActions(): void
    {
        $state = $this->resolveMemberships(active: [self::FLUENTCART]);

        self::assertSame('not_installed', $state['lifecycle']);
        self::assertSame('unknown', $state['update']);
        self::assertSame('compatible', $state['compatibility']);
        self::assertNull($state['installed_version']);
        self::assertNull($state['admin_url']);
        self::assertSame(['install', 'install-and-activate'], $state['actions']);
    }

    public function testAnInstalledButInactivePluginOffersActivation(): void
    {
        $state = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => '1.4.0']],
            active: [self::FLUENTCART]
        );

        self::assertSame('inactive', $state['lifecycle']);
        self::assertSame(['activate'], $state['actions']);
        self::assertNull($state['admin_url'], 'A settings link is useless while the plugin is switched off.');
    }

    public function testAnInactivePluginWithAnUpdateOffersBoth(): void
    {
        $state = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => '1.3.0']],
            active: [self::FLUENTCART]
        );

        self::assertSame('available', $state['update']);
        self::assertSame(['activate', 'update'], $state['actions']);
    }

    public function testAMissingFluentCartBlocksCompatibilityWithoutBlockingTheHub(): void
    {
        $state = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => '1.4.0']],
            active: []
        );

        self::assertSame('blocked', $state['compatibility']);
        self::assertSame([
            'requirement' => 'dependency',
            'required' => 'fluentcart',
            'current' => null,
        ], $state['compatibility_reason']);
        self::assertSame([], $state['actions'], 'Nothing unsafe is offered while a dependency is missing.');
    }

    public function testAnOldWordPressBlocksCompatibility(): void
    {
        $state = $this->resolveMemberships(
            wpVersion: '6.4.2',
            installed: [self::MEMBERSHIPS => ['Version' => '1.4.0']],
            active: [self::MEMBERSHIPS, self::FLUENTCART]
        );

        self::assertSame('blocked', $state['compatibility']);
        self::assertSame('wp', $state['compatibility_reason']['requirement']);
        self::assertSame('6.7', $state['compatibility_reason']['required']);
        self::assertSame('6.4.2', $state['compatibility_reason']['current']);
    }

    public function testAnActiveButBlockedProductCanStillBeDeactivated(): void
    {
        $state = $this->resolveMemberships(
            phpVersion: '8.2.0',
            installed: [self::MEMBERSHIPS => ['Version' => '1.3.0']],
            active: [self::MEMBERSHIPS, self::FLUENTCART]
        );

        self::assertSame(['deactivate'], $state['actions'], 'Updating a blocked product is withheld; switching it off is not.');
    }

    public function testAnUnverifiableDependencyLeavesCompatibilityUnknown(): void
    {
        $this->catalogue['products']['fchub-memberships']['dependencies'] = ['some-future-platform'];

        $state = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => '1.4.0']],
            active: [self::MEMBERSHIPS]
        );

        self::assertSame('unknown', $state['compatibility']);
        self::assertSame('some-future-platform', $state['compatibility_reason']['required']);
        self::assertSame(['deactivate'], $state['actions']);
    }

    public function testAnUnreadableInstalledVersionLeavesTheUpdateDimensionUnknown(): void
    {
        $state = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => 'trunk']],
            active: [self::MEMBERSHIPS, self::FLUENTCART]
        );

        self::assertSame('active', $state['lifecycle']);
        self::assertSame('unknown', $state['update']);
        self::assertSame('compatible', $state['compatibility']);
        self::assertSame(['deactivate'], $state['actions']);
    }

    public function testCapabilitiesGateEveryOfferedAction(): void
    {
        $GLOBALS['_fchub_hub_test_capabilities'] = ['manage_options'];

        $notInstalled = $this->resolveMemberships(active: [self::FLUENTCART]);
        self::assertSame([], $notInstalled['actions']);

        $outdated = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => '1.3.0']],
            active: [self::MEMBERSHIPS, self::FLUENTCART]
        );
        self::assertSame([], $outdated['actions']);
    }

    public function testInstallWithoutActivationIsOfferedWhenOnlyInstallIsPermitted(): void
    {
        $GLOBALS['_fchub_hub_test_capabilities'] = ['manage_options', 'install_plugins'];

        $state = $this->resolveMemberships(active: [self::FLUENTCART]);

        self::assertSame(['install'], $state['actions']);
    }

    public function testAValidDescriptorEnrichesHealthAndTheSettingsLinkForAnActiveProduct(): void
    {
        $state = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => '1.4.0']],
            active: [self::MEMBERSHIPS, self::FLUENTCART],
            descriptors: [
                'fchub-memberships' => [
                    'admin_path' => 'admin.php?page=fchub-memberships#/plans',
                    'health' => ['status' => 'attention', 'message' => 'Two plans have no content rules.'],
                ],
            ]
        );

        self::assertSame('attention', $state['health']);
        self::assertSame('Two plans have no content rules.', $state['health_message']);
        self::assertSame(
            'https://example.com/wp-admin/admin.php?page=fchub-memberships#/plans',
            $state['admin_url']
        );
    }

    public function testTheCatalogueAdminPathIsUsedWhenNoDescriptorSuppliesOne(): void
    {
        $state = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => '1.4.0']],
            active: [self::MEMBERSHIPS, self::FLUENTCART]
        );

        self::assertSame(
            'https://example.com/wp-admin/admin.php?page=fchub-memberships',
            $state['admin_url']
        );
    }

    public function testADescriptorForAProductThatIsNotRunningIsIgnored(): void
    {
        // Only an active plugin can add its own descriptor. Anything claiming
        // to speak for an inactive product is speaking for someone else.
        $state = $this->resolveMemberships(
            installed: [self::MEMBERSHIPS => ['Version' => '1.4.0']],
            active: [self::FLUENTCART],
            descriptors: [
                'fchub-memberships' => [
                    'admin_path' => 'admin.php?page=impostor',
                    'health' => ['status' => 'healthy', 'message' => 'All good, honest.'],
                ],
            ]
        );

        self::assertSame('unknown', $state['health']);
        self::assertNull($state['health_message']);
        self::assertNull($state['admin_url']);
    }

    public function testADescriptorNeverAltersVersionUpdateOrCompatibilityData(): void
    {
        $state = $this->resolveMemberships(
            phpVersion: '8.2.0',
            installed: [self::MEMBERSHIPS => ['Version' => '1.3.0']],
            active: [self::MEMBERSHIPS, self::FLUENTCART],
            descriptors: [
                'fchub-memberships' => [
                    'admin_path' => null,
                    'health' => ['status' => 'healthy', 'message' => 'Nothing to see here.'],
                ],
            ]
        );

        self::assertSame('healthy', $state['health']);
        self::assertSame('available', $state['update']);
        self::assertSame('blocked', $state['compatibility']);
        self::assertSame('1.3.0', $state['installed_version']);
    }

    public function testResolvesEveryCatalogueProductKeyedBySlug(): void
    {
        $states = (new ProductStateResolver('8.3.0', '6.7'))->resolve(
            $this->catalogue,
            [self::P24 => ['Version' => '1.0.3']],
            [self::P24, self::FLUENTCART],
            []
        );

        self::assertSame(['fchub-p24', 'fchub-memberships'], array_keys($states));
        self::assertSame('active', $states['fchub-p24']['lifecycle']);
        self::assertSame('not_installed', $states['fchub-memberships']['lifecycle']);
    }

    public function testSiteReportsTheVersionsEveryDecisionWasMadeAgainst(): void
    {
        $site = (new ProductStateResolver('8.3.0', '6.7'))->site(
            [self::FLUENTCART => ['Version' => '1.2.3']],
            [self::FLUENTCART]
        );

        self::assertSame(['wp' => '6.7', 'php' => '8.3.0', 'fluentcart' => '1.2.3'], $site);
    }

    public function testSiteCallsFluentCartAbsentWhenItIsInstalledButSwitchedOff(): void
    {
        $site = (new ProductStateResolver('8.3.0', '6.7'))->site(
            [self::FLUENTCART => ['Version' => '1.2.3']],
            []
        );

        // A product that needs FluentCart will not load against a deactivated
        // copy either, so reporting its version would be a comforting lie.
        self::assertNull($site['fluentcart']);
    }

    public function testSiteCallsFluentCartAbsentWhenItIsNotInstalledAtAll(): void
    {
        $site = (new ProductStateResolver('8.3.0', '6.7'))->site([], []);

        self::assertNull($site['fluentcart']);
    }

    public function testSiteFallsBackToNullRatherThanInventingAVersion(): void
    {
        // Active according to WordPress, but absent from its own inventory and
        // with no constant defined. Broken, and not something to guess about.
        $site = (new ProductStateResolver('8.3.0', '6.7'))->site([], [self::FLUENTCART]);

        self::assertNull($site['fluentcart']);
    }

    public function testSiteNeverInventsAWordPressVersionItWasNotGiven(): void
    {
        $site = (new ProductStateResolver('8.1.2', ''))->site([], []);

        self::assertSame('', $site['wp']);
        self::assertSame('8.1.2', $site['php']);
    }

    /**
     * @param array<string, array<string, mixed>> $installed
     * @param list<string> $active
     * @param array<string, array<string, mixed>> $descriptors
     * @return array<string, mixed>
     */
    private function resolveMemberships(
        string $phpVersion = '8.3.0',
        string $wpVersion = '6.7',
        array $installed = [],
        array $active = [],
        array $descriptors = []
    ): array {
        $states = (new ProductStateResolver($phpVersion, $wpVersion))
            ->resolve($this->catalogue, $installed, $active, $descriptors);

        return $states['fchub-memberships'];
    }
}
