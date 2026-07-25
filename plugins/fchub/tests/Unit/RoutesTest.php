<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use Closure;
use FChubHub\Catalogue\CatalogueRepository;
use FChubHub\Catalogue\ProductStateResolver;
use FChubHub\Core\Plugin;
use FChubHub\Http\ProductController;
use FChubHub\Http\Routes;
use FChubHub\Operations\ProductOperationService;
use FChubHub\Operations\VerifiedPackageDownloader;
use FChubHub\Tests\Support\CatalogueFixtures;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use UnexpectedValueException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The REST contract: which routes exist, who is allowed through them, and what
 * the client is — and is not — told when something goes wrong.
 */
final class RoutesTest extends TestCase
{
    private const MEMBERSHIPS = 'fchub-memberships/fchub-memberships.php';
    private const FLUENTCART = 'fluent-cart/fluent-cart.php';

    private const ALL_CAPABILITIES = [
        'manage_options',
        'install_plugins',
        'activate_plugins',
        'update_plugins',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $installed = [];

    /** @var list<string> */
    private array $active = [];

    /** @var list<string> */
    private array $logged = [];

    /** @var list<string> */
    private array $temporary = [];

    /** How many times the catalogue endpoint was actually dialled. */
    private int $catalogueFetches = 0;

    /** Reads of the plugin inventory so far. */
    private int $inventoryReads = 0;

    /** Which inventory read starts failing, or null for a healthy site. */
    private ?int $inventoryFailsAfter = null;

    /**
     * The version the upgrader claims to have put on disk, or null to keep the
     * download-and-install path barred. Arming it is how the one test that needs
     * a real mutation gets one; every other test still fails loudly if it tries.
     */
    private ?string $upgraderInstalls = null;

    /** What the activator hands back. A WP_Error is a refusal from WordPress. */
    private mixed $activationResult = null;

    protected function setUp(): void
    {
        parent::setUp();

        CatalogueRepository::resetSharedInstanceForTests();

        $GLOBALS['_fchub_hub_test_rest_routes'] = [];
        $GLOBALS['_fchub_hub_test_action_registrations'] = [];
        $GLOBALS['_fchub_hub_test_filters'] = [];
        $GLOBALS['_fchub_hub_test_capabilities'] = self::ALL_CAPABILITIES;

        $this->installed = [];
        $this->active = [self::FLUENTCART];
        $this->logged = [];
        $this->temporary = [];
        $this->catalogueFetches = 0;
        $this->inventoryReads = 0;
        $this->inventoryFailsAfter = null;
        $this->upgraderInstalls = null;
        $this->activationResult = null;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        CatalogueRepository::resetSharedInstanceForTests();

        $GLOBALS['_fchub_hub_test_rest_routes'] = [];
        $GLOBALS['_fchub_hub_test_action_registrations'] = [];
        $GLOBALS['_fchub_hub_test_filters'] = [];
        $GLOBALS['_fchub_hub_test_capabilities'] = [];

        parent::tearDown();
    }

    public function testRegistersExactlyTheAgreedRoutesAndMethods(): void
    {
        Routes::register();

        self::assertSame([
            'GET /products',
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/install',
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/install-and-activate',
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/activate',
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/update',
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/deactivate',
            'POST /catalogue/refresh',
        ], $this->registeredRoutes());
    }

    public function testEveryRouteLivesInTheFchubNamespace(): void
    {
        Routes::register();

        foreach ($GLOBALS['_fchub_hub_test_rest_routes'] as $route) {
            self::assertSame('fchub/v1', $route['namespace']);
        }
    }

    public function testEveryRouteDeclaresAPermissionCallback(): void
    {
        Routes::register();

        foreach ($GLOBALS['_fchub_hub_test_rest_routes'] as $route) {
            self::assertArrayHasKey('permission_callback', $route['args'], $route['route'] . ' must be guarded.');
            self::assertIsCallable($route['args']['permission_callback']);
        }
    }

    public function testPermissionCallbacksUseOperationSpecificCapabilities(): void
    {
        Routes::register();

        $expected = [
            'GET /products' => ['manage_options'],
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/install' => ['install_plugins'],
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/install-and-activate' => ['install_plugins', 'activate_plugins'],
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/activate' => ['activate_plugins'],
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/update' => ['update_plugins'],
            'POST /products/(?P<slug>[a-z0-9-]{1,64})/deactivate' => ['activate_plugins'],
            'POST /catalogue/refresh' => ['manage_options'],
        ];

        foreach ($expected as $signature => $capabilities) {
            $permission = $this->permissionCallbackFor($signature);

            $GLOBALS['_fchub_hub_test_capabilities'] = $capabilities;
            self::assertTrue($permission(), $signature . ' must accept exactly its own capabilities.');

            // Drop each capability in turn: every one of them has to matter.
            foreach ($capabilities as $capability) {
                $GLOBALS['_fchub_hub_test_capabilities'] = array_values(
                    array_diff(self::ALL_CAPABILITIES, [$capability])
                );

                self::assertFalse($permission(), $signature . ' must require ' . $capability . '.');
            }
        }
    }

    public function testInstallCannotBeReachedThroughTheReadRoutesCapability(): void
    {
        Routes::register();

        // A pure manage_options account — plenty for reading, nothing more.
        $GLOBALS['_fchub_hub_test_capabilities'] = ['manage_options'];

        self::assertTrue(($this->permissionCallbackFor('GET /products'))());

        foreach (['install', 'install-and-activate', 'activate', 'update', 'deactivate'] as $action) {
            $signature = 'POST /products/(?P<slug>[a-z0-9-]{1,64})/' . $action;

            self::assertFalse(($this->permissionCallbackFor($signature))(), $action . ' must not ride on manage_options.');
        }
    }

    public function testTheSlugArgumentOnlyAcceptsASlug(): void
    {
        Routes::register();

        $args = $this->routeFor('POST /products/(?P<slug>[a-z0-9-]{1,64})/install')['args']['args']['slug'];
        $validate = $args['validate_callback'];

        self::assertTrue($validate('fchub-memberships'));
        self::assertFalse($validate('../../wp-config.php'));
        self::assertFalse($validate('FCHUB Memberships'));
        self::assertFalse($validate(''));
        self::assertFalse($validate(['fchub-p24']));
        self::assertFalse($validate(str_repeat('a', 65)), 'A slug is a slug, not a paragraph.');
    }

    public function testEachMutationRouteIsBoundToItsOwnAction(): void
    {
        // Five callbacks built in one loop. Capture the action wrongly and all
        // five would quietly run the last one, which is the sort of bug that
        // only shows up on somebody's live site.
        Routes::register();

        foreach (array_keys(ProductController::ACTIONS) as $action) {
            $callback = $this->routeFor('POST /products/(?P<slug>[a-z0-9-]{1,64})/' . $action)['args']['callback'];
            $bound = (new ReflectionFunction($callback))->getStaticVariables();

            self::assertSame($action, $bound['action'] ?? null);
        }
    }

    public function testBootRegistersTheRestRoutes(): void
    {
        Plugin::boot();

        $registrations = $GLOBALS['_fchub_hub_test_action_registrations']['rest_api_init'] ?? [];

        self::assertNotEmpty($registrations, 'Plugin::boot() must hook rest_api_init.');
        self::assertSame([Routes::class, 'register'], $registrations[0]['callback']);
    }

    public function testTheProductsRouteReturnsTheAgreedEnvelope(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.3.0']];
        $this->active = [self::MEMBERSHIPS, self::FLUENTCART];

        $response = $this->controller()->products(new WP_REST_Request('GET'));
        $payload = $this->payload($response);

        self::assertSame(200, $response->get_status());
        self::assertSame(['products', 'summary', 'catalogue', 'site', 'capabilities'], array_keys($payload));
        self::assertSame(['active' => 1, 'updates' => 1, 'compatibility_issues' => 0], $payload['summary']);
        self::assertSame(['source' => 'remote', 'last_refresh' => null], $payload['catalogue']);
        self::assertSame(['install' => true, 'activate' => true, 'update' => true], $payload['capabilities']);
    }

    public function testTheEnvelopeSaysWhatThisSiteActuallyRuns(): void
    {
        $this->installed = [
            self::MEMBERSHIPS => ['Version' => '1.4.0'],
            self::FLUENTCART => ['Version' => '1.2.3'],
        ];
        $this->active = [self::MEMBERSHIPS, self::FLUENTCART];

        $payload = $this->payload($this->controller()->products(new WP_REST_Request('GET')));

        // The same versions the compatibility decisions on this response were
        // made against — see service(), which builds the resolver with them.
        self::assertSame(
            ['wp' => '6.7', 'php' => '8.3.0', 'fluentcart' => '1.2.3'],
            $payload['site']
        );
    }

    public function testTheEnvelopeSaysSoWhenFluentCartIsNotRunningHere(): void
    {
        $this->installed = [self::FLUENTCART => ['Version' => '1.2.3']];
        $this->active = [];

        $payload = $this->payload($this->controller()->products(new WP_REST_Request('GET')));

        // Installed but switched off is absent: the products that need it will
        // not load either, so saying "1.2.3" here would be a comforting lie.
        self::assertNull($payload['site']['fluentcart']);
        self::assertSame('6.7', $payload['site']['wp']);
        self::assertSame('8.3.0', $payload['site']['php']);
    }

    public function testASuccessfulMutationAlsoRefreshesWhatTheSiteRuns(): void
    {
        $this->installed = [
            self::MEMBERSHIPS => ['Version' => '1.4.0'],
            self::FLUENTCART => ['Version' => '1.2.3'],
        ];

        $payload = $this->payload(
            $this->controller()->operate('activate', $this->request('fchub-memberships'))
        );

        self::assertSame('1.2.3', $payload['site']['fluentcart']);
    }

    public function testEachProductCarriesItsCatalogueCopyAndItsResolvedState(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.3.0']];
        $this->active = [self::MEMBERSHIPS, self::FLUENTCART];

        $payload = $this->payload($this->controller()->products(new WP_REST_Request('GET')));
        $memberships = $this->productNamed($payload['products'], 'fchub-memberships');

        self::assertSame('Memberships', $memberships['name']);
        self::assertSame('1.4.0', $memberships['version']);
        self::assertSame('1.3.0', $memberships['installed_version']);
        self::assertSame('active', $memberships['lifecycle']);
        self::assertSame('available', $memberships['update']);
        self::assertSame('compatible', $memberships['compatibility']);
        self::assertSame('https://fchub.co/docs/fchub-memberships', $memberships['docs_url']);
        self::assertContains('update', $memberships['actions']);
    }

    public function testTheInterfaceIsNeverHandedADownloadUrl(): void
    {
        $payload = $this->payload($this->controller()->products(new WP_REST_Request('GET')));

        foreach ($payload['products'] as $product) {
            self::assertArrayNotHasKey('package_url', $product);
            self::assertArrayNotHasKey('checksum_url', $product);
        }
    }

    public function testCapabilitiesReportWhatThisAccountCanActuallyDo(): void
    {
        $GLOBALS['_fchub_hub_test_capabilities'] = ['manage_options', 'update_plugins'];

        $payload = $this->payload($this->controller()->products(new WP_REST_Request('GET')));

        self::assertSame(['install' => false, 'activate' => false, 'update' => true], $payload['capabilities']);
    }

    public function testASuccessfulMutationReturnsTheSameEnvelopePlusANotice(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];

        $response = $this->controller()->operate('activate', $this->request('fchub-memberships'));
        $payload = $this->payload($response);

        self::assertSame(200, $response->get_status());
        self::assertSame(
            ['products', 'summary', 'catalogue', 'site', 'capabilities', 'notice'],
            array_keys($payload)
        );
        self::assertSame('Memberships is switched on.', $payload['notice']);
        self::assertSame(
            'active',
            $this->productNamed($payload['products'], 'fchub-memberships')['lifecycle'],
            'The response describes the site after the change, not before it.'
        );
    }

    public function testAFailureWhileRefreshingIsNotReportedAsAFailedOperation(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];

        // The operation goes through; the refreshed view of the site does not.
        $this->inventoryFailsAfter = 1;

        $response = $this->controller()->operate('activate', $this->request('fchub-memberships'));
        $payload = $this->payload($response);

        self::assertContains(self::MEMBERSHIPS, $this->active, 'The plugin really was switched on.');
        self::assertSame(['success', 'code', 'message', 'product'], array_keys($payload));

        // The two fields that answer "did my activation happen?". Both must say
        // yes, because it did.
        self::assertTrue($payload['success']);
        self::assertSame(200, $response->get_status());

        self::assertSame('refresh_failed_after_operation', $payload['code']);
        self::assertSame(
            'That worked, but FCHub could not read its catalogue afterwards. A page reload should sort it out.',
            $payload['message']
        );
        self::assertSame('fchub-memberships', $payload['product']);
    }

    public function testTheUrlDecidesWhichProductAnOperationActsOn(): void
    {
        // Core consults the request body before the URL, so a body parameter
        // could otherwise redirect an operation while the URL — and any audit
        // trail built from it — still named something else.
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];

        $request = $this->request('fchub-memberships');
        $request->set_body_param('slug', 'fchub-p24');

        self::assertSame('fchub-p24', $request->get_param('slug'), 'The body really does win get_param().');

        $payload = $this->payload($this->controller()->operate('activate', $request));

        self::assertSame('Memberships is switched on.', $payload['notice']);
        self::assertSame(
            [self::FLUENTCART, self::MEMBERSHIPS],
            $this->active,
            'The URL named Memberships, so Memberships is what was switched on.'
        );
    }

    public function testAFailureThatLeftFilesBehindAlsoSaysWhatTheSiteLooksLikeNow(): void
    {
        // The upgrader claims success and puts something else on disk. Files
        // landed, so telling the screen "nothing happened" would leave it
        // insisting a product sitting in wp-content/plugins/ is not installed,
        // and the next click would get a 409 saying the opposite.
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.3.0']];
        $this->active = [self::MEMBERSHIPS, self::FLUENTCART];
        $this->upgraderInstalls = '1.3.9';

        $response = $this->controller()->operate('update', $this->request('fchub-memberships'));
        $payload = $this->payload($response);

        self::assertSame(500, $response->get_status(), 'The status code is unchanged: this is still a failure.');
        self::assertSame(['success', 'code', 'message', 'product', 'state'], array_keys($payload));
        self::assertFalse($payload['success'], 'The update did not finish, and success says only that.');
        self::assertSame('version_mismatch', $payload['code']);

        // The fifth key is a whole envelope, minus the notice a success carries.
        self::assertSame(
            ['products', 'summary', 'catalogue', 'site', 'capabilities'],
            array_keys($payload['state'])
        );
        self::assertSame(
            '1.3.9',
            $this->productNamed($payload['state']['products'], 'fchub-memberships')['installed_version'],
            'The state describes the site as it is now, not as it was before the attempt.'
        );
    }

    public function testAnActivationThatFailedStillReportsTheSiteItLeftBehind(): void
    {
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];
        $this->activationResult = new WP_Error('unexpected_output', 'The plugin generated unexpected output.');

        $response = $this->controller()->operate('activate', $this->request('fchub-memberships'));
        $payload = $this->payload($response);

        self::assertSame(500, $response->get_status());
        self::assertSame('activation_failed', $payload['code']);
        self::assertArrayHasKey('state', $payload);
        self::assertSame(
            'inactive',
            $this->productNamed($payload['state']['products'], 'fchub-memberships')['lifecycle']
        );
    }

    public function testAFailureThatChangedNothingKeepsTheFourKeyShape(): void
    {
        // Refusals decided before a byte moved have nothing new to say about
        // the site, and a state key on every error would train the screen to
        // reload after refusals that changed nothing.
        $this->installed = [self::MEMBERSHIPS => ['Version' => '1.4.0']];

        foreach (['install', 'update', 'deactivate'] as $action) {
            $payload = $this->payload(
                $this->controller()->operate($action, $this->request('fchub-memberships'))
            );

            self::assertArrayNotHasKey('state', $payload, $action . ' refused nothing into existence.');
        }
    }

    public function testPublicErrorsCarryOnlySuccessCodeMessageAndProduct(): void
    {
        $response = $this->controller()->operate('activate', $this->request('fchub-memberships'));
        $payload = $this->payload($response);

        self::assertSame(['success', 'code', 'message', 'product'], array_keys($payload));
        self::assertFalse($payload['success']);
        self::assertSame('product_not_installed', $payload['code']);
        self::assertSame('Memberships is not installed yet.', $payload['message']);
        self::assertSame('fchub-memberships', $payload['product']);
        self::assertSame(409, $response->get_status());
    }

    public function testAnUnknownProductIsAFriendlyNotFound(): void
    {
        $response = $this->controller()->operate('install', $this->request('someone-elses-plugin'));
        $payload = $this->payload($response);

        self::assertSame(404, $response->get_status());
        self::assertSame('product_unknown', $payload['code']);
        self::assertSame('someone-elses-plugin', $payload['product']);
    }

    public function testASlugThatIsNotASlugNeverReachesTheEnvelope(): void
    {
        $response = $this->controller()->operate('install', $this->request('../../wp-config.php'));
        $payload = $this->payload($response);

        self::assertSame('product_unknown', $payload['code']);
        self::assertNull($payload['product'], 'Nothing unrecognisable is echoed back to the client.');
    }

    public function testAnActionFchubDoesNotKnowIsRefused(): void
    {
        $response = $this->controller()->operate('delete', $this->request('fchub-memberships'));

        self::assertSame('operation_unknown', $this->payload($response)['code']);
    }

    public function testADamagedCatalogueBecomesAFriendlyErrorRatherThanACrash(): void
    {
        $response = $this->damagedController()->products(new WP_REST_Request('GET'));
        $payload = $this->payload($response);

        self::assertSame(503, $response->get_status());
        self::assertSame(['success', 'code', 'message', 'product'], array_keys($payload));
        self::assertSame('catalogue_unavailable', $payload['code']);
        self::assertStringNotContainsString('catalog.json', $payload['message']);
        self::assertStringNotContainsString(FCHUB_HUB_PATH, $payload['message']);
    }

    public function testInternalContextIsLoggedAndNeverReturned(): void
    {
        $response = $this->damagedController()->products(new WP_REST_Request('GET'));
        $body = (string) json_encode($this->payload($response));

        self::assertNotEmpty($this->logged);
        self::assertStringContainsString('catalogue_unavailable', $this->logged[0]);
        self::assertStringContainsString('catalogue_bundled_unreadable', $this->logged[0]);

        self::assertStringNotContainsString('catalogue_bundled_unreadable', $body, 'Internal detail stays on the server.');
        self::assertStringNotContainsString('resources/', $body);
    }

    public function testTheDefaultLoggerStaysQuietWhileDebuggingIsOff(): void
    {
        // WP_DEBUG is undefined for the suite, which is the production default
        // on any site that has not asked for noise.
        self::assertSame('', $this->captureDefaultLog('installation_failed: something detailed'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheDefaultLoggerSpeaksUpWhenDebuggingIsOn(): void
    {
        // A separate process because a constant cannot be undefined again, and
        // WP_DEBUG leaking into the rest of the suite would quietly turn every
        // other test into a logging test.
        define('WP_DEBUG', true);

        // The sample is a real line, verbatim, so this test also documents what
        // an operator will actually find in the log.
        $sample = 'checksum_unavailable: no checksum for fchub-p24 1.0.3, installing anyway';

        self::assertStringContainsString('FCHub: ' . $sample, $this->captureDefaultLog($sample));
    }

    public function testTheRefreshRouteAsksTheCatalogueForARealRetry(): void
    {
        $payload = $this->payload($this->controller()->refresh(new WP_REST_Request('POST')));

        self::assertSame(1, $this->catalogueFetches, 'Refresh means refresh, not "look at the cache again".');
        self::assertSame('Catalogue refreshed.', $payload['notice']);
        self::assertSame('remote', $payload['catalogue']['source']);
    }

    public function testAReadInsideTheFreshnessWindowNeverDialsTheEndpoint(): void
    {
        $payload = $this->payload($this->controller()->products(new WP_REST_Request('GET')));

        self::assertSame(0, $this->catalogueFetches);
        self::assertSame('remote', $payload['catalogue']['source']);
    }

    /**
     * @return list<string>
     */
    private function registeredRoutes(): array
    {
        return array_map(
            static fn (array $route): string => $route['args']['methods'] . ' ' . $route['route'],
            $GLOBALS['_fchub_hub_test_rest_routes']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function routeFor(string $signature): array
    {
        foreach ($GLOBALS['_fchub_hub_test_rest_routes'] as $route) {
            if ($route['args']['methods'] . ' ' . $route['route'] === $signature) {
                return $route;
            }
        }

        self::fail('No route registered for ' . $signature);
    }

    private function permissionCallbackFor(string $signature): Closure
    {
        return $this->routeFor($signature)['args']['permission_callback'];
    }

    private function request(string $slug): WP_REST_Request
    {
        $request = new WP_REST_Request('POST');
        $request->set_param('slug', $slug);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $response): array
    {
        $data = $response->get_data();

        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>
     */
    private function productNamed(array $products, string $slug): array
    {
        foreach ($products as $product) {
            if ($product['slug'] === $slug) {
                return $product;
            }
        }

        self::fail('No product entry for ' . $slug);
    }

    private function controller(): ProductController
    {
        return new ProductController($this->service($this->repository()), $this->logger());
    }

    private function damagedController(): ProductController
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

        return new ProductController($this->service($repository), $this->logger());
    }

    /**
     * A healthy site inside its freshness window: a stored copy the repository
     * will serve without asking anyone, and an endpoint that answers only when
     * somebody genuinely insists.
     */
    private function repository(): CatalogueRepository
    {
        $catalogue = CatalogueFixtures::raw();

        return new CatalogueRepository(
            fetch: function () use ($catalogue): array {
                $this->catalogueFetches++;

                return ['code' => 200, 'body' => (string) json_encode($catalogue), 'etag' => ''];
            },
            readOption: static fn (string $name, mixed $default = null): mixed => $name === CatalogueRepository::OPTION_LAST_GOOD
                ? $catalogue
                : $default,
            writeOption: static function (): void {
            },
            deleteOption: static function (): void {
            },
            readTransient: static fn (): mixed => ['state' => 'fresh', 'at' => '2026-07-24T17:00:00+00:00'],
            writeTransient: static function (): void {
            },
            clock: static fn (): int => 1785000000
        );
    }

    private function service(CatalogueRepository $repository): ProductOperationService
    {
        return new ProductOperationService(
            repository: $repository,
            resolver: new ProductStateResolver('8.3.0', '6.7'),
            downloader: new VerifiedPackageDownloader(function (string $url): string {
                if ($this->upgraderInstalls === null) {
                    self::fail('No REST test may download anything.');
                }

                $path = (string) tempnam(sys_get_temp_dir(), 'fchub-rest-');
                $this->temporary[] = $path;

                file_put_contents(
                    $path,
                    str_ends_with($url, '.sha256') ? hash('sha256', 'an archive') : 'an archive'
                );

                return $path;
            }),
            installedPlugins: function (): array {
                $this->inventoryReads++;

                if ($this->inventoryFailsAfter !== null && $this->inventoryReads > $this->inventoryFailsAfter) {
                    throw new UnexpectedValueException('inventory_unreadable: the site went sideways');
                }

                return $this->installed;
            },
            activePlugins: fn (): array => $this->active,
            installer: function (): bool {
                if ($this->upgraderInstalls === null) {
                    self::fail('No REST test may run the upgrader.');
                }

                $this->installed[self::MEMBERSHIPS] = ['Version' => $this->upgraderInstalls];

                return true;
            },
            activator: function (string $pluginFile): mixed {
                if ($this->activationResult !== null) {
                    return $this->activationResult;
                }

                $this->active[] = $pluginFile;

                return null;
            },
            deactivator: function (string $pluginFile): void {
                $this->active = array_values(array_diff($this->active, [$pluginFile]));
            },
            refreshInventory: static function (): void {
            },
            logger: $this->logger()
        );
    }

    private function logger(): Closure
    {
        return function (string $message): void {
            $this->logged[] = $message;
        };
    }

    /**
     * Runs the production diagnostics sink with error_log() pointed at a file
     * of our own, and hands back whatever it wrote.
     */
    private function captureDefaultLog(string $message): string
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'fchub-log-');
        $this->temporary[] = $log;
        $previous = ini_set('error_log', $log);

        try {
            (ProductOperationService::debugLogger())($message);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        return (string) file_get_contents($log);
    }
}
