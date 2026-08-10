<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\ClosureReport;
use CartShift\Domain\Subscription\DatasetClosureValidator;
use CartShift\Domain\Subscription\DatasetManifest;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\Package\PackagePath;
use CartShift\Domain\Subscription\Package\SubscriptionPackageReader;
use CartShift\Domain\Subscription\Package\SubscriptionPackageWriter;
use CartShift\Domain\Subscription\Source\PackageSubscriptionDatasetSource;
use CartShift\Domain\Subscription\Source\WooSubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/PreflightStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

/**
 * The private NDJSON package, both ways.
 *
 * The load-bearing assertion is fingerprint equality: a record exported from a
 * live WooCommerce runtime and the same record decoded on the far side of a
 * file must hash identically, because the cutover treats a changed fingerprint
 * as a changed source and aborts. If the two modes can disagree at all, the
 * freeze marker is decorative.
 *
 * Everything else here is refusal. A package that has been edited, truncated,
 * re-serialised by a well-meaning tool, or parked somewhere a web server can
 * serve it is not a package.
 */
final class SubscriptionPackageTest extends PluginTestCase
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
    // Round trip
    // ──────────────────────────────────────────────

    public function testALivePackageRoundTripPreservesEveryFingerprint(): void
    {
        $path = $this->export();

        $live = $this->liveFingerprints();
        $package = $this->packageFingerprints($path);

        $this->assertNotSame([], $live);
        $this->assertSame(
            $live,
            $package,
            'A record that crossed a site boundary must be recognisably the same record.',
        );
    }

    public function testRelationshipTypesSurviveTheRoundTrip(): void
    {
        $path = $this->export();

        $subscription = null;

        foreach ($this->packageRecords($path) as $record) {
            if ($record instanceof SubscriptionRecord) {
                $subscription = $record;
            }
        }

        $this->assertInstanceOf(SubscriptionRecord::class, $subscription);
        $this->assertSame([880_030], $subscription->relatedOrderIds(SubscriptionOrderReference::PARENT));
        $this->assertSame([880_531, 880_532], $subscription->relatedOrderIds(SubscriptionOrderReference::RENEWAL));
        $this->assertSame([880_631], $subscription->relatedOrderIds(SubscriptionOrderReference::SWITCH));
        $this->assertSame([880_731], $subscription->relatedOrderIds(SubscriptionOrderReference::RESUBSCRIBE));
    }

    public function testTheMalformedRecordSurvivesAsOneBlockedInvalidRecord(): void
    {
        $path = $this->export();

        $invalid = array_values(array_filter(
            $this->packageRecords($path),
            static fn (object $record): bool => $record instanceof InvalidSourceRecord,
        ));

        $this->assertCount(1, $invalid);
        $this->assertSame(SubscriptionRecord::KIND, $invalid[0]->entityKind);

        $manifest = (new SubscriptionPackageReader())->manifest($path);

        $this->assertSame(1, $manifest->invalidCount);
        $this->assertSame(
            2,
            $manifest->countFor(SubscriptionRecord::KIND),
            'Dropping it makes the selected total disagree with the source for ever.',
        );
    }

    public function testTheHeaderCarriesTheSelectionFingerprintAndTheStorageAuthority(): void
    {
        $selection = new SubscriptionSelection(self::SOURCE_KEY, [], [], [910_014]);
        $path = $this->export($selection);

        $manifest = (new SubscriptionPackageReader())->manifest($path);

        $this->assertSame(DatasetManifest::SCHEMA_VERSION, $manifest->schemaVersion);
        $this->assertSame($selection->fingerprint(), $manifest->selectionFingerprint);
        $this->assertSame(
            $selection->toArray(),
            SubscriptionSelection::fromArray($manifest->selection)->toArray(),
        );
        $this->assertSame('posts', $manifest->storageAuthority);
        $this->assertSame(['PLN'], $manifest->currencies);
    }

    public function testASelectionDefinitionThatDisagreesWithItsFingerprintIsRefused(): void
    {
        $path = $this->export(
            new SubscriptionSelection(self::SOURCE_KEY, [], [], [910_030]),
            'selection-tampered.ndjson',
        );
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $header = (array) json_decode((string) $lines[0], true);
        $header['selection']['excluded_subscription_ids'] = [910_031];
        $lines[0] = SubscriptionRecordFactory::canonicalJson($header);
        file_put_contents($path, implode("\n", $lines) . "\n");

        $result = (new SubscriptionPackageReader())->validate($path);

        $this->assertFalse($result['ok']);
        $this->assertContains(
            SubscriptionPackageReader::REASON_CHECKSUM_MISMATCH,
            array_column($result['failures'], 'code'),
        );
    }

    public function testARewrittenSelectionAndFingerprintCannotDisownRecordsStillInThePackage(): void
    {
        $path = $this->export(null, 'selection-rewritten.ndjson');
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $header = (array) json_decode((string) $lines[0], true);
        $selection = new SubscriptionSelection(self::SOURCE_KEY, [], [], [910_030]);
        $header['selection'] = $selection->toArray();
        $header['selection_fingerprint'] = $selection->fingerprint();
        $lines[0] = SubscriptionRecordFactory::canonicalJson($header);
        file_put_contents($path, implode("\n", $lines) . "\n");

        $result = (new SubscriptionPackageReader())->validate($path);

        $this->assertFalse($result['ok']);
        $this->assertContains(
            SubscriptionPackageReader::REASON_CHECKSUM_MISMATCH,
            array_column($result['failures'], 'code'),
        );
    }

    // ──────────────────────────────────────────────
    // The source's mirror finding, carried across
    // ──────────────────────────────────────────────

    /**
     * The comparison can only run where WooCommerce is booted — the source —
     * while in cross-runtime mode the operator decides on the target. Lapka is
     * that mode, so without this the four discrepancies section 4.9 found are
     * invisible at exactly the moment the decision is made.
     */
    public function testTheHeaderCarriesTheSourcesMirrorSummary(): void
    {
        $this->seedMirrorRows([
            910_030 => ['_schedule_next_payment' => '2100-05-11 09:15:00'],
        ]);

        $manifest = (new SubscriptionPackageReader())->manifest($this->export(null, 'mirrored.ndjson'));

        $this->assertSame('posts', $manifest->storageMirror['authority']);
        $this->assertSame('hpos', $manifest->storageMirror['mirror']);
        $this->assertTrue($manifest->storageMirror['mirror_present']);
        $this->assertSame(['payment_retry'], $manifest->storageMirror['unverified_fields']);
        $this->assertSame(
            ['next_payment' => 1, 'payment_retry' => 0],
            $manifest->storageMirror['mirror_values_found'],
        );
        $this->assertSame(
            ['next_payment' => 2, 'payment_retry' => 0],
            $manifest->storageMirror['discrepancy_counts'],
        );
    }

    /**
     * Counts travel; per-subscriber rows do not.
     *
     * The discrepancy rows carry source references and dates — customer-adjacent
     * data — and section 6.5 keeps the package free of anything it does not
     * need. A non-zero count sends the operator back to the source audit; the
     * header's job is to stop them proceeding in ignorance, not to reproduce
     * the report.
     */
    public function testTheHeaderCarriesNoPerSubscriberDiscrepancyRows(): void
    {
        $this->seedMirrorRows([
            910_030 => ['_schedule_next_payment' => '2100-05-11 09:15:00'],
        ]);

        $path = $this->export(null, 'mirrored.ndjson');
        $header = (array) json_decode((string) file($path)[0], true);

        $this->assertArrayNotHasKey('discrepancies', $header['storage_mirror']);
        $this->assertStringNotContainsString('subscription:910030', (string) json_encode($header['storage_mirror']));
    }

    /**
     * The summary is a header field, and `records_checksum` is taken over the
     * record lines. Two exports whose mirror findings differ must therefore
     * produce different headers and the identical checksum — otherwise the
     * header would be quietly participating in its own integrity value.
     */
    public function testTheMirrorSummaryDoesNotDisturbTheRecordsChecksum(): void
    {
        $withoutMirror = $this->export(null, 'plain.ndjson');

        $this->seedMirrorRows([
            910_030 => ['_schedule_next_payment' => '2100-05-11 09:15:00'],
        ]);

        $withMirror = $this->export(null, 'mirrored.ndjson');

        $reader = new SubscriptionPackageReader();

        $this->assertNotSame(
            $reader->manifest($withoutMirror)->storageMirror,
            $reader->manifest($withMirror)->storageMirror,
            'The two exports must actually differ, or this proves nothing.',
        );

        $this->assertSame(
            $reader->manifest($withoutMirror)->recordsChecksum,
            $reader->manifest($withMirror)->recordsChecksum,
        );
        $this->assertSame($this->recordLines($withoutMirror), $this->recordLines($withMirror));

        $this->assertTrue($reader->validate($withMirror)['ok']);
        $this->assertTrue($reader->validate($withoutMirror)['ok']);
    }

    public function testAPackageWithoutTheHeaderFieldStillDecodes(): void
    {
        $path = $this->export(null, 'legacy.ndjson');

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $header = (array) json_decode((string) $lines[0], true);
        unset($header['selection'], $header['storage_mirror']);
        $lines[0] = SubscriptionRecordFactory::canonicalJson($header);
        file_put_contents($path, implode("\n", $lines) . "\n");

        $result = (new SubscriptionPackageReader())->validate($path);

        $this->assertTrue(
            $result['ok'],
            'The field is additive, so schema_version stays 1 and an older package still reads.',
        );
        $this->assertSame([], $result['manifest']->storageMirror);
        $this->assertSame([], $result['manifest']->selection);
    }

    public function testTheRecordOrderIsDeterministic(): void
    {
        $first = $this->export();
        $second = $this->export(null, 'second.ndjson');

        $this->assertSame(
            $this->recordLines($first),
            $this->recordLines($second),
            'Two exports of one unchanged source must be byte-identical below the header.',
        );
    }

    public function testAPackageIsWrittenPrivateWhereTheFilesystemSupportsIt(): void
    {
        $path = $this->export();

        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    // ──────────────────────────────────────────────
    // Validation
    // ──────────────────────────────────────────────

    public function testAValidPackageValidatesWithOnlyTheMalformedRecordBlocking(): void
    {
        $result = (new SubscriptionPackageReader())->validate($this->export());

        $this->assertSame(
            [ClosureReport::CODE_INVALID_SOURCE_RECORD],
            $result['closure']->reasonCodes(),
        );
    }

    public function testAnEditedRecordFailsTheChecksum(): void
    {
        $path = $this->export();
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        $target = $this->firstLineOfKind($lines, 'order');
        $lines[$target] = str_replace('"completed"', '"refunded"', (string) $lines[$target]);

        file_put_contents($path, implode("\n", $lines) . "\n");

        $result = (new SubscriptionPackageReader())->validate($path);

        $this->assertFalse($result['ok']);
        $this->assertContains('dataset_checksum_mismatch', array_column($result['failures'], 'code'));
    }

    public function testARecordReSerialisedOutOfCanonicalFormIsRefused(): void
    {
        $path = $this->export();
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        // Same data, different byte order: what a well-meaning JSON formatter
        // produces, and what a tamper looks like once somebody has thought
        // about it for a minute.
        $decoded = json_decode((string) $lines[1], true);
        $lines[1] = json_encode(array_reverse((array) $decoded, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, implode("\n", $lines) . "\n");

        $result = (new SubscriptionPackageReader())->validate($path);

        $this->assertFalse($result['ok']);
    }

    public function testAManifestCountThatDisagreesWithTheRecordsIsRefused(): void
    {
        $path = $this->export();
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        $header = (array) json_decode((string) $lines[0], true);
        $header['counts'][SubscriptionRecord::KIND] = 99;
        $lines[0] = SubscriptionRecordFactory::canonicalJson($header);
        file_put_contents($path, implode("\n", $lines) . "\n");

        $result = (new SubscriptionPackageReader())->validate($path);

        $this->assertFalse($result['ok']);
        $this->assertContains('dataset_count_mismatch', $this->allCodes($result));
    }

    /**
     * Every structural refusal reports a section 9.4 code, and the right one.
     *
     * These three control a cutover — `validate()` folds them into `ok` and the
     * audit derives `outcome => blocked` from that — so section 9.4 requires
     * them to be ratified codes rather than free-form strings, and they now are.
     *
     * They are three codes rather than one because they need three different
     * things from the operator: upgrade CartShift or re-export from a matching
     * version; repair or re-export the header; go and look at the selection that
     * produced an empty export. `dataset_checksum_mismatch` would tell all three
     * that the content changed, which is the one thing that did not happen in
     * any of them.
     */
    public function testEachStructuralRefusalReportsItsOwnRatifiedCode(): void
    {
        $empty = $this->workspace . '/empty.ndjson';
        file_put_contents($empty, '');

        $garbled = $this->workspace . '/garbled.ndjson';
        file_put_contents($garbled, "not json at all\n");

        $future = $this->export(null, 'future.ndjson');
        $lines = file($future, FILE_IGNORE_NEW_LINES);
        $header = (array) json_decode((string) $lines[0], true);
        $header['schema_version'] = 99;
        $lines[0] = SubscriptionRecordFactory::canonicalJson($header);
        file_put_contents($future, implode("\n", $lines) . "\n");

        $expected = [
            $empty   => SubscriptionPackageReader::REASON_EMPTY,
            $garbled => SubscriptionPackageReader::REASON_HEADER_UNREADABLE,
            $future  => SubscriptionPackageReader::REASON_SCHEMA_UNSUPPORTED,
        ];

        foreach ($expected as $path => $code) {
            $result = (new SubscriptionPackageReader())->validate($path);

            $this->assertFalse($result['ok'], $path);
            $this->assertContains(
                $code,
                array_column($result['failures'], 'code'),
                sprintf('%s must name its own blocker, not a generic one.', basename($path)),
            );
        }
    }

    /**
     * A damaged record line is a content-integrity failure, and says so.
     *
     * The counterpart to the test above: here the bytes really did change, so
     * `dataset_checksum_mismatch` is exactly the right thing to tell somebody,
     * and the specific detail rides in the context where it belongs.
     */
    public function testADamagedRecordLineStaysAnIntegrityFailure(): void
    {
        $path = $this->export();
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $lines[1] = '{"kind":"customer","payload":';
        file_put_contents($path, implode("\n", $lines) . "\n");

        $result = (new SubscriptionPackageReader())->validate($path);

        $this->assertFalse($result['ok']);
        $this->assertContains(ClosureReport::CODE_CHECKSUM_MISMATCH, array_column($result['failures'], 'code'));

        $reasons = array_column(array_column($result['failures'], 'context'), 'reason');

        $this->assertContains(SubscriptionPackageReader::DETAIL_UNREADABLE_JSON, $reasons);
    }

    public function testATruncatedPackageIsRefusedRatherThanPartlyBelieved(): void
    {
        $path = $this->export();
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        array_pop($lines);
        file_put_contents($path, implode("\n", $lines) . "\n");

        $result = (new SubscriptionPackageReader())->validate($path);

        $this->assertFalse($result['ok']);
    }

    // ──────────────────────────────────────────────
    // Where a package may live
    // ──────────────────────────────────────────────

    public function testAPathInsideThePublicUploadsDirectoryIsRefused(): void
    {
        $uploads = $this->workspace . '/wp-content/uploads';
        mkdir($uploads, 0700, true);

        $resolved = PackagePath::resolveForWrite($uploads . '/package.ndjson');

        $this->assertNull($resolved['path']);
        $this->assertContains(PackagePath::REASON_PUBLIC_DIRECTORY, $resolved['failures']);
    }

    public function testAPathInsideAGitRepositoryIsRefused(): void
    {
        $repo = $this->workspace . '/repo';
        mkdir($repo . '/.git', 0700, true);

        $resolved = PackagePath::resolveForWrite($repo . '/package.ndjson');

        $this->assertNull($resolved['path']);
        $this->assertContains(PackagePath::REASON_VERSION_CONTROL, $resolved['failures']);
    }

    public function testANestedPathInsideAGitRepositoryIsAlsoRefused(): void
    {
        $repo = $this->workspace . '/repo';
        mkdir($repo . '/.git', 0700, true);
        mkdir($repo . '/private/deep', 0700, true);

        $resolved = PackagePath::resolveForWrite($repo . '/private/deep/package.ndjson');

        $this->assertNull($resolved['path']);
        $this->assertContains(PackagePath::REASON_VERSION_CONTROL, $resolved['failures']);
    }

    public function testAPathTheWriterAcceptsIsCanonical(): void
    {
        $resolved = PackagePath::resolveForWrite($this->workspace . '/./package.ndjson');

        $this->assertSame($this->workspace . '/package.ndjson', $resolved['path']);
        $this->assertSame([], $resolved['failures']);
    }

    /**
     * A file-level symlink escapes both refusals, so it is refused itself.
     *
     * `realpath(dirname())` canonicalises the directory and leaves the file name
     * un-followed, so `/srv/private/lapka.ndjson` pointing into
     * `wp-content/uploads` passes the public-directory test on the string while
     * every write follows the link — and `export` puts the entire customer and
     * order dataset inside the web root.
     */
    public function testASymlinkedTargetIsRefusedForWriting(): void
    {
        $uploads = $this->workspace . '/wp-content/uploads';
        mkdir($uploads, 0700, true);
        file_put_contents($uploads . '/real.ndjson', "{}\n");

        $link = $this->workspace . '/private.ndjson';
        symlink($uploads . '/real.ndjson', $link);

        $resolved = PackagePath::resolveForWrite($link);

        $this->assertNull($resolved['path']);
        $this->assertContains(PackagePath::REASON_SYMLINK, $resolved['failures']);
    }

    /**
     * The silent half: `delete-package` would unlink the link, print "Deleted",
     * and leave the real customer data exactly where it was — a false assurance
     * on the one command whose whole job is destroying evidence.
     */
    public function testASymlinkedTargetIsRefusedForReading(): void
    {
        $real = $this->export();
        $link = $this->workspace . '/link.ndjson';
        symlink($real, $link);

        $resolved = PackagePath::resolveForRead($link);

        $this->assertNull($resolved['path']);
        $this->assertContains(PackagePath::REASON_SYMLINK, $resolved['failures']);

        $result = (new SubscriptionPackageReader())->validate($link);

        $this->assertFalse($result['ok']);
        $this->assertNull($result['path']);
        $this->assertFileExists($real, 'The real package must still be there.');
    }

    public function testADirectorySymlinkIsFineBecauseRealpathResolvesIt(): void
    {
        $real = $this->workspace . '/vault';
        mkdir($real, 0700, true);
        symlink($real, $this->workspace . '/link-dir');

        $resolved = PackagePath::resolveForWrite($this->workspace . '/link-dir/package.ndjson');

        $this->assertSame($real . '/package.ndjson', $resolved['path']);
        $this->assertSame([], $resolved['failures']);
    }

    public function testAReadRefusesADirectory(): void
    {
        $resolved = PackagePath::resolveForRead($this->workspace);

        $this->assertNull($resolved['path']);
        $this->assertContains(PackagePath::REASON_NOT_A_FILE, $resolved['failures']);
    }

    // ──────────────────────────────────────────────
    // The package as a dataset source
    // ──────────────────────────────────────────────

    public function testThePackageSourceIsInterchangeableWithTheLiveOne(): void
    {
        $path = $this->export();

        $source = new PackageSubscriptionDatasetSource($path);
        $manifest = $source->manifest();

        $this->assertSame(self::SOURCE_KEY, $manifest->sourceKey);

        $report = (new DatasetClosureValidator())->validate(
            $manifest,
            $source->records(SubscriptionSelection::all(self::SOURCE_KEY)),
        );

        $this->assertSame(
            [ClosureReport::CODE_INVALID_SOURCE_RECORD],
            $report->reasonCodes(),
            'The package must carry exactly the same closure verdict the live source reached.',
        );
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function export(?SubscriptionSelection $selection = null, string $name = 'package.ndjson'): string
    {
        $selection ??= SubscriptionSelection::all(self::SOURCE_KEY);

        $result = (new SubscriptionPackageWriter())->write(
            $this->workspace . '/' . $name,
            new WooSubscriptionDatasetSource(self::SOURCE_KEY),
            $selection,
        );

        $this->assertSame([], $result['failures'], 'The fixture export must succeed.');

        return (string) $result['path'];
    }

    /**
     * @return list<string>
     */
    private function liveFingerprints(): array
    {
        $source = new WooSubscriptionDatasetSource(self::SOURCE_KEY);

        return $this->sortedFingerprints(iterator_to_array(
            $source->records(SubscriptionSelection::all(self::SOURCE_KEY)),
            false,
        ));
    }

    /**
     * @return list<string>
     */
    private function packageFingerprints(string $path): array
    {
        return $this->sortedFingerprints($this->packageRecords($path));
    }

    /**
     * @param list<object> $records
     * @return list<string>
     */
    private function sortedFingerprints(array $records): array
    {
        $fingerprints = array_map(static fn (object $record): string => $record->fingerprint, $records);
        sort($fingerprints);

        return $fingerprints;
    }

    /**
     * @return list<object>
     */
    private function packageRecords(string $path): array
    {
        return iterator_to_array((new SubscriptionPackageReader())->records($path), false);
    }

    /**
     * The index of the first line carrying an envelope of this kind.
     *
     * Located rather than hard-coded: the record order is deterministic but it
     * is deliberately grouped by kind, and a test that says "line 2" quietly
     * starts editing a product the day a customer record is added.
     *
     * @param list<string> $lines
     */
    private function firstLineOfKind(array $lines, string $kind): int
    {
        foreach ($lines as $index => $line) {
            if (str_starts_with((string) $line, '{"kind":"' . $kind . '"')) {
                return $index;
            }
        }

        $this->fail(sprintf('The package carries no %s line.', $kind));
    }

    /**
     * @return list<string>
     */
    private function recordLines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        array_shift($lines);

        return array_values($lines);
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private function allCodes(array $result): array
    {
        $codes = array_column($result['failures'], 'code');

        if ($result['closure'] instanceof ClosureReport) {
            $codes = array_merge($codes, $result['closure']->reasonCodes());
        }

        return array_values(array_unique($codes));
    }

    /**
     * Make the HPOS mirror exist and hold these meta values.
     *
     * @param array<int, array<string, string>> $meta
     */
    private function seedMirrorRows(array $meta): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => 'wp_wc_orders_meta';

        $GLOBALS['_cartshift_test_get_results_callback'] = static function () use ($meta): array {
            $rows = [];

            foreach ($meta as $orderId => $pairs) {
                foreach ($pairs as $key => $value) {
                    $rows[] = (object) ['object_id' => $orderId, 'meta_key' => $key, 'meta_value' => $value];
                }
            }

            return $rows;
        };
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
