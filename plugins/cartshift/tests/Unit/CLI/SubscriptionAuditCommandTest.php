<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\CLI;

use CartShift\CLI\SubscriptionCommand;
use CartShift\Domain\Subscription\Package\PackageContextRepository;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/PreflightStubs.php';
require_once dirname(__DIR__, 2) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 2) . '/stubs/ZeroWriteGuard.php';
require_once dirname(__DIR__, 2) . '/stubs/FluentCartModelStubs.php';

/**
 * The read-only half of `wp cartshift subscriptions`.
 *
 * The plan's first P0 is that CartShift's existing dry run writes simulated
 * ID-map rows while calling itself a rehearsal. `audit` is the answer, and an
 * answer of that shape is only worth anything if it is enforced rather than
 * asserted: every audit here runs under a `$wpdb` that refuses to write at all,
 * and `testTheZeroWriteGuardWouldCatchARealWrite` makes a write on purpose so
 * the guard cannot pass merely because nothing tried.
 *
 * `prepare-package` is the one command in this file that writes, and what it
 * writes is four strings.
 */
final class SubscriptionAuditCommandTest extends PluginTestCase
{
    private const string SOURCE_KEY = 'lapka-club';

    /** @var array<string, callable> */
    private array $shapes;

    private string $workspace;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->shapes = require dirname(__DIR__, 2) . '/fixtures/lapka-subscription-shapes.php';

        $GLOBALS['_cartshift_test_hpos_enabled'] = false;
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [];
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => '';

        $this->workspace = realpath(sys_get_temp_dir()) . '/cartshift-cli-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);

        $this->seedSource([
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['malformedNoItemNoParent'](),
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // The guard itself
    // ──────────────────────────────────────────────

    public function testTheZeroWriteGuardWouldCatchARealWrite(): void
    {
        $watched = \CartShiftZeroWriteGuard::watch(static function (): void {
            global $wpdb;

            $wpdb->insert('wp_cartshift_id_map', ['entity_type' => 'subscription']);
        });

        $this->assertNotSame(
            [],
            $watched['violations'],
            'A guard that cannot catch an INSERT proves nothing about the commands it watches.',
        );
    }

    public function testTheZeroWriteGuardAlsoCatchesAnOptionWrite(): void
    {
        $watched = \CartShiftZeroWriteGuard::watch(static function (): void {
            update_option('cartshift_something', 'written');
        });

        $this->assertContains('option state changed', $watched['violations']);
    }

    /**
     * The largest write surface in the plugin, and the one the guard could not
     * see.
     *
     * The FluentCart ORM stub records into `_cartshift_test_fc_models` and
     * `_cartshift_test_fc_saved` and never touches `$wpdb`, so every
     * `Subscription::save()` in the suite was invisible to the guard whose
     * entire job is to prove nothing writes. Nothing on the audited path writes
     * through it today — the claim was sound and the proof was narrower than it
     * advertised.
     */
    public function testTheZeroWriteGuardAlsoCatchesAFluentCartModelWrite(): void
    {
        $watched = \CartShiftZeroWriteGuard::watch(static function (): void {
            \CartShiftFcModelStore::record('Subscription', ['status' => 'active']);
        });

        $this->assertContains('FluentCart model state changed', $watched['violations']);
    }

    public function testTheZeroWriteGuardAlsoCatchesAPostWrite(): void
    {
        $watched = \CartShiftZeroWriteGuard::watch(static function (): void {
            $GLOBALS['_cartshift_test_posts'][987_654] = (object) ['ID' => 987_654];
        });

        $this->assertContains('post state changed', $watched['violations']);
    }

    public function testTheZeroWriteGuardPassesWhenNothingWrites(): void
    {
        $watched = \CartShiftZeroWriteGuard::watch(static fn (): int => 1 + 1);

        $this->assertSame([], $watched['violations']);
        $this->assertSame(2, $watched['result']);
    }

    // ──────────────────────────────────────────────
    // audit
    // ──────────────────────────────────────────────

    public function testAuditingTheLiveSourceWritesNothingAtAll(): void
    {
        $GLOBALS['_cartshift_test_wc_order_lookups'] = 0;

        $watched = \CartShiftZeroWriteGuard::watch(function (): void {
            SubscriptionCommand::audit([], [
                'source'     => 'live',
                'source-key' => self::SOURCE_KEY,
                'format'     => 'json',
            ]);
        });

        $this->assertSame([], $watched['violations']);

        // Not a vacuous pass: the audit really did read the source under the
        // guard. A command that bailed out before touching anything would
        // satisfy the assertion above and prove nothing.
        $this->assertGreaterThan(0, $GLOBALS['_cartshift_test_wc_order_lookups']);
    }

    public function testLiveAuditAppliesAndReportsTheSameNarrowedSelectionAsExport(): void
    {
        $this->seedSource([
            $this->shapes['monthlyPln29'](['id' => 910_101]),
            $this->shapes['yearlyPln290'](['id' => 910_102]),
            $this->shapes['cancelled'](['id' => 910_103]),
        ]);
        $GLOBALS['_cartshift_test_wp_cli'] = [];

        SubscriptionCommand::audit([], [
            'source'                   => 'live',
            'source-key'               => self::SOURCE_KEY,
            'exclude-subscription-ids' => '910102',
            'statuses'                 => 'active,cancelled',
            'format'                   => 'json',
        ]);

        $line = array_values(array_filter(
            $GLOBALS['_cartshift_test_wp_cli'],
            static fn (array $message): bool => $message['level'] === 'line',
        ));
        $document = json_decode((string) ($line[0]['message'] ?? ''), true);

        $this->assertSame(2, $document['manifest']['counts']['subscription']);
        $this->assertSame([910_102], $document['selection']['excluded_subscription_ids']);
        $this->assertSame(['active', 'cancelled'], $document['selection']['statuses']);
    }

    public function testPackageAuditRefusesSelectionFlagsInsteadOfPretendingToReapplyThem(): void
    {
        $path = $this->exportPackage();
        $GLOBALS['_cartshift_test_wp_cli'] = [];

        SubscriptionCommand::audit([], [
            'file'                     => $path,
            'exclude-subscription-ids' => '910030',
            'format'                   => 'json',
        ]);

        $this->assertStringContainsString(
            'already carries its frozen selection',
            implode(' ', array_column($GLOBALS['_cartshift_test_wp_cli'], 'message')),
        );
    }

    public function testAuditingAPackageWritesNothingAtAll(): void
    {
        $path = $this->exportPackage();

        $watched = \CartShiftZeroWriteGuard::watch(static function () use ($path): void {
            SubscriptionCommand::audit([], ['file' => $path, 'format' => 'json']);
        });

        $this->assertSame([], $watched['violations']);
    }

    public function testAuditDoesNotPrepareOrPersistAnything(): void
    {
        $path = $this->exportPackage();

        SubscriptionCommand::audit([], ['file' => $path, 'format' => 'json']);

        $this->assertSame([], (new PackageContextRepository())->all());
    }

    public function testTheAuditSummaryIsByteIdenticalAcrossRuns(): void
    {
        $first = $this->auditDocument();
        $second = $this->auditDocument();

        $this->assertSame(
            json_encode($first),
            json_encode($second),
            'Two audits of one unchanged source must produce the same document.',
        );
    }

    /**
     * The malformed record blocks its own entry and NOT the dataset.
     *
     * §6.2 forces an invalid record to block the affected ENTITY, not the
     * package. The outcome used to come off `ClosureReport::isComplete()`, which
     * is `failures === []` — so the reference cohort read `blocked` in the audit
     * and then staged 563 of 564 without complaint, and the operator learned by
     * direct experience that this screen's red is advisory. The audit and
     * `SubscriptionCutover::stage()` now ask the same question.
     */
    public function testTheAuditSummaryReportsTheMalformedRecordWithoutBlockingTheDataset(): void
    {
        $document = $this->auditDocument();

        $this->assertSame('ready', $document['outcome']);
        $this->assertFalse($document['closure']['set_level']);
        $this->assertSame([], $document['closure']['set_level_codes']);

        // Still reported, and still false — `complete` survives as information.
        $this->assertFalse($document['closure']['complete']);
        $this->assertContains('invalid_source_record', $document['closure']['reason_codes']);
        $this->assertSame(2, $document['manifest']['counts']['subscription']);
    }

    /**
     * And a set-level fault still blocks, which is what makes the distinction
     * worth having rather than a way of turning the gate off.
     *
     * Two subscriptions on one parent order: neither record is individually
     * wrong, so no per-record gate sees it, and `SubscriptionCutover::stage()`
     * refuses on exactly this. The audit has to agree.
     */
    public function testASetLevelFaultStillBlocksTheAudit(): void
    {
        $this->seedSource([
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['malformedNoItemNoParent'](),
            // Same parent order as `typedRelatedOrders`.
            $this->shapes['typedRelatedOrders'](['id' => 910_040]),
        ]);

        $document = $this->auditDocument();

        $this->assertSame('blocked', $document['outcome']);
        $this->assertTrue($document['closure']['set_level']);
        $this->assertContains(
            'shared_parent_order_requires_projection',
            $document['closure']['set_level_codes'],
        );

        // The per-record finding travels with it and is NOT what blocked.
        $this->assertContains('invalid_source_record', $document['closure']['reason_codes']);
        $this->assertNotContains('invalid_source_record', $document['closure']['set_level_codes']);
    }

    /**
     * The mirror findings have to reach the person reading the audit.
     *
     * `unverified_fields` is the whole value of the comparison for
     * `payment_retry`: WooCommerce Subscriptions is not installed here, so the
     * meta key that field is read through is convention rather than verified
     * contract, and a wrong literal now surfaces as "nobody answered this"
     * instead of as silent agreement. That is only true if it survives as far
     * as the output, so this asserts it does — both in the document and in the
     * rendered JSON an operator actually sees.
     */
    public function testTheMirrorFindingsReachTheAuditOutput(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => 'wp_wc_orders_meta';
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [
            (object) [
                'object_id'  => 910_030,
                'meta_key'   => '_schedule_next_payment',
                'meta_value' => '2100-05-11 09:15:00',
            ],
        ];

        $document = $this->auditDocument();

        $this->assertTrue($document['storage_mirror']['verified_here']);
        $this->assertSame('live_comparison', $document['storage_mirror']['reported_by']);

        $mirror = $document['storage_mirror']['summary'];

        $this->assertSame('posts', $mirror['authority']);
        $this->assertSame(['payment_retry'], $mirror['unverified_fields']);
        // One mirrored row against two subscriptions that both carry a next
        // payment: the mirror is missing one, which is a discrepancy. Neither
        // side has ever produced a retry value, which is not.
        $this->assertSame(['next_payment' => 1, 'payment_retry' => 0], $mirror['mirror_values_found']);
        $this->assertSame(['next_payment' => 2, 'payment_retry' => 0], $mirror['authority_values_found']);
        $this->assertNotSame([], $mirror['discrepancies']);

        $json = (new \ReflectionMethod(SubscriptionCommand::class, 'renderDocument'))
            ->invoke(null, $document);

        $decoded = json_decode((string) $json, true);

        $this->assertSame(
            ['payment_retry'],
            $decoded['storage_mirror']['summary']['unverified_fields'],
            '--format=json must carry the unverified fields, or the finding never leaves the process.',
        );
    }

    /**
     * The target sees the source's finding, attributed and without the rows.
     *
     * Cross-runtime is the only route Lapka uses, and the operator decides on
     * the target — so a `null` here would hide section 4.9's discrepancies at
     * exactly the moment the decision is made.
     */
    public function testAPackageAuditShowsTheSourcesMirrorFindingAsTheSourcesFinding(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => 'wp_wc_orders_meta';
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [
            (object) [
                'object_id'  => 910_030,
                'meta_key'   => '_schedule_next_payment',
                'meta_value' => '2100-05-11 09:15:00',
            ],
        ];

        $path = $this->exportPackage('with-mirror.ndjson');

        $document = (new \ReflectionMethod(SubscriptionCommand::class, 'auditDocument'))
            ->invoke(null, 'package', self::SOURCE_KEY, $path);

        $mirror = $document['storage_mirror'];

        $this->assertFalse(
            $mirror['verified_here'],
            'The target has no WooCommerce to compare backends against and must not imply it does.',
        );
        $this->assertSame('source_export', $mirror['reported_by']);
        $this->assertSame('posts', $mirror['summary']['authority']);
        $this->assertSame(['payment_retry'], $mirror['summary']['unverified_fields']);
        // Two subscriptions carry a next payment and only one is mirrored, so
        // both disagree with the mirror: one on the value, one on its absence.
        $this->assertSame(['next_payment' => 2, 'payment_retry' => 0], $mirror['summary']['discrepancy_counts']);

        // Counts travel; rows do not. The per-subscriber discrepancies name
        // source references and dates, and section 6.5 keeps the package free
        // of anything it does not need.
        $this->assertArrayNotHasKey('discrepancies', $mirror['summary']);
    }

    /**
     * The mirror summary appears once, with its provenance attached.
     *
     * `DatasetManifest::toArray()` emits `storage_mirror`, so the document's
     * manifest section would otherwise carry a second, bare copy of the same
     * fact right beside the attributed one — and a consumer reaching for the
     * shorter path would get the copy that does not say who computed it or
     * when. That is exactly the mistake the envelope was built to prevent, so
     * shipping both would have quietly undone it.
     */
    public function testTheMirrorSummaryIsPresentedOnlyThroughTheAttributedEnvelope(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => 'wp_wc_orders_meta';
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [
            (object) [
                'object_id'  => 910_030,
                'meta_key'   => '_schedule_next_payment',
                'meta_value' => '2100-05-11 09:15:00',
            ],
        ];

        $live = $this->auditDocument();

        $package = (new \ReflectionMethod(SubscriptionCommand::class, 'auditDocument'))
            ->invoke(null, 'package', self::SOURCE_KEY, $this->exportPackage('attributed.ndjson'));

        foreach (['live' => $live, 'package' => $package] as $mode => $document) {
            $this->assertArrayNotHasKey(
                'storage_mirror',
                $document['manifest'],
                sprintf('The %s manifest section must not carry an unattributed second copy.', $mode),
            );

            // Still reachable — stripped from one place, not lost.
            $this->assertArrayHasKey('unverified_fields', $document['storage_mirror']['summary']);
            $this->assertArrayHasKey('verified_here', $document['storage_mirror']);
        }

        // And the rendered JSON an operator reads carries exactly one of them.
        $json = (string) (new \ReflectionMethod(SubscriptionCommand::class, 'renderDocument'))
            ->invoke(null, $package);

        $this->assertSame(
            1,
            substr_count($json, '"unverified_fields"'),
            'One fact, one presentation.',
        );
    }

    public function testValidatePackageStillReportsTheHeaderItValidated(): void
    {
        $result = (new \CartShift\Domain\Subscription\Package\SubscriptionPackageReader())
            ->validate($this->exportPackage('structural.ndjson'));

        $document = (new \ReflectionMethod(SubscriptionCommand::class, 'packageDocument'))
            ->invoke(null, $result);

        // validate-package answers a structural question — intact or not — and
        // has no attribution envelope to hang a readiness signal on. The mirror
        // finding belongs to `audit`, which is the command that decides
        // readiness, so it is absent here rather than present unattributed.
        $this->assertArrayNotHasKey('storage_mirror', $document['manifest']);
        $this->assertTrue($document['ok']);
        $this->assertNotSame('', $document['checksum']);
    }

    public function testAPackageWrittenBeforeTheHeaderFieldExistedReadsAsNobodyLooked(): void
    {
        $path = $this->exportPackage('legacy.ndjson');

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $header = (array) json_decode((string) $lines[0], true);
        unset($header['storage_mirror']);
        $lines[0] = \CartShift\Domain\Subscription\SubscriptionRecordFactory::canonicalJson($header);
        file_put_contents($path, implode("\n", $lines) . "\n");

        $document = (new \ReflectionMethod(SubscriptionCommand::class, 'auditDocument'))
            ->invoke(null, 'package', self::SOURCE_KEY, $path);

        $this->assertSame(
            'unavailable',
            $document['storage_mirror']['reported_by'],
            'An absent summary is "nobody looked", which is not the same as "no discrepancies".',
        );
        $this->assertSame([], $document['storage_mirror']['summary']);
    }

    public function testAuditRefusesAnUnknownSource(): void
    {
        SubscriptionCommand::audit([], ['source' => 'telepathy']);

        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    // ──────────────────────────────────────────────
    // export
    // ──────────────────────────────────────────────

    public function testExportWritesAPackageAndNoDatabaseRows(): void
    {
        $path = $this->workspace . '/exported.ndjson';

        $watched = \CartShiftZeroWriteGuard::watch(static function () use ($path): void {
            SubscriptionCommand::export([], ['output' => $path, 'source-key' => self::SOURCE_KEY]);
        });

        $this->assertSame([], $watched['violations']);
        $this->assertFileExists($path);
    }

    public function testExportPersistsAndAppliesTheOperatorsNarrowedSelection(): void
    {
        $this->seedSource([
            $this->shapes['monthlyPln29'](['id' => 910_101]),
            $this->shapes['yearlyPln290'](['id' => 910_102]),
            $this->shapes['cancelled'](['id' => 910_103]),
        ]);

        $path = $this->workspace . '/narrowed.ndjson';

        SubscriptionCommand::export([], [
            'output'                   => $path,
            'source-key'               => self::SOURCE_KEY,
            'exclude-subscription-ids' => '910102',
            'statuses'                 => 'active,cancelled',
        ]);

        $reader = new \CartShift\Domain\Subscription\Package\SubscriptionPackageReader();
        $result = $reader->validate($path);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['manifest']?->countFor('subscription'));
        $this->assertSame([
            'excluded_subscription_ids' => [910_102],
            'source_key'                => self::SOURCE_KEY,
            'statuses'                  => ['active', 'cancelled'],
            'subscription_ids'          => [],
        ], $result['manifest']?->selection);

        $refs = [];

        foreach ($reader->records($path) as $record) {
            if ($record->kind() === 'subscription') {
                $refs[] = $record->sourceRef;
            }
        }

        $this->assertSame(['subscription:910101', 'subscription:910103'], $refs);
    }

    public function testExportRefusesAnIdIncludedAndExcludedByTheSameSelection(): void
    {
        $GLOBALS['_cartshift_test_wp_cli'] = [];
        $path = $this->workspace . '/contradictory.ndjson';

        SubscriptionCommand::export([], [
            'output'                   => $path,
            'source-key'               => self::SOURCE_KEY,
            'subscription-ids'         => '910030',
            'exclude-subscription-ids' => '910030',
        ]);

        $this->assertFileDoesNotExist($path);
        $this->assertStringContainsString(
            'both included and excluded',
            implode(' ', array_column($GLOBALS['_cartshift_test_wp_cli'], 'message')),
        );
    }

    public function testExportRefusesMalformedSubscriptionIdsInsteadOfCastingThemToZero(): void
    {
        $GLOBALS['_cartshift_test_wp_cli'] = [];
        $path = $this->workspace . '/malformed-selection.ndjson';

        SubscriptionCommand::export([], [
            'output'           => $path,
            'source-key'       => self::SOURCE_KEY,
            'subscription-ids' => '910030,banana,-4',
        ]);

        $this->assertFileDoesNotExist($path);
        $this->assertStringContainsString(
            '--subscription-ids accepts positive numeric IDs',
            implode(' ', array_column($GLOBALS['_cartshift_test_wp_cli'], 'message')),
        );
    }

    public function testExportRefusesAPathInsideAGitRepository(): void
    {
        $repo = $this->workspace . '/repo';
        mkdir($repo . '/.git', 0700, true);

        SubscriptionCommand::export([], [
            'output'     => $repo . '/package.ndjson',
            'source-key' => self::SOURCE_KEY,
        ]);

        $this->assertFileDoesNotExist($repo . '/package.ndjson');
    }

    // ──────────────────────────────────────────────
    // prepare-package / forget-package
    // ──────────────────────────────────────────────

    public function testPreparePackageStoresTheDescriptorAndNothingElse(): void
    {
        $path = $this->exportPackage();

        SubscriptionCommand::preparePackage([], ['file' => $path]);

        $descriptor = (new PackageContextRepository())->get(self::SOURCE_KEY);

        $this->assertNotNull($descriptor);
        $this->assertSame(
            ['checksum', 'path', 'selection_fingerprint', 'source_key'],
            array_keys($descriptor),
            'The descriptor is four strings. It is not a copy of the package.',
        );
        $this->assertSame($path, $descriptor['path']);
        $this->assertSame(64, strlen((string) $descriptor['checksum']));
    }

    public function testPreparePackageRefusesAPackageThatDoesNotValidate(): void
    {
        $path = $this->exportPackage();
        file_put_contents($path, file_get_contents($path) . "{\"kind\":\"customer\"}\n");

        SubscriptionCommand::preparePackage([], ['file' => $path]);

        $this->assertNull((new PackageContextRepository())->get(self::SOURCE_KEY));
    }

    public function testForgetPackageRequiresConfirmationAndThenRemovesOnlyTheDescriptor(): void
    {
        $path = $this->exportPackage();
        SubscriptionCommand::preparePackage([], ['file' => $path]);

        SubscriptionCommand::forgetPackage([], ['source-key' => self::SOURCE_KEY]);

        $this->assertNotNull(
            (new PackageContextRepository())->get(self::SOURCE_KEY),
            'Without --confirm, nothing happens.',
        );

        SubscriptionCommand::forgetPackage([], ['source-key' => self::SOURCE_KEY, 'confirm' => true]);

        $this->assertNull((new PackageContextRepository())->get(self::SOURCE_KEY));
        $this->assertFileExists($path, 'Forgetting a descriptor must not delete the package.');
    }

    // ──────────────────────────────────────────────
    // delete-package
    // ──────────────────────────────────────────────

    public function testDeletePackageRefusesWithoutConfirmation(): void
    {
        $path = $this->exportPackage();
        SubscriptionCommand::preparePackage([], ['file' => $path]);

        SubscriptionCommand::deletePackage([], ['file' => $path]);

        $this->assertFileExists($path);
    }

    public function testDeletePackageRefusesAFileThatWasNeverPrepared(): void
    {
        $path = $this->exportPackage();

        SubscriptionCommand::deletePackage([], ['file' => $path, 'confirm' => true]);

        $this->assertFileExists($path, 'delete-package is not `rm` with branding.');
    }

    public function testDeletePackageRefusesAFileWhoseChecksumHasMoved(): void
    {
        $path = $this->exportPackage();
        SubscriptionCommand::preparePackage([], ['file' => $path]);

        // A real change, not a stray newline: the last record line, repeated.
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        file_put_contents($path, implode("\n", [...$lines, end($lines)]) . "\n");

        SubscriptionCommand::deletePackage([], ['file' => $path, 'confirm' => true]);

        $this->assertFileExists($path);
    }

    public function testDeletePackageRemovesTheExactPreparedFile(): void
    {
        $path = $this->exportPackage();
        SubscriptionCommand::preparePackage([], ['file' => $path]);

        SubscriptionCommand::deletePackage([], ['file' => $path, 'confirm' => true]);

        $this->assertFileDoesNotExist($path);
        $this->assertNull(
            (new PackageContextRepository())->get(self::SOURCE_KEY),
            'A descriptor pointing at a file that is gone is worse than no descriptor.',
        );
    }

    // ──────────────────────────────────────────────
    // validate-package
    // ──────────────────────────────────────────────

    public function testValidatePackageWritesNothing(): void
    {
        $path = $this->exportPackage();

        $watched = \CartShiftZeroWriteGuard::watch(static function () use ($path): void {
            SubscriptionCommand::validatePackage([], ['file' => $path, 'format' => 'json']);
        });

        $this->assertSame([], $watched['violations']);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function auditDocument(): array
    {
        return (new \ReflectionMethod(SubscriptionCommand::class, 'auditDocument'))
            ->invoke(null, 'live', self::SOURCE_KEY, null);
    }

    private function exportPackage(string $name = 'package.ndjson'): string
    {
        $path = $this->workspace . '/' . $name;

        SubscriptionCommand::export([], ['output' => $path, 'source-key' => self::SOURCE_KEY]);

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
