<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Subscription\SubscriptionWriter;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

/**
 * Nothing reaches `fct_subscriptions` without a passing assessment — including
 * through the GENERIC `wp cartshift migrate` entry point.
 *
 * The staged cutover has its own monotonic receipt, and that receipt is the
 * enforcement point for the subscription path. It is not the only path.
 * `SubscriptionMigrator` is reachable from `MigrationOrchestratorFactory`, which
 * the ordinary migrate/finalize commands and the REST controller all build, and
 * none of those has ever depended on an audit having been run. So the question
 * this file answers is narrow and load-bearing: can that older door create a
 * destination subscription that was never assessed `ready`?
 *
 * It cannot, for two independent reasons, and both are asserted here because
 * either alone would be a single point of failure.
 */
final class SubscriptionAssessmentGateTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    private ?object $originalWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb']    = new \CartShiftTestWpdb();

        \CartShiftFcModelStore::install();

        $GLOBALS['_cartshift_test_id_map']           = [];
        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();

        $this->shapes = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
    }

    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }

        parent::tearDown();
    }

    /**
     * Reason one: the migrator asks, and refuses on anything but `ready`.
     *
     * The fixture is a live Stripe subscription with every reference resolved —
     * the exact record the generic path used to migrate happily. Section 8.4
     * holds it at `confirmation_required` until the operator accepts that
     * FluentCart will invoice the customer instead of charging them silently,
     * and the acceptance is deliberately NOT given here.
     */
    public function testTheGenericMigratePathWritesNothingWithoutAPassingAssessment(): void
    {
        $subscription = $this->shapes['monthlyPln29']();

        $this->mapEverythingFor($subscription);

        $this->assertFalse($this->migrator()->processRecord($subscription));
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
    }

    /**
     * Reason two: the writer refuses as well, loudly, whatever asked it.
     *
     * A caller reaching this method with a blocked assessment is a programming
     * error rather than a data condition, which is why it throws rather than
     * returning a verdict a caller could ignore.
     */
    public function testTheWriterItselfRefusesAnAssessmentThatIsNotReady(): void
    {
        $record = $this->record();

        $this->expectException(\LogicException::class);

        (new SubscriptionWriter(new IdMapRepository()))->stage(
            $record,
            new SubscriptionAssessment(
                SubscriptionAssessment::OUTCOME_CONFIRMATION_REQUIRED,
                [],
                [],
                [],
                new PaymentMigrationDecision(
                    PaymentMigrationDecision::STRATEGY_MANUAL,
                    PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED,
                    PaymentMigrationDecision::COLLECTION_MANUAL,
                    '',
                    PaymentMigrationDecision::OWNER_TARGET_MANUAL,
                    null,
                    null,
                    null,
                    [],
                    [SubscriptionAssessment::REASON_MANUAL_NOT_ACCEPTED],
                ),
            ),
        );
    }

    /**
     * And a structural guard, so a third route cannot quietly appear.
     *
     * `SubscriptionWriter` is the only place in `app/` that constructs a
     * FluentCart subscription. That is what makes the two reasons above
     * exhaustive rather than merely true of the two callers somebody thought to
     * check.
     */
    public function testOnlyTheWriterEverConstructsAFluentCartSubscription(): void
    {
        $constructors = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/app'),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // Comments are stripped first: three docblocks in this codebase
            // discuss `Subscription::create()` precisely because it is the
            // mistake, and a grep that counted prose would fail on the
            // explanation of the fix.
            $code = '';

            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            // The fully-qualified form is matched too. `new
            // \FluentCart\App\Models\Subscription(` needs no `use` statement, so
            // a file could construct one without this guard ever noticing —
            // which would make the whole assertion decorative.
            $pattern = '/\bnew\s+(\\\\?FluentCart\\\\App\\\\Models\\\\)?Subscription\s*\('
                . '|(\\\\?FluentCart\\\\App\\\\Models\\\\)?Subscription::(query\(\)->)?create\s*\(/';

            if (preg_match($pattern, $code) === 1) {
                $constructors[] = basename($file->getPathname());
            }
        }

        $this->assertSame(['SubscriptionWriter.php'], array_values(array_unique($constructors)));
    }

    // ──────────────────────────────────────────────
    // Harness
    // ──────────────────────────────────────────────

    private function migrator(): SubscriptionMigrator
    {
        return new SubscriptionMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    private function record(): SubscriptionRecord
    {
        $record = (new SubscriptionRecordFactory())->subscriptionFromWoo(
            Constants::DEFAULT_SOURCE_KEY,
            $this->shapes['monthlyPln29'](),
        );

        $this->assertInstanceOf(SubscriptionRecord::class, $record);

        return $record;
    }

    private function mapEverythingFor(object $subscription): void
    {
        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_CUSTOMER][(string) $subscription->get_customer_id()] = 501;
        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_ORDER][(string) $subscription->get_parent_id()]      = 601;
        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_PRODUCT][(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID] = 701;
        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_VARIATION][(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID] = 801;

        cartshift_test_own_variation(801, 701);
    }
}
