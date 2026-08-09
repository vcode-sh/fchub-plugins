<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Subscription\Package\PackageContextRepository;
use CartShift\Domain\Subscription\Package\SubscriptionPackageWriter;
use CartShift\Domain\Subscription\Source\WooSubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Http\Controllers\SubscriptionPackageController;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

require_once dirname(__DIR__, 3) . '/stubs/PreflightStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/ZeroWriteGuard.php';

/**
 * `prepare-package` over HTTP, and the labelling that keeps it honest.
 *
 * Section 11 Phase A: "Saving mapping decisions and accepting a deliberate
 * manual fallback are separate, explicit CartShift configuration writes and
 * must never be described as audit." Preparing a package is the third of those.
 * It writes four strings — source key, absolute private path, records checksum,
 * selection fingerprint — and the response says so in the same breath, because
 * an operator who has just read a screen headed "writes nothing" needs the
 * difference stated rather than implied.
 *
 * The listing endpoint, by contrast, is a read, and the guard proves it.
 */
final class SubscriptionPackageControllerTest extends PluginTestCase
{
    private const string SOURCE_KEY = 'lapka-club';

    /** @var array<string, callable> */
    private array $shapes;

    private string $workspace;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->shapes = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';

        $GLOBALS['_cartshift_test_hpos_enabled'] = false;
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [];
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => '';

        $this->workspace = realpath(sys_get_temp_dir()) . '/cartshift-package-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);

        $this->seedSource([$this->shapes['typedRelatedOrders']()]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    public function testPreparingAPackageIsLabelledAConfigurationWriteAndStoresFourStrings(): void
    {
        $path = $this->exportPackage();

        $response = $this->call('prepare', ['file' => $path]);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data()['data'];

        $this->assertTrue($data['prepared']);
        $this->assertSame('cartshift_configuration', $data['write']['kind']);
        $this->assertStringNotContainsStringIgnoringCase('audit', $data['write']['kind']);

        $descriptor = (new PackageContextRepository())->get(self::SOURCE_KEY);

        $this->assertNotNull($descriptor);
        $this->assertSame(
            ['checksum', 'path', 'selection_fingerprint', 'source_key'],
            array_keys($descriptor),
            'The descriptor is four strings. It is not a copy of the package.',
        );
    }

    public function testPreparingRefusesAPackageThatDoesNotValidate(): void
    {
        $path = $this->exportPackage();
        file_put_contents($path, file_get_contents($path) . "{\"kind\":\"customer\"}\n");

        $response = $this->call('prepare', ['file' => $path]);

        $this->assertSame(422, $response->get_status());
        $this->assertNull((new PackageContextRepository())->get(self::SOURCE_KEY));
    }

    public function testPreparingRefusesWithoutAFile(): void
    {
        $this->assertSame(400, $this->call('prepare', [])->get_status());
    }

    public function testListingPreparedPackagesWritesNothing(): void
    {
        $this->call('prepare', ['file' => $this->exportPackage()]);

        $watched = \CartShiftZeroWriteGuard::watch(fn (): array => $this->call('index', [])->get_data()['data']);

        $this->assertSame([], $watched['violations']);
        $this->assertArrayHasKey(self::SOURCE_KEY, $watched['result']['packages']);
    }

    public function testForgettingRequiresConfirmationAndLeavesTheFileAlone(): void
    {
        $path = $this->exportPackage();
        $this->call('prepare', ['file' => $path]);

        $this->assertSame(400, $this->call('forget', ['source_key' => self::SOURCE_KEY])->get_status());
        $this->assertNotNull((new PackageContextRepository())->get(self::SOURCE_KEY));

        $response = $this->call('forget', ['source_key' => self::SOURCE_KEY, 'confirm' => true]);

        $this->assertSame(200, $response->get_status());
        $this->assertNull((new PackageContextRepository())->get(self::SOURCE_KEY));
        $this->assertFileExists($path, 'Forgetting a descriptor must not delete the package.');
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     */
    private function call(string $method, array $params): \WP_REST_Response
    {
        $request = new WP_REST_Request();

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return (new SubscriptionPackageController(new Container()))->{$method}($request);
    }

    private function exportPackage(string $name = 'package.ndjson'): string
    {
        $path      = $this->workspace . '/' . $name;
        $selection = SubscriptionSelection::all(self::SOURCE_KEY);

        (new SubscriptionPackageWriter())->write(
            $path,
            new WooSubscriptionDatasetSource(self::SOURCE_KEY, $selection),
            $selection,
        );

        $this->assertFileExists($path);

        return $path;
    }

    /**
     * @param list<object> $subscriptions
     */
    private function seedSource(array $subscriptions): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = $subscriptions;
        $GLOBALS['_cartshift_test_wc_orders'] = [];
        $GLOBALS['_cartshift_test_wc_products'] = [];

        foreach ($subscriptions as $subscription) {
            foreach (SubscriptionOrderReference::RELATIONSHIPS as $relationship) {
                foreach ($subscription->get_related_orders('all', $relationship) as $order) {
                    $GLOBALS['_cartshift_test_wc_orders'][$order->get_id()] = $order;
                }
            }

            foreach ($subscription->get_items() as $item) {
                $productId = $item->get_product_id();

                if ($productId > 0) {
                    $GLOBALS['_cartshift_test_wc_products'][$productId] =
                        new \CartShiftLapkaProduct($productId, $item->get_name());
                }
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
