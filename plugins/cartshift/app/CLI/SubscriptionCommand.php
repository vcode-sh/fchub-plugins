<?php

declare(strict_types=1);

namespace CartShift\CLI;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\ClosureReport;
use CartShift\Domain\Subscription\DatasetClosureValidator;
use CartShift\Domain\Subscription\Package\PackageContextRepository;
use CartShift\Domain\Subscription\Package\PackagePath;
use CartShift\Domain\Subscription\Package\SubscriptionPackageReader;
use CartShift\Domain\Subscription\Package\SubscriptionPackageWriter;
use CartShift\Domain\Subscription\RuntimeCompatibilityProbe;
use CartShift\Domain\Subscription\RuntimeCompatibilityReport;
use CartShift\Domain\Subscription\Source\PackageSubscriptionDatasetSource;
use CartShift\Domain\Subscription\Source\WooSubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionCutover;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Support\Constants;
use CartShift\Validator\PreflightCheck;

/**
 * `wp cartshift subscriptions <subcommand>`.
 *
 * The subscription migration is a two-site handoff run from the command line in
 * each runtime, so this is where it lives. Subcommands join by adding a
 * SUBCOMMANDS entry and a public method, which is the whole extension contract
 * — no dispatcher to rewrite and no growing switch.
 *
 * One line matters more than the rest of this class. `audit`, `export` and
 * `validate-package` write nothing to any database: no row, no option, no
 * transient, no CartShift log line, and above all no simulated ID-map row. That
 * last one is the plan's first P0 — the existing generic dry run does write
 * simulated rows while calling itself a rehearsal — and the whole point of a
 * separate audit command is that it is a different promise, not a friendlier
 * word for the same one. `prepare-package` is the single command here that
 * writes, and what it writes is four strings: source key, path, checksum,
 * selection fingerprint. `forget-package` removes exactly those four again.
 * `delete-package` deletes one file, and only one it can prove somebody
 * prepared and nobody has touched since.
 */
final class SubscriptionCommand
{
    /**
     * Subcommand name to method. The registry, and the only place to add one.
     *
     * @var array<string, string>
     */
    private const array SUBCOMMANDS = [
        'activate'         => 'activate',
        'audit'            => 'audit',
        'compatibility'    => 'compatibility',
        'cutover-source'   => 'cutoverSource',
        'delete-package'   => 'deletePackage',
        'export'           => 'export',
        'forget-package'   => 'forgetPackage',
        'prepare-package'  => 'preparePackage',
        'reconcile'        => 'reconcile',
        'restore-source'   => 'restoreSource',
        'stage'            => 'stage',
        'validate-package' => 'validatePackage',
    ];

    private const array ROLES = [
        RuntimeCompatibilityProbe::ROLE_SOURCE,
        RuntimeCompatibilityProbe::ROLE_TARGET,
    ];

    private const array FORMATS = ['table', 'json'];

    /** Where an audit reads from. */
    private const string SOURCE_LIVE = 'live';
    private const string SOURCE_PACKAGE = 'package';

    private const array SOURCES = [self::SOURCE_LIVE, self::SOURCE_PACKAGE];

    private const string OUTCOME_READY = 'ready';
    private const string OUTCOME_BLOCKED = 'blocked';

    public static function register(): void
    {
        foreach (self::SUBCOMMANDS as $name => $method) {
            \WP_CLI::add_command('cartshift subscriptions ' . $name, [self::class, $method]);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function subcommands(): array
    {
        return self::SUBCOMMANDS;
    }

    /**
     * Check this runtime against what the subscription migration assumes.
     *
     * Reads only. No database write, no option, no transient, no file, and no
     * call to Stripe or PayPal. In particular it never changes FluentCart's
     * store-wide subscription settings: if system collection is unavailable the
     * report says which input is blocking it and the operator decides.
     *
     * Run it in both runtimes before anything else happens. Save the JSON
     * outside Git — it is redacted, but it is still a description of somebody's
     * shop.
     *
     * ## OPTIONS
     *
     * --role=<role>
     * : Which half of the migration this runtime is.
     * ---
     * options:
     *   - source
     *   - target
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cartshift subscriptions compatibility --role=source
     *     wp cartshift subscriptions compatibility --role=target --format=json
     *
     * Every WP_CLI::error() is followed by an explicit return: the real one
     * exits, the test stub does not, and a guard that only works outside the
     * tests is not a guard.
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function compatibility(array $args, array $assocArgs): void
    {
        $role = (string) ($assocArgs['role'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');

        if (!in_array($role, self::ROLES, true)) {
            \WP_CLI::error(sprintf(
                '--role is required and must be one of: %s.',
                implode(', ', self::ROLES),
            ));

            return;
        }

        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error(sprintf(
                '--format must be one of: %s.',
                implode(', ', self::FORMATS),
            ));

            return;
        }

        $report = (new RuntimeCompatibilityProbe())->inspect($role);

        if ($format === 'json') {
            \WP_CLI::line(self::renderJson($report));
        } else {
            \WP_CLI\Utils\format_items('table', self::renderTable($report), ['Check', 'Result']);
        }

        if (!$report->isReady()) {
            \WP_CLI::error(sprintf(
                'This runtime is not ready: %s. Fix the contract at source — do not work around it.',
                implode(', ', $report->errors),
            ));

            return;
        }

        // Role-qualified on purpose: the fingerprint is bound to the role that
        // produced it, and a source one is not an approval token for a target.
        \WP_CLI::success(sprintf(
            'Runtime is compatible (%s, %s). Settings/census fingerprint for role=%s: %s',
            $report->role,
            $report->topology->value,
            $report->role,
            $report->fingerprint(),
        ));
    }

    // ──────────────────────────────────────────────
    // audit
    // ──────────────────────────────────────────────

    /**
     * Assess a subscription dataset without writing a single row.
     *
     * Zero-write, and meant literally: no INSERT, UPDATE or DELETE, no option,
     * no transient, no CartShift log line, and no simulated ID-map row. The
     * generic dry run does write simulated rows — that is what it is for — and
     * this command exists because "rehearsal" and "read-only" are different
     * promises that were previously being made with one word.
     *
     * Read `--source=live` in the WooCommerce runtime and `--file=` in the
     * FluentCart one. Both produce the same document, because both read the
     * same records; that is the whole point of the dataset contract.
     *
     * ## OPTIONS
     *
     * [--source=<source>]
     * : Read the live WooCommerce runtime, or a private package file.
     * ---
     * default: live
     * options:
     *   - live
     *   - package
     * ---
     *
     * [--file=<path>]
     * : Absolute path to a private package. Implies --source=package.
     *
     * [--source-key=<key>]
     * : The stable slug identifying this source. Never derived from a URL.
     * ---
     * default: local
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cartshift subscriptions audit --source=live --source-key=lapka-club
     *     wp cartshift subscriptions audit --file=/srv/private/lapka.ndjson --format=json
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function audit(array $args, array $assocArgs): void
    {
        $file = self::stringArg($assocArgs, 'file');
        $source = $file !== null
            ? self::SOURCE_PACKAGE
            : (string) ($assocArgs['source'] ?? self::SOURCE_LIVE);
        $format = (string) ($assocArgs['format'] ?? 'table');

        if (!in_array($source, self::SOURCES, true)) {
            \WP_CLI::error(sprintf('--source must be one of: %s.', implode(', ', self::SOURCES)));

            return;
        }

        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error(sprintf('--format must be one of: %s.', implode(', ', self::FORMATS)));

            return;
        }

        if ($source === self::SOURCE_PACKAGE && $file === null) {
            \WP_CLI::error('--file is required when auditing a package.');

            return;
        }

        if ($source === self::SOURCE_LIVE && !self::liveSourceIsReadable()) {
            return;
        }

        $document = self::auditDocument($source, self::sourceKey($assocArgs), $file);

        self::emit($document, $format);

        if ($document['outcome'] === self::OUTCOME_BLOCKED) {
            // The SET-level codes, which are the ones that stopped it. Listing
            // every reason code here would put a per-record refusal beside a
            // dataset-level one under a sentence saying nothing was written,
            // which is untrue of the per-record ones — they block their own
            // entry and the rest of the cohort stages.
            $codes = (array) ($document['closure']['set_level_codes'] ?? []);

            \WP_CLI::error(sprintf(
                'The dataset is blocked: %s. Nothing was written — repair the source and audit again.',
                implode(', ', $codes) ?: implode(', ', (array) ($document['closure']['reason_codes'] ?? [])),
            ));

            return;
        }

        // Ready to stage, which is not the same as spotless. A cohort with one
        // malformed subscription stages the other 563 and says so here rather
        // than letting the operator discover it from the receipt.
        $blocked = count((array) ($document['closure']['failures'] ?? []));

        \WP_CLI::success(sprintf(
            'Dataset is ready. %d records, selection %s.%s',
            (int) ($document['manifest']['total_records'] ?? 0),
            (string) ($document['selection_fingerprint'] ?? ''),
            $blocked > 0
                ? sprintf(
                    ' %d record-level finding(s) will block their own entries and no others: %s.',
                    $blocked,
                    implode(', ', (array) ($document['closure']['reason_codes'] ?? [])),
                )
                : '',
        ));
    }

    // ──────────────────────────────────────────────
    // export
    // ──────────────────────────────────────────────

    /**
     * Write a dependency-complete private package from this WooCommerce runtime.
     *
     * The file is the only thing this command creates. It touches no database
     * on either side, and it refuses to write anywhere a web server could serve
     * the result or Git could swallow it.
     *
     * ## OPTIONS
     *
     * --output=<path>
     * : Absolute path, outside the web root and outside any Git repository.
     *
     * [--source-key=<key>]
     * : The stable slug identifying this source. Use the same one every time —
     * : a source restored under a different hostname must stay idempotent, so
     * : this must never be derived from a URL.
     * ---
     * default: local
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cartshift subscriptions export --output=/srv/private/lapka.ndjson --source-key=lapka-club
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function export(array $args, array $assocArgs): void
    {
        $output = self::stringArg($assocArgs, 'output');

        if ($output === null) {
            \WP_CLI::error('--output is required.');

            return;
        }

        if (!self::liveSourceIsReadable()) {
            return;
        }

        $sourceKey = self::sourceKey($assocArgs);
        $selection = SubscriptionSelection::all($sourceKey);

        $result = (new SubscriptionPackageWriter())->write(
            $output,
            new WooSubscriptionDatasetSource($sourceKey, $selection),
            $selection,
        );

        if ($result['path'] === null) {
            \WP_CLI::error(sprintf(
                'Refused to write the package: %s.',
                implode(', ', $result['failures']),
            ));

            return;
        }

        \WP_CLI::success(sprintf(
            'Exported %d records to %s. Checksum %s, selection %s.',
            $result['manifest']?->totalRecords ?? 0,
            $result['path'],
            $result['checksum'],
            $selection->fingerprint(),
        ));
    }

    // ──────────────────────────────────────────────
    // validate-package
    // ──────────────────────────────────────────────

    /**
     * Check a package's structure, counts, canonical form and checksum.
     *
     * Structure only. A package can be structurally perfect and still contain a
     * record nobody may migrate — the one malformed Lapka subscription is
     * exactly that — so the closure verdict is reported beside the structural
     * one rather than folded into it.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : Absolute path to the private package.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function validatePackage(array $args, array $assocArgs): void
    {
        $file = self::stringArg($assocArgs, 'file');
        $format = (string) ($assocArgs['format'] ?? 'table');

        if ($file === null) {
            \WP_CLI::error('--file is required.');

            return;
        }

        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error(sprintf('--format must be one of: %s.', implode(', ', self::FORMATS)));

            return;
        }

        $result = (new SubscriptionPackageReader())->validate($file);

        self::emit(self::packageDocument($result), $format);

        if (!$result['ok']) {
            \WP_CLI::error(sprintf(
                'That file is not a valid package: %s.',
                implode(', ', array_column($result['failures'], 'code')),
            ));

            return;
        }

        \WP_CLI::success(sprintf('Package is intact. Checksum %s.', $result['checksum']));
    }

    // ──────────────────────────────────────────────
    // prepare-package
    // ──────────────────────────────────────────────

    /**
     * Remember where a validated package is, so the mapping UI can read it.
     *
     * The one command in this file that writes, and it writes four strings:
     * source key, absolute path, records checksum, selection fingerprint. It
     * does not copy the package, and it does not create a customer, an order, a
     * subscription or an ID-map row. Staging revalidates the file byte for byte
     * against this descriptor before the first destination write.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : Absolute path to the private package.
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function preparePackage(array $args, array $assocArgs): void
    {
        $file = self::stringArg($assocArgs, 'file');

        if ($file === null) {
            \WP_CLI::error('--file is required.');

            return;
        }

        $result = (new SubscriptionPackageReader())->validate($file);

        if (!$result['ok'] || $result['manifest'] === null || $result['path'] === null) {
            \WP_CLI::error(sprintf(
                'Refused to prepare that file: %s.',
                implode(', ', array_column($result['failures'], 'code')) ?: 'it is not a readable package',
            ));

            return;
        }

        (new PackageContextRepository())->remember(
            $result['manifest']->sourceKey,
            $result['path'],
            $result['checksum'],
            $result['manifest']->selectionFingerprint,
        );

        \WP_CLI::success(sprintf(
            'Prepared %s for source key %s. Checksum %s.',
            $result['path'],
            $result['manifest']->sourceKey,
            $result['checksum'],
        ));
    }

    // ──────────────────────────────────────────────
    // forget-package
    // ──────────────────────────────────────────────

    /**
     * Drop a prepared package descriptor. The file itself is left alone.
     *
     * ## OPTIONS
     *
     * --source-key=<key>
     * : The source key whose descriptor should be forgotten.
     *
     * [--confirm]
     * : Required. Without it nothing happens.
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function forgetPackage(array $args, array $assocArgs): void
    {
        $sourceKey = self::stringArg($assocArgs, 'source-key');

        if ($sourceKey === null) {
            \WP_CLI::error('--source-key is required.');

            return;
        }

        if (!self::confirmed($assocArgs)) {
            \WP_CLI::error('Pass --confirm to forget a prepared package.');

            return;
        }

        \WP_CLI::success((new PackageContextRepository())->forget($sourceKey)
            ? sprintf('Forgot the prepared package for %s. The file is still where it was.', $sourceKey)
            : sprintf('Nothing was prepared for %s.', $sourceKey));
    }

    // ──────────────────────────────────────────────
    // delete-package
    // ──────────────────────────────────────────────

    /**
     * Delete one package file, after proving it is the one that was prepared.
     *
     * Three conditions, all of them: the path matches a stored descriptor
     * exactly, the file's current checksum matches the one recorded when it was
     * prepared, and the operator passed --confirm. A package holds every
     * customer and order in the migration, so deleting it after cutover is the
     * right thing to do — but this is not `rm` with branding, and it will not
     * delete a file it cannot identify.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : Absolute path to the prepared package.
     *
     * [--confirm]
     * : Required. Without it nothing happens.
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function deletePackage(array $args, array $assocArgs): void
    {
        $file = self::stringArg($assocArgs, 'file');

        if ($file === null) {
            \WP_CLI::error('--file is required.');

            return;
        }

        if (!self::confirmed($assocArgs)) {
            \WP_CLI::error('Pass --confirm to delete a package.');

            return;
        }

        $result = (new SubscriptionPackageReader())->validate($file);

        if ($result['path'] === null) {
            \WP_CLI::error(sprintf(
                'Refused to delete that path: %s.',
                implode(', ', array_column($result['failures'], 'code')),
            ));

            return;
        }

        $packages = new PackageContextRepository();
        $descriptor = $packages->findByPath($result['path']);

        if ($descriptor === null) {
            \WP_CLI::error(
                'Refused: no prepared package has that path. Prepare it first, or delete the file yourself — '
                . 'this command only removes packages CartShift can identify.',
            );

            return;
        }

        if (!hash_equals((string) $descriptor['checksum'], $result['checksum'])) {
            \WP_CLI::error(
                'Refused: the file no longer matches the checksum recorded when it was prepared. Something '
                . 'changed it. Work out what before deleting the evidence.',
            );

            return;
        }

        // Resolved once more, immediately before the unlink. Everything above
        // this line was decided about a path that has since had time to become
        // a symlink into somewhere else entirely, and `unlink` is the one
        // operation here that cannot be taken back.
        $final = PackagePath::resolveForRead($result['path']);

        if ($final['path'] !== $result['path']) {
            \WP_CLI::error('Refused: the path changed underneath the check. Nothing was deleted.');

            return;
        }

        if (!@unlink($final['path'])) {
            \WP_CLI::error(sprintf('Could not delete %s.', $final['path']));

            return;
        }

        // The descriptor goes with it. One pointing at a file that is gone is
        // worse than no descriptor: the UI would offer a package nobody can
        // read and staging would fail at the revalidation step for a reason
        // that has nothing to do with the data.
        $packages->forget((string) $descriptor['source_key']);

        \WP_CLI::success(sprintf('Deleted %s and forgot its descriptor.', $result['path']));
    }

    // ──────────────────────────────────────────────
    // stage
    // ──────────────────────────────────────────────

    /**
     * Create the destination rows, paused, and write the cutover receipt.
     *
     * Plan section 11 Phase B. The receipt is written BEFORE the first
     * destination row and advanced to `staged` after the last one, so an
     * interruption anywhere in between leaves a receipt that says what was being
     * attempted, a source that still bills, and a destination that is paused.
     * Nothing here disables anything on the source; that is `cutover-source`,
     * and it is a separate command precisely so it can be a separate decision.
     *
     * ## OPTIONS
     *
     * [--file=<path>]
     * : Absolute path to a private package. Implies --source=package.
     *
     * [--source=<source>]
     * : Read the live WooCommerce runtime, or a private package file.
     * ---
     * default: package
     * options:
     *   - live
     *   - package
     * ---
     *
     * [--source-key=<key>]
     * : The stable slug identifying this source.
     * ---
     * default: local
     * ---
     *
     * --receipt=<path>
     * : Where to write the private cutover receipt. Outside the web root and
     * : outside any Git repository, the same as a package.
     *
     * [--approve-system-settings=<sha256>]
     * : The exact target settings/census fingerprint the audit printed. Required
     * : before any `system` candidate may be created; a hash that has moved
     * : since is not an approval. CartShift changes no FluentCart setting to
     * : make one fit.
     *
     * [--confirm]
     * : Required. Without it nothing happens.
     *
     * ## EXAMPLES
     *
     *     wp cartshift subscriptions stage --file=/srv/private/lapka.ndjson --receipt=/srv/private/receipt.ndjson --confirm
     *     wp cartshift subscriptions stage --source=live --receipt=/srv/private/receipt.ndjson --confirm
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function stage(array $args, array $assocArgs): void
    {
        $receipt = self::stringArg($assocArgs, 'receipt');

        if ($receipt === null) {
            \WP_CLI::error('--receipt is required: the cutover has no memory without one.');

            return;
        }

        if (!self::confirmed($assocArgs)) {
            \WP_CLI::error('Pass --confirm to stage. This creates customers, orders and subscriptions.');

            return;
        }

        $file = self::stringArg($assocArgs, 'file');
        $source = $file !== null
            ? self::SOURCE_PACKAGE
            : (string) ($assocArgs['source'] ?? self::SOURCE_PACKAGE);

        if (!in_array($source, self::SOURCES, true)) {
            \WP_CLI::error(sprintf('--source must be one of: %s.', implode(', ', self::SOURCES)));

            return;
        }

        $sourceKey = self::sourceKey($assocArgs);
        $dataset   = null;
        $checksum  = '';

        if ($source === self::SOURCE_LIVE) {
            if (!self::liveSourceIsReadable()) {
                return;
            }

            $dataset = new WooSubscriptionDatasetSource($sourceKey, SubscriptionSelection::all($sourceKey));
        } else {
            $descriptor = $file === null ? (new PackageContextRepository())->get($sourceKey) : null;
            $path       = $file ?? (string) ($descriptor['path'] ?? '');

            if ($path === '') {
                \WP_CLI::error(sprintf(
                    'Staging a package needs a file. Nothing has been prepared for source key "%s", and no '
                    . 'path was given.',
                    $sourceKey,
                ));

                return;
            }

            $package = (new SubscriptionPackageReader())->validate($path);

            if (!$package['ok'] || $package['path'] === null) {
                \WP_CLI::error(sprintf(
                    'That file is not a valid package: %s.',
                    implode(', ', array_column($package['failures'], 'code')) ?: 'it could not be opened',
                ));

                return;
            }

            // AGAINST THE DESCRIPTOR, NOT ONLY AGAINST ITSELF. §6.5 and
            // `preparePackage()`'s own docblock both promise the file is
            // revalidated byte for byte against what was prepared, and the
            // descriptor was being read for a path and nothing else. A file
            // swapped between `prepare-package` and `stage` for a DIFFERENT but
            // internally consistent export passes its own header check happily —
            // and the mapping decisions the operator approved in between were
            // made against the first file.
            $prepared = (string) ($descriptor['checksum'] ?? '');

            if ($prepared !== '' && !hash_equals($prepared, $package['checksum'])) {
                \WP_CLI::error(sprintf(
                    'That file is not the package that was prepared for source key "%s". It was %s and is '
                    . 'now %s. Nothing was staged: the mapping decisions taken since `prepare-package` were '
                    . 'made against the other file. Restore it, or run `prepare-package` again against this '
                    . 'one and re-check the mapping.',
                    $sourceKey,
                    $prepared,
                    $package['checksum'],
                ));

                return;
            }

            $sourceKey = $package['manifest']?->sourceKey ?? $sourceKey;
            $checksum  = $package['checksum'];
            $dataset   = new PackageSubscriptionDatasetSource($package['path']);
        }

        self::report('stage', (new SubscriptionCutover())->stage($dataset, [
            'source_key'              => $sourceKey,
            'receipt_path'            => $receipt,
            'package_checksum'        => $checksum,
            'approve_system_settings' => self::stringArg($assocArgs, 'approve-system-settings'),
            'migration_id'            => 'cutover-' . gmdate('YmdHis'),
        ]));
    }

    // ──────────────────────────────────────────────
    // cutover-source
    // ──────────────────────────────────────────────

    /**
     * Disable the source's automatic renewal for every staged subscription.
     *
     * RUN THIS IN THE WOOCOMMERCE RUNTIME. It is the one command in the plugin
     * that changes the source, and it changes exactly one thing: WooCommerce
     * Subscriptions' manual-renewal flag. It never clears a payment method,
     * never cancels or pays an order, and never deletes one.
     *
     * `--renewals-paused` is the operator stating that storefront and admin
     * renewal actions and the source cron/Action Scheduler worker are paused.
     * IT PAUSES NOTHING ITSELF. CartShift is not a process supervisor, and the
     * flag exists so the statement is recorded and timestamped in the receipt
     * rather than assumed.
     *
     * ## OPTIONS
     *
     * --receipt=<path>
     * : The receipt `stage` wrote, carried over from the target runtime.
     *
     * [--source-key=<key>]
     * : Optional. The receipt already records the source key it was written
     * : under and that is what this command uses; pass one only if you want it
     * : checked, and it must match. It is NOT a reason to re-export.
     *
     * [--confirm]
     * : Required. Without it nothing happens.
     *
     * [--renewals-paused]
     * : Required. Your statement that the source's renewal workers are paused.
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function cutoverSource(array $args, array $assocArgs): void
    {
        $receipt = self::stringArg($assocArgs, 'receipt');

        if ($receipt === null) {
            \WP_CLI::error('--receipt is required.');

            return;
        }

        if (!self::confirmed($assocArgs)) {
            \WP_CLI::error('Pass --confirm to release source ownership.');

            return;
        }

        self::report('cutover-source', (new SubscriptionCutover())->cutoverSource([
            // Null unless the operator actually typed one. The receipt carries
            // the source key it was written under and that is what the command
            // uses; passing the CLI default would make every run look like a
            // mismatch on any source whose key is not `local`.
            'source_key'      => self::stringArg($assocArgs, 'source-key'),
            'receipt_path'    => $receipt,
            'renewals_paused' => self::flag($assocArgs, 'renewals-paused'),
        ]));
    }

    // ──────────────────────────────────────────────
    // activate
    // ──────────────────────────────────────────────

    /**
     * Give the destination its intended status, once the source has let go.
     *
     * Plan section 11 Phase D, and it refuses unless the receipt proves every
     * participating record's source automatic owner has been disabled or
     * explicitly transferred. Terminal historical records are left exactly as
     * they are; deliberate manual records stay manual; blocked records are never
     * activated at all.
     *
     * ## OPTIONS
     *
     * --receipt=<path>
     * : The receipt, carried back from the source runtime.
     *
     * [--source-key=<key>]
     * : Optional. The receipt already records the source key it was written
     * : under and that is what this command uses; pass one only if you want it
     * : checked, and it must match. It is NOT a reason to re-export.
     *
     * [--confirm]
     * : Required. Without it nothing happens.
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function activate(array $args, array $assocArgs): void
    {
        $receipt = self::stringArg($assocArgs, 'receipt');

        if ($receipt === null) {
            \WP_CLI::error('--receipt is required.');

            return;
        }

        if (!self::confirmed($assocArgs)) {
            \WP_CLI::error('Pass --confirm to activate. After this, FluentCart bills these customers.');

            return;
        }

        self::report('activate', (new SubscriptionCutover())->activate([
            // See cutoverSource(): the receipt knows, so the flag is optional
            // and is compared rather than used.
            'source_key'   => self::stringArg($assocArgs, 'source-key'),
            'receipt_path' => $receipt,
        ]));
    }

    // ──────────────────────────────────────────────
    // reconcile
    // ──────────────────────────────────────────────

    /**
     * Compare what was selected with what the target now holds.
     *
     * Plan section 11 Phase E. Reads and closes the receipt. No `--confirm`,
     * because it changes no commerce data on either side.
     *
     * IT CLOSES OVER BLOCKED RECORDS AND REFUSES OVER HELD ONES. A blocked
     * record was never migrated, is reported with its reason codes, and is an
     * expected outcome of a cohort containing one malformed subscription —
     * refusing over it would mean such a cohort could never be closed at all. A
     * held record is a subscriber paused in FluentCart whose source is still
     * billing, because its history did not reconcile: safe, since only one side
     * charges, and not finished. That one stops the command, and the refusal
     * says what the state machine will actually accept next.
     *
     * ## OPTIONS
     *
     * --receipt=<path>
     * : The receipt.
     *
     * [--source-key=<key>]
     * : Optional. The receipt already records the source key it was written
     * : under and that is what this command uses; pass one only if you want it
     * : checked, and it must match. It is NOT a reason to re-export.
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function reconcile(array $args, array $assocArgs): void
    {
        $receipt = self::stringArg($assocArgs, 'receipt');

        if ($receipt === null) {
            \WP_CLI::error('--receipt is required.');

            return;
        }

        self::report('reconcile', (new SubscriptionCutover())->reconcile([
            // See cutoverSource(): the receipt knows, so the flag is optional
            // and is compared rather than used.
            'source_key'   => self::stringArg($assocArgs, 'source-key'),
            'receipt_path' => $receipt,
        ]));
    }

    // ──────────────────────────────────────────────
    // restore-source
    // ──────────────────────────────────────────────

    /**
     * Put the source's previous renewal flag back.
     *
     * RUN THIS IN THE WOOCOMMERCE RUNTIME, and only before anything has been
     * activated on the target — a source restored while the destination bills is
     * two systems charging one person. If a renewal order, retry or payment
     * appeared after the release, including an uncharged pending manual invoice
     * a queued action raised, the source STAYS MANUAL and this command stops.
     * Deleting billing history to make a rollback look tidy is how audits become
     * folklore.
     *
     * ## OPTIONS
     *
     * --receipt=<path>
     * : The receipt.
     *
     * [--source-key=<key>]
     * : Optional. The receipt already records the source key it was written
     * : under and that is what this command uses; pass one only if you want it
     * : checked, and it must match. It is NOT a reason to re-export.
     *
     * [--confirm]
     * : Required. Without it nothing happens.
     *
     * @param string[] $args      Positional arguments.
     * @param string[] $assocArgs Associative arguments.
     */
    public static function restoreSource(array $args, array $assocArgs): void
    {
        $receipt = self::stringArg($assocArgs, 'receipt');

        if ($receipt === null) {
            \WP_CLI::error('--receipt is required.');

            return;
        }

        if (!self::confirmed($assocArgs)) {
            \WP_CLI::error('Pass --confirm to restore source ownership.');

            return;
        }

        self::report('restore-source', (new SubscriptionCutover())->restoreSource([
            // See cutoverSource(): the receipt knows, so the flag is optional
            // and is compared rather than used.
            'source_key'   => self::stringArg($assocArgs, 'source-key'),
            'receipt_path' => $receipt,
        ]));
    }

    /**
     * One cutover result, said out loud.
     *
     * The reason codes lead, because they are what the plan, the receipt, the
     * tests and any retry logic key off. The prose follows for the human.
     *
     * @param array<string, mixed> $result
     */
    private static function report(string $command, array $result): void
    {
        foreach ((array) ($result['summary'] ?? []) as $key => $value) {
            \WP_CLI::line(sprintf('%s: %s', $key, is_array($value) ? implode(', ', $value) : (string) $value));
        }

        $failures = (array) ($result['failures'] ?? []);

        if (($result['ok'] ?? false) === true) {
            \WP_CLI::success(sprintf(
                '%s complete. Receipt is at "%s" (%s).',
                $command,
                (string) ($result['receipt_path'] ?? ''),
                (string) ($result['state'] ?? ''),
            ));

            return;
        }

        foreach ($failures as $failure) {
            \WP_CLI::warning(sprintf(
                '[%s] %s',
                (string) ($failure['code'] ?? ''),
                (string) ($failure['message'] ?? ''),
            ));
        }

        \WP_CLI::error(sprintf(
            '%s stopped: %s. Nothing beyond what the receipt records was changed.',
            $command,
            implode(', ', array_unique(array_column($failures, 'code'))) ?: 'no reason was given',
        ));
    }

    // ──────────────────────────────────────────────
    // Documents
    // ──────────────────────────────────────────────

    /**
     * What an audit found, as a deterministic document.
     *
     * `exported_at_utc` is deliberately dropped from the manifest here. It is
     * the one field that moves between two audits of an unchanged source, and
     * a summary that cannot be compared byte for byte across runs is no use for
     * proving nothing changed — which is exactly what it is for.
     *
     * @return array<string, mixed>
     */
    private static function auditDocument(string $source, string $sourceKey, ?string $file): array
    {
        if ($source === self::SOURCE_PACKAGE) {
            $result = (new SubscriptionPackageReader())->validate((string) $file);
            $manifest = $result['manifest'];
            $closure = $result['closure'];

            $document = [
                'closure'               => $closure?->toArray() ?? ['complete' => false, 'set_level' => true],
                'file'                  => $result['path'],
                'manifest'              => self::stableManifest($manifest?->toArray() ?? []),
                'package'               => [
                    'checksum' => $result['checksum'],
                    'failures' => $result['failures'],
                    'ok'       => $result['ok'],
                ],
                'source'                => self::SOURCE_PACKAGE,
                'source_key'            => $manifest?->sourceKey ?? $sourceKey,
                'selection_fingerprint' => $manifest?->selectionFingerprint ?? '',
                'storage_authority'     => $manifest?->storageAuthority ?? '',
                // The source's finding, carried in the header, and labelled as
                // such. This runtime has no WooCommerce to compare backends
                // against, and an operator must never read a summary that
                // travelled in a file as though the target had just verified it.
                'storage_mirror'        => self::attributedMirror(
                    $manifest?->storageMirror ?? [],
                    false,
                ),
                // SET-LEVEL, NOT `complete`. §6.2 forces an invalid record to
                // block the affected ENTITY, not the package, and the reference
                // dataset carries exactly one — so `isComplete()` reported a
                // permanent red for a cohort `SubscriptionCutover::stage()`
                // migrates 563 of 564 of. The two commands now answer the same
                // question about the same dataset; `closure.complete` is still
                // in the document, as information.
                'outcome'               => $result['ok'] && $closure !== null && !$closure->hasSetLevelFault()
                    ? self::OUTCOME_READY
                    : self::OUTCOME_BLOCKED,
            ];

            return self::canonical($document);
        }

        $selection = SubscriptionSelection::all($sourceKey);
        $live = new WooSubscriptionDatasetSource($sourceKey, $selection);

        $manifest = $live->manifest();
        $closure = (new DatasetClosureValidator())->validate($manifest, $live->records($selection));

        return self::canonical([
            'closure'               => $closure->toArray(),
            'file'                  => null,
            'manifest'              => self::stableManifest($manifest->toArray()),
            'package'               => null,
            'source'                => self::SOURCE_LIVE,
            'source_key'            => $sourceKey,
            'selection_fingerprint' => $selection->fingerprint(),
            'storage_authority'     => $manifest->storageAuthority,
            // Reported, never adopted. Lapka's HPOS mirror holds two
            // next-payment dates exactly 365 days from the authoritative ones.
            'storage_mirror'        => self::attributedMirror(
                $live->storageMirrorReport($selection),
                true,
            ),
            // The same verdict `stage` reaches. See the package arm above.
            'outcome'               => $closure->hasSetLevelFault() ? self::OUTCOME_BLOCKED : self::OUTCOME_READY,
        ]);
    }

    /**
     * @param array{ok: bool, path: string|null, manifest: mixed, checksum: string, failures: list<array<string, mixed>>, closure: ClosureReport|null} $result
     * @return array<string, mixed>
     */
    private static function packageDocument(array $result): array
    {
        return self::canonical([
            'checksum' => $result['checksum'],
            'closure'  => $result['closure']?->toArray() ?? ['complete' => false],
            'failures' => $result['failures'],
            'file'     => $result['path'],
            'manifest' => self::stableManifest($result['manifest']?->toArray() ?? []),
            'ok'       => $result['ok'],
        ]);
    }

    /**
     * A mirror finding, and who is vouching for it.
     *
     * `verified_here` is the load-bearing key. A live audit compared the two
     * backends a moment ago; a package audit is repeating what the source said
     * when it exported, which may have been days and one maintenance window
     * earlier. Both are worth showing — the alternative was showing nothing on
     * the target, which is where the cross-runtime operator actually decides —
     * but they are not the same claim and must not be printed as though they
     * were.
     *
     * An empty summary means the package predates the header field, which is a
     * third thing again: not "no discrepancies", but "nobody looked".
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private static function attributedMirror(array $summary, bool $verifiedHere): array
    {
        return [
            'reported_by'   => match (true) {
                $verifiedHere    => 'live_comparison',
                $summary === []  => 'unavailable',
                default          => 'source_export',
            },
            'summary'       => $summary,
            'verified_here' => $verifiedHere,
        ];
    }

    /**
     * The header as a document section: deterministic, and told once.
     *
     * TWO FIELDS COME OUT, FOR TWO DIFFERENT REASONS.
     *
     * `exported_at_utc` because it is the one value that moves between two
     * audits of an unchanged source, and a summary that cannot be compared byte
     * for byte across runs is no use for proving nothing changed — which is
     * what it is for.
     *
     * `storage_mirror` because it is presented above, inside an envelope that
     * says who computed it and whether this runtime verified it. Leaving the
     * bare copy here would ship the same fact twice, once with its provenance
     * and once without, and a consumer reaching for the shorter path would get
     * the unattributed one. That is precisely the mistake the envelope exists
     * to prevent: a summary computed on the source, days and one maintenance
     * window ago, must never read as something the target just checked.
     *
     * This is a decision about what the audit DOCUMENT presents. The manifest
     * object still carries the field, `DatasetManifest::toArray()` still emits
     * it, and the reader and writer both depend on that — the header is the
     * package's own, and nothing here changes it.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private static function stableManifest(array $manifest): array
    {
        unset($manifest['exported_at_utc'], $manifest['storage_mirror']);

        return $manifest;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function canonical(array $document): array
    {
        ksort($document);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function emit(array $document, string $format): void
    {
        if ($format === 'json') {
            \WP_CLI::line(self::renderDocument($document));

            return;
        }

        $flat = self::flatten($document);

        \WP_CLI\Utils\format_items(
            'table',
            array_map(
                static fn (string $check, string $result): array => ['Check' => $check, 'Result' => $result],
                array_keys($flat),
                array_values($flat),
            ),
            ['Check', 'Result'],
        );
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function renderDocument(array $document): string
    {
        return (string) json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Refuse to read a live source that is not there.
     *
     * Without this, a runtime with no WooCommerce Subscriptions produces an
     * empty dataset, an empty dataset has no closure failures, and the audit
     * cheerfully reports "ready, 0 records" — which is the silent-success
     * failure mode the whole preflight gate exists to prevent, arriving through
     * a command that had bypassed the gate. Asked through PreflightCheck so
     * there is one list of required APIs, not two that can drift.
     */
    private static function liveSourceIsReadable(): bool
    {
        $missing = (new PreflightCheck())->missingSubscriptionDatasetApis();

        if ($missing === []) {
            return true;
        }

        \WP_CLI::error(sprintf(
            'This runtime cannot read subscriptions: %s missing. Run '
            . '`wp cartshift subscriptions compatibility --role=source` for the full picture. Reporting an '
            . 'empty dataset here would look exactly like a shop with no subscribers.',
            implode(', ', $missing),
        ));

        return false;
    }

    /**
     * @param array<string, mixed> $assocArgs
     */
    private static function sourceKey(array $assocArgs): string
    {
        return self::stringArg($assocArgs, 'source-key') ?? Constants::DEFAULT_SOURCE_KEY;
    }

    /**
     * @param array<string, mixed> $assocArgs
     */
    private static function stringArg(array $assocArgs, string $name): ?string
    {
        $value = trim((string) ($assocArgs[$name] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $assocArgs
     */
    private static function confirmed(array $assocArgs): bool
    {
        return self::flag($assocArgs, 'confirm');
    }

    /**
     * A boolean flag, read the way an operator would expect.
     *
     * `--renewals-paused=0` used to read as "acknowledged", because WP-CLI hands
     * the value through as the string `'0'` and anything that is not literally
     * `false` looked like a yes. On the one flag whose entire purpose is to
     * record a deliberate human statement, that is not a defensible reading.
     *
     * @param array<string, mixed> $assocArgs
     */
    private static function flag(array $assocArgs, string $name): bool
    {
        $value = $assocArgs[$name] ?? false;

        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return $value !== false && $value !== null;
    }

    /**
     * The whole report, sorted, as JSON.
     *
     * Deterministic on purpose: later tasks bind an operator's system-collection
     * approval to the fingerprint this document carries, so two runs of an
     * unchanged store must produce byte-identical output.
     */
    private static function renderJson(RuntimeCompatibilityReport $report): string
    {
        return (string) json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * The same report flattened to dotted keys, for reading at a terminal.
     *
     * @return list<array{Check: string, Result: string}>
     */
    private static function renderTable(RuntimeCompatibilityReport $report): array
    {
        $rows = [];

        foreach (self::flatten($report->toArray()) as $key => $value) {
            $rows[] = ['Check' => $key, 'Result' => $value];
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, string>
     */
    private static function flatten(array $value, string $prefix = ''): array
    {
        $flat = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($item) && $item !== []) {
                $flat += self::flatten($item, $path);

                continue;
            }

            $flat[$path] = self::scalarToString($item);
        }

        return $flat;
    }

    private static function scalarToString(mixed $value): string
    {
        return match (true) {
            $value === null  => 'null',
            $value === true  => 'true',
            $value === false => 'false',
            is_array($value) => '[]',
            default          => (string) $value,
        };
    }
}
