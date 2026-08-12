<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LapkaRehearsalScriptsTest extends TestCase
{
    private string $root;
    private string $scripts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-lapka-fixture.' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->root = realpath($this->root) ?: throw new \RuntimeException('Temporary test root is unavailable.');
        $this->scripts = dirname(__DIR__) . '/scripts';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    /** @return iterable<string, callable(array<string,mixed>):array<string,mixed>> */
    public static function unsafeComposeProvider(): iterable
    {
        yield 'external network' => [static function (array $config): array {
            $config['networks']['isolated']['internal'] = false;
            return $config;
        }];
        yield 'privileged service' => [static function (array $config): array {
            $config['services']['source-wordpress']['privileged'] = true;
            return $config;
        }];
        yield 'routable port' => [static function (array $config): array {
            $config['services']['source-wordpress']['ports'][0]['host_ip'] = '0.0.0.0';
            return $config;
        }];
        yield 'external volume' => [static function (array $config): array {
            $config['volumes']['data']['external'] = true;
            return $config;
        }];
        yield 'shared production-like bind' => [static function (array $config): array {
            $config['services']['source-wordpress']['volumes'][0]['source'] = '/Users/tomrobak/_projects_/fchub-playground';
            return $config;
        }];
        yield 'routable WordPress URL' => [static function (array $config): array {
            $config['services']['source-wordpress']['environment']['WORDPRESS_HOME'] = 'https://fchub.vcode.sh';
            return $config;
        }];
    }

    #[DataProvider('unsafeComposeProvider')]
    public function testIsolationAssertionRejectsUnsafeRenderedCompose(callable $mutate): void
    {
        [$arguments, $environment, $config] = $this->isolationFixture();
        $this->writeJson($environment['FAKE_CONFIG'], $mutate($config));

        $result = $this->runProcess([$this->scripts . '/assert-isolated-stack.sh', ...$arguments], $environment);

        self::assertNotSame(0, $result['status'], $result['stdout']);
        self::assertStringContainsString('isolation failed', $result['stderr']);
    }

    public function testIsolationAssertionAcceptsOnlyTheGeneratedPrivateBoundary(): void
    {
        [$arguments, $environment, $config] = $this->isolationFixture();
        $this->writeJson($environment['FAKE_CONFIG'], $config);
        $output = $this->root . '/evidence/isolation.json';

        $result = $this->runProcess([
            $this->scripts . '/assert-isolated-stack.sh', ...$arguments, '--output', $output,
        ], $environment);

        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame('isolated', json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR)['status']);
    }

    public function testIsolationAssertionRejectsTheMutableWorkingTreeEvenWhenItIsMountedReadOnly(): void
    {
        [$arguments, $environment, $config] = $this->isolationFixture();
        $config['services']['source-wordpress']['volumes'][0]['source'] = dirname(__DIR__, 3);
        $config['services']['source-wordpress']['volumes'][0]['read_only'] = true;
        $this->writeJson($environment['FAKE_CONFIG'], $config);

        $result = $this->runProcess([$this->scripts . '/assert-isolated-stack.sh', ...$arguments], $environment);

        self::assertNotSame(0, $result['status'], $result['stdout']);
        self::assertStringContainsString('isolation failed', $result['stderr']);
    }

    public function testRestoreRejectsDatabaseScopeStatementsBeforeDockerCanRun(): void
    {
        $evidence = $this->directory('restore-evidence');
        $package = $this->directory('sealed-package');
        file_put_contents($package . '/manifest.json', "{}\n");
        chmod($package . '/manifest.json', 0600);
        $sourceSql = $this->file(
            'source.sql',
            "DROP DATABASE production;\n" . str_repeat("INSERT INTO wp_posts VALUES (1);\n", 40_000),
        );
        $targetSql = $this->file('target.sql', "CREATE TABLE wp_posts (id INT);\n");
        $sourceTar = $this->archive('source-content.tar');
        $targetTar = $this->archive('target-content.tar');
        $candidate = $this->candidateArchive('restore-candidate.zip');
        $sourceBaseline = $this->baseline('source', $sourceSql, $sourceTar, 'wp_');
        $targetBaseline = $this->baseline('target', $targetSql, $targetTar, 'wp_');

        $result = $this->runProcess([
            $this->scripts . '/restore-lapka-fixture.sh',
            '--mode', 'empty', '--project', 'cartshift-lapka-empty-test123',
            '--source-sql', $sourceSql, '--source-sql-sha256', hash_file('sha256', $sourceSql),
            '--target-sql', $targetSql, '--target-sql-sha256', hash_file('sha256', $targetSql),
            '--source-wp-content', $sourceTar, '--source-wp-content-sha256', hash_file('sha256', $sourceTar),
            '--target-wp-content', $targetTar, '--target-wp-content-sha256', hash_file('sha256', $targetTar),
            '--source-baseline', $sourceBaseline, '--target-baseline', $targetBaseline,
            '--source-prefix', 'wp_', '--target-prefix', 'wp_', '--package-dir', $package,
            '--manifest-sha256', hash_file('sha256', $package . '/manifest.json'),
            '--candidate-zip', $candidate, '--candidate-sha256', hash_file('sha256', $candidate),
            '--evidence-dir', $evidence, '--state-file', $evidence . '/state.json',
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('attempts to select or mutate a database', $result['stderr']);
    }

    public function testRestoreCanonicalisesTheGeneratedFixtureRootBeforeIsolation(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringContainsString(
            'fixture_root="$(cd "$fixture_root" && pwd -P)"',
            $restore,
            'macOS exposes TMPDIR through /var while its canonical path is /private/var.',
        );
    }

    public function testRestoreStatePinsTheImagesThatTheRunnerMustReuse(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        foreach (['mariadb_image', 'wpcli_image', 'wordpress_image'] as $field) {
            self::assertStringContainsString("--arg {$field}", $restore);
            self::assertStringContainsString(".{$field} | test", $runner);
        }
        self::assertStringContainsString('CARTSHIFT_REHEARSAL_MARIADB_IMAGE="$mariadb_image"', $runner);
        self::assertStringContainsString('CARTSHIFT_REHEARSAL_WPCLI_IMAGE="$wpcli_image"', $runner);
        self::assertStringContainsString('CARTSHIFT_REHEARSAL_WORDPRESS_IMAGE="$wordpress_image"', $runner);
    }

    public function testRestoreWaitsForAnAuthenticatedDatabaseQueryInsteadOfMariaDbAdminPing(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringNotContainsString('mariadb-admin ping', $restore);
        self::assertStringContainsString("mariadb -B -N -urehearsal -prehearsal -e 'SELECT 1'", $restore);
    }

    public function testRestoreSuppressesOnlyUnsupportedArchiveMetadataWarnings(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertSame(
            4,
            substr_count($restore, '--warning=no-unknown-keyword'),
            'Both source and target content/core extractions must suppress harmless macOS metadata warnings.',
        );
    }

    public function testRestoreCanBindAnInstalledWordPressCoreWithoutImportingContentOrConfiguration(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringContainsString('--wordpress-root)', $restore);
        self::assertStringContainsString('wordpress-root\\/wp-content($|\\/)', $restore);
        self::assertStringContainsString('wordpress-root\\/wp-config\\.php$', $restore);
        self::assertStringContainsString('verify_digest \'WordPress root backup\'', $restore);
        self::assertSame(2, substr_count($restore, 'tar --warning=no-unknown-keyword -xf /fixture-artifacts/wordpress-root.tar'));
        self::assertStringContainsString('wordpress_root_sha256:$wordpress_root_sha256', $restore);
    }

    public function testRestorePreservesASealedBaselineMismatchDiagnosticBeforeCleanup(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringContainsString('baseline-mismatch.json', $restore);
        self::assertStringContainsString('--slurpfile sealed "$baseline"', $restore);
        self::assertStringContainsString('--slurpfile actual "$actual"', $restore);
    }

    public function testRestoreDatabaseProjectionDoesNotLetDockerConsumeTheTableLoopInput(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringContainsString(
            '"SELECT COUNT(*) FROM \\`${table}\\`;" < /dev/null | tr',
            $restore,
        );
        self::assertStringContainsString(
            '"CHECKSUM TABLE \\`${table}\\`;" < /dev/null | awk',
            $restore,
        );
    }

    public function testRunnerDatabaseQueriesCannotConsumeProjectionLoopInput(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        self::assertStringContainsString('-e "$1" < /dev/null | tr', $runner);
    }

    public function testRunnerPlacesQuietAfterCommandArgumentsSoWpCliAcceptsValuelessFlags(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        self::assertStringContainsString('"$service" wp --allow-root "$@" --quiet', $runner);
        self::assertStringNotContainsString('"$service" wp --allow-root --quiet "$@"', $runner);
    }

    public function testRunnerSerialisesTheRehearsalSourceProofAsCanonicalCompactJson(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        self::assertStringContainsString('jq -cS -n --arg descriptor "$descriptor"', $runner);
    }

    public function testRunnerVerifiesFinalReconciledMapsInsteadOfAnUnusedActiveState(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        self::assertStringContainsString("m.record_state='reconciled'", $runner);
        self::assertStringNotContainsString("m.record_state='active'", $runner);
        self::assertStringContainsString(
            "CONCAT(m.source_key, ':', m.entity_type, ':', m.wc_id)=r.source_identity",
            $runner,
        );
        self::assertStringNotContainsString('m.wc_id=CAST(SUBSTRING_INDEX', $runner);
    }

    public function testRunnerRequiresSemanticReceiptsForJournalledRootsRatherThanEmbeddedDependencies(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        self::assertStringContainsString(
            "[.record_counts.product, .record_counts.customer, .record_counts.order, .record_counts.subscription]",
            $runner,
        );
    }

    public function testRestoreKeepsWordPressPrivateOnTheInternalServiceNetwork(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringNotContainsString('ports: [{target: 80', $restore);
        self::assertStringNotContainsString('docker inspect "$container_id"', $restore);
        self::assertStringContainsString('url="http://${web}"', $restore);
    }

    public function testRestorePreservesWordPressReadinessDiagnosticsBeforeCleanup(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringContainsString('wordpress-readiness-failure.txt', $restore);
        self::assertStringContainsString('wp "$cli" core is-installed', $restore);
        self::assertStringContainsString('logs --no-color "$web"', $restore);
    }

    public function testRestoreDisablesTheProductionRedisDropInWithoutAddingRedisToTheFixture(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertSame(4, substr_count($restore, "define('WP_REDIS_DISABLED', true);"));
        self::assertStringNotContainsString('redis:', $restore);
    }

    public function testRestoreProvidesEnoughMemoryForTheInstalledLapkaPluginSet(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertSame(4, substr_count($restore, "define('WP_MEMORY_LIMIT', '512M');"));
        self::assertSame(4, substr_count($restore, "define('WP_MAX_MEMORY_LIMIT', '512M');"));
    }

    public function testRestoreDisablesCronAndUpdatersInCliAndWebContainers(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertSame(4, substr_count($restore, "define('DISABLE_WP_CRON', true);"));
        self::assertSame(4, substr_count($restore, "define('WP_HTTP_BLOCK_EXTERNAL', true);"));
        self::assertSame(4, substr_count($restore, "define('AUTOMATIC_UPDATER_DISABLED', true);"));
    }

    public function testRestoreSuppressesWordPressUpdateChecksWithoutHidingOtherHttpAttempts(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringContainsString("add_filter('pre_site_transient_update_core'", $restore);
        self::assertStringContainsString("add_filter('pre_site_transient_update_plugins'", $restore);
        self::assertStringContainsString("add_filter('pre_site_transient_update_themes'", $restore);
        self::assertStringContainsString("cartshift_rehearsal_spy('outbound_http_attempt', [", $restore);
        self::assertStringNotContainsString('api.wordpress.org', $restore);
    }

    public function testRestoreRecordsSanitisedHttpDiagnosticsWithoutPersistingRequestQueries(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringContainsString("wp_parse_url(\$url, PHP_URL_HOST)", $restore);
        self::assertStringContainsString("wp_parse_url(\$url, PHP_URL_PATH)", $restore);
        self::assertStringContainsString("'trace' => cartshift_rehearsal_trace()", $restore);
        self::assertStringContainsString("}, PHP_INT_MIN, 3);", $restore);
        self::assertStringNotContainsString("'url' => \$url", $restore);
    }

    public function testRestoreResolvesOnlyLoopbackGeolocationWithoutMaskingExternalHttp(): void
    {
        $restore = (string) file_get_contents($this->scripts . '/restore-lapka-fixture.sh');

        self::assertStringContainsString("add_filter('woocommerce_geolocate_ip'", $restore);
        self::assertStringContainsString('WC_Geolocation::get_ip_address()', $restore);
        self::assertStringContainsString('$effectiveIp', $restore);
        self::assertStringContainsString("['127.0.0.1', '::1']", $restore);
        self::assertStringContainsString('wc_get_base_location()', $restore);
        self::assertStringNotContainsString("add_filter('woocommerce_geolocation_geoip_apis'", $restore);
    }

    public function testRehearsalRestoreRefusesToSubstituteWorkingSourceForAMissingCandidateZip(): void
    {
        $evidence = $this->directory('candidate-evidence');
        $package = $this->directory('candidate-package');
        file_put_contents($package . '/manifest.json', "{}\n");
        chmod($package . '/manifest.json', 0600);
        $sourceSql = $this->file('candidate-source.sql', "CREATE TABLE wp_posts (id INT);\n");
        $targetSql = $this->file('candidate-target.sql', "CREATE TABLE wp_posts (id INT);\n");
        $sourceTar = $this->archive('candidate-source-content.tar');
        $targetTar = $this->archive('candidate-target-content.tar');
        $sourceBaseline = $this->baseline('source', $sourceSql, $sourceTar, 'wp_');
        $targetBaseline = $this->baseline('target', $targetSql, $targetTar, 'wp_');
        $fakeBin = $this->directory('candidate-bin');
        $docker = $fakeBin . '/docker';
        file_put_contents($docker, "#!/usr/bin/env bash\nexit 91\n");
        chmod($docker, 0700);

        $result = $this->runProcess([
            $this->scripts . '/restore-lapka-fixture.sh',
            '--mode', 'empty', '--project', 'cartshift-lapka-empty-test123',
            '--source-sql', $sourceSql, '--source-sql-sha256', hash_file('sha256', $sourceSql),
            '--target-sql', $targetSql, '--target-sql-sha256', hash_file('sha256', $targetSql),
            '--source-wp-content', $sourceTar, '--source-wp-content-sha256', hash_file('sha256', $sourceTar),
            '--target-wp-content', $targetTar, '--target-wp-content-sha256', hash_file('sha256', $targetTar),
            '--source-baseline', $sourceBaseline, '--target-baseline', $targetBaseline,
            '--source-prefix', 'wp_', '--target-prefix', 'wp_', '--package-dir', $package,
            '--manifest-sha256', hash_file('sha256', $package . '/manifest.json'),
            '--evidence-dir', $evidence, '--state-file', $evidence . '/state.json',
        ], [
            'PATH' => $fakeBin . ':' . (string) getenv('PATH'),
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('candidate ZIP is required', $result['stderr']);
    }

    public function testRunnerRequiresExactFilesystemDeltaAndRejectsAcceptedSideEffectsBeforeTouchingDocker(): void
    {
        $evidence = $this->directory('runner-evidence');
        $package = $this->directory('runner-package');
        $compose = $this->file('compose.yaml', "services: {}\n");
        $restore = $this->file('restore.json', "{}\n");
        $isolation = $this->file('isolation.json', "{}\n");
        $manifest = $package . '/manifest.json';
        file_put_contents($manifest, "{}\n");
        chmod($manifest, 0600);
        $decision = $evidence . '/decisions.json';
        file_put_contents($decision, "{}\n");
        chmod($decision, 0600);
        $selection = str_repeat('a', 64);
        $expectations = $evidence . '/expectations.json';
        $candidate = $this->directory('runner-candidate');
        $candidateEntrypoint = $this->file('runner-candidate/cartshift.php', "<?php\n");
        $candidateTreeSha = hash('sha256', "cartshift.php\t" . hash_file('sha256', $candidateEntrypoint) . "\n");
        $this->writeJson($expectations, [
            'version' => 1, 'source_key' => 'lapka-web', 'selection_fingerprint' => $selection,
            'record_counts' => [], 'receipt_counts' => [], 'receipt_action_counts' => [], 'map_counts' => [],
            'target_table_deltas' => [], 'money' => [],
            'outcomes' => ['selected' => 0, 'created' => 0, 'reused' => 0, 'blocked' => 0],
            'outbox_rows' => 0, 'spies' => ['lifecycle_event' => 0, 'mail_attempt' => 1, 'outbound_http_attempt' => 0],
            'dangling_maps' => 0, 'blocking_findings' => 0,
        ]);
        $state = $evidence . '/state.json';
        $this->writeJson($state, [
            'version' => 1, 'mode' => 'empty', 'project' => 'cartshift-lapka-empty-test123',
            'compose_file' => $compose, 'compose_sha256' => hash_file('sha256', $compose),
            'fixture_root' => $this->root, 'evidence_dir' => $evidence, 'package_dir' => $package,
            'manifest_sha256' => hash_file('sha256', $manifest), 'source_prefix' => 'wp_', 'target_prefix' => 'wp_',
            'candidate_dir' => $candidate, 'candidate_zip_sha256' => str_repeat('d', 64),
            'candidate_tree_sha256' => $candidateTreeSha,
            'mariadb_image' => 'sha256:' . str_repeat('1', 64),
            'wpcli_image' => 'sha256:' . str_repeat('2', 64),
            'wordpress_image' => 'sha256:' . str_repeat('3', 64),
            'restore_report' => $restore, 'restore_report_sha256' => hash_file('sha256', $restore),
            'isolation_report' => $isolation, 'isolation_report_sha256' => hash_file('sha256', $isolation),
        ]);

        $result = $this->runProcess([
            $this->scripts . '/run-lapka-rehearsal.sh', '--mode', 'empty', '--state-file', $state,
            '--package-dir', $package, '--manifest-sha256', hash_file('sha256', $manifest),
            '--decision-set', $decision, '--decision-set-sha256', hash_file('sha256', $decision),
            '--expectations', $expectations, '--expectations-sha256', hash_file('sha256', $expectations),
            '--output', $evidence . '/report.json', '--operator', 'owner.test', '--source-key', 'lapka-web',
            '--selection-fingerprint', $selection, '--schema-from', '8', '--approval-reference', str_repeat('b', 64),
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('expectations contract is invalid', $result['stderr']);

        $document = json_decode((string) file_get_contents($expectations), true, flags: JSON_THROW_ON_ERROR);
        $document['target_files'] = ['added' => [], 'changed' => [], 'removed' => []];
        $this->writeJson($expectations, $document);
        $result = $this->runProcess([
            $this->scripts . '/run-lapka-rehearsal.sh', '--mode', 'empty', '--state-file', $state,
            '--package-dir', $package, '--manifest-sha256', hash_file('sha256', $manifest),
            '--decision-set', $decision, '--decision-set-sha256', hash_file('sha256', $decision),
            '--expectations', $expectations, '--expectations-sha256', hash_file('sha256', $expectations),
            '--output', $evidence . '/report.json', '--operator', 'owner.test', '--source-key', 'lapka-web',
            '--selection-fingerprint', $selection, '--schema-from', '8', '--approval-reference', str_repeat('b', 64),
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('accepted blockers/side effects', $result['stderr']);

        $result = $this->runProcess([
            $this->scripts . '/run-lapka-rehearsal.sh', '--mode', 'repeat', '--state-file', $state,
            '--package-dir', $package, '--manifest-sha256', hash_file('sha256', $manifest),
            '--decision-set', $decision, '--decision-set-sha256', hash_file('sha256', $decision),
            '--expectations', $expectations, '--expectations-sha256', hash_file('sha256', $expectations),
            '--output', $evidence . '/repeat-report.json', '--operator', 'owner.test', '--source-key', 'lapka-web',
            '--selection-fingerprint', $selection, '--schema-from', '8', '--approval-reference', str_repeat('b', 64),
            '--resume-descriptor', 'tr-' . str_repeat('c', 24),
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('accepted blockers/side effects', $result['stderr']);
        self::assertStringNotContainsString('restore state contract or mode is invalid', $result['stderr']);
    }

    public function testRunnerRequiresReceiptEvidenceForEveryPackageRecordKind(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');
        $allKinds = "record_kind IN ('product','customer','order','subscription','taxonomy_term','media_asset')";

        self::assertGreaterThanOrEqual(
            2,
            substr_count($runner, $allKinds),
            'Both action counts and semantic receipts must cover auxiliary package records.',
        );
        self::assertStringContainsString(
            '(product|customer|order|subscription|taxonomy_term|media_asset)',
            $runner,
        );
    }

    public function testRepeatVerificationDoesNotRunTheLegacyPreMigrationTargetAudit(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        self::assertStringContainsString(
            <<<'SHELL'
if [ "$mode" = repeat ]; then
  jq -S -n '{status:"not_applicable",reason:"completed_descriptor_uses_final_state_reconciliation"}' > "$run_dir/02-inspect-target.json"
  chmod 0600 "$run_dir/02-inspect-target.json"
else
  command_json target-cli "$run_dir/02-inspect-target.json" cartshift transfer inspect-target \
    --role=target --source-key="$source_key" --format=json
fi
SHELL,
            $runner,
            'A completed descriptor must be verified from its receipts and finalisation evidence, not re-audited as a pre-migration target.',
        );
    }

    public function testRollbackRunnerRequiresAnExplicitPlanOrApplyPhase(): void
    {
        $result = $this->runProcess([$this->scripts . '/run-lapka-rehearsal.sh', '--mode', 'rollback']);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('--rollback-action=plan or apply', $result['stderr']);
    }

    public function testRollbackApplyRequiresTheSealedPreStageBaseline(): void
    {
        $result = $this->runProcess([
            $this->scripts . '/run-lapka-rehearsal.sh', '--mode', 'rollback', '--rollback-action', 'apply',
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('requires the sealed rollback baseline report', $result['stderr']);
    }

    public function testRollbackStageExcludesPostActivationCatalogueReceiptsFromItsExpectedTotal(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        self::assertStringContainsString(
            <<<'SHELL'
  expected_stage_receipt_total="$(jq '[.receipt_action_counts | to_entries[] | select(.key | endswith(":created")) | .value] | add // 0' "$expectations")"
SHELL,
            $runner,
        );
        self::assertStringNotContainsString(
            <<<'SHELL'
  expected_receipt_total="$(jq '[.receipt_counts[]] | add // 0' "$expectations")"
SHELL,
            $runner,
            'Catalogue status receipts are created only after activation and cannot be required by staging.',
        );
    }

    public function testRollbackProjectionTreatsObservedPluginRuntimeOptionValuesAsVolatile(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        foreach ([
            '_ff_fluentform_pro_license_status_checking',
            '_transient_timeout_wcs_woocommerce_active_version',
            'pmpro_library_conflicts',
        ] as $optionName) {
            self::assertStringContainsString($optionName, $runner);
        }
        self::assertStringContainsString('WHERE option_name NOT IN (', $runner);
        self::assertStringContainsString('WHERE option_name IN (', $runner);
    }

    public function testRollbackApplyDerivesItsFileDeltaFromTheSealedPreStageBaseline(): void
    {
        $runner = (string) file_get_contents($this->scripts . '/run-lapka-rehearsal.sh');

        self::assertStringContainsString('rollback-expected-target-files-delta.json', $runner);
        self::assertStringContainsString('.rollback_baseline.target_files', $runner);
        self::assertStringContainsString(
            'target filesystem delta differs from sealed pre-stage baseline',
            $runner,
        );
    }

    public function testRollbackRestorationVerifierRejectsAnyDatabaseOrFileDrift(): void
    {
        $descriptor = 'tr-' . str_repeat('a', 24);
        $database = $this->root . '/rollback-database.json';
        $files = $this->root . '/rollback-files.json';
        $baseline = $this->root . '/rollback-baseline.json';
        $this->writeJson($database, [
            'schema_sha256' => str_repeat('1', 64),
            'stable_option_hashes' => ['siteurl' => str_repeat('3', 64)],
            'stable_options_sha256' => str_repeat('4', 64),
            'volatile_option_value_names' => ['_ff_fluentform_pro_license_status_checking', '_transient_timeout_wcs_woocommerce_active_version', 'pmpro_library_conflicts'],
            'volatile_options_shape_sha256' => str_repeat('5', 64),
            'table_counts' => ['wp_fct_orders' => 438],
            'table_checksums' => ['wp_fct_orders' => 123456],
        ]);
        $this->writeJson($files, ['uploads/existing.jpg' => str_repeat('b', 64)]);
        $this->writeJson($baseline, [
            'status' => 'owner_review_required',
            'mode' => 'rollback',
            'descriptor' => $descriptor,
            'rollback_plan_sha256' => str_repeat('c', 64),
            'rollback_baseline' => [
                'target_database' => json_decode((string) file_get_contents($database), true, flags: JSON_THROW_ON_ERROR),
                'target_files' => json_decode((string) file_get_contents($files), true, flags: JSON_THROW_ON_ERROR),
            ],
        ]);

        $result = $this->runProcess([
            $this->scripts . '/verify-lapka-rollback-restoration.sh',
            '--baseline-report', $baseline, '--baseline-report-sha256', hash_file('sha256', $baseline),
            '--descriptor', $descriptor, '--database-projection', $database,
            '--files-projection', $files, '--output', $this->root . '/rollback-restored.json',
        ]);

        self::assertSame(0, $result['status'], $result['stderr']);

        $this->writeJson($database, [
            'schema_sha256' => str_repeat('1', 64),
            'stable_option_hashes' => ['siteurl' => str_repeat('3', 64)],
            'stable_options_sha256' => str_repeat('4', 64),
            'volatile_option_value_names' => ['_ff_fluentform_pro_license_status_checking', '_transient_timeout_wcs_woocommerce_active_version', 'pmpro_library_conflicts'],
            'volatile_options_shape_sha256' => str_repeat('5', 64),
            'table_counts' => ['wp_fct_orders' => 438],
            'table_checksums' => ['wp_fct_orders' => 654321],
        ]);
        $result = $this->runProcess([
            $this->scripts . '/verify-lapka-rollback-restoration.sh',
            '--baseline-report', $baseline, '--baseline-report-sha256', hash_file('sha256', $baseline),
            '--descriptor', $descriptor, '--database-projection', $database,
            '--files-projection', $files, '--output', $this->root . '/rollback-database-drift.json',
        ]);
        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('business database differs', $result['stderr']);

        $this->writeJson($database, [
            'schema_sha256' => str_repeat('2', 64),
            'stable_option_hashes' => ['siteurl' => str_repeat('3', 64)],
            'stable_options_sha256' => str_repeat('4', 64),
            'volatile_option_value_names' => ['_ff_fluentform_pro_license_status_checking', '_transient_timeout_wcs_woocommerce_active_version', 'pmpro_library_conflicts'],
            'volatile_options_shape_sha256' => str_repeat('5', 64),
            'table_counts' => ['wp_fct_orders' => 438],
            'table_checksums' => ['wp_fct_orders' => 123456],
        ]);
        $result = $this->runProcess([
            $this->scripts . '/verify-lapka-rollback-restoration.sh',
            '--baseline-report', $baseline, '--baseline-report-sha256', hash_file('sha256', $baseline),
            '--descriptor', $descriptor, '--database-projection', $database,
            '--files-projection', $files, '--output', $this->root . '/rollback-schema-drift.json',
        ]);
        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('business database differs', $result['stderr']);

        $this->writeJson($database, [
            'schema_sha256' => str_repeat('1', 64),
            'stable_option_hashes' => ['siteurl' => str_repeat('3', 64)],
            'stable_options_sha256' => str_repeat('6', 64),
            'volatile_option_value_names' => ['_ff_fluentform_pro_license_status_checking', '_transient_timeout_wcs_woocommerce_active_version', 'pmpro_library_conflicts'],
            'volatile_options_shape_sha256' => str_repeat('5', 64),
            'table_counts' => ['wp_fct_orders' => 438],
            'table_checksums' => ['wp_fct_orders' => 123456],
        ]);
        $result = $this->runProcess([
            $this->scripts . '/verify-lapka-rollback-restoration.sh',
            '--baseline-report', $baseline, '--baseline-report-sha256', hash_file('sha256', $baseline),
            '--descriptor', $descriptor, '--database-projection', $database,
            '--files-projection', $files, '--output', $this->root . '/rollback-option-drift.json',
        ]);
        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('business database differs', $result['stderr']);

        $this->writeJson($database, [
            'schema_sha256' => str_repeat('1', 64),
            'stable_option_hashes' => ['siteurl' => str_repeat('3', 64)],
            'stable_options_sha256' => str_repeat('4', 64),
            'volatile_option_value_names' => ['_ff_fluentform_pro_license_status_checking', '_transient_timeout_wcs_woocommerce_active_version', 'pmpro_library_conflicts'],
            'volatile_options_shape_sha256' => str_repeat('5', 64),
            'table_counts' => ['wp_fct_orders' => 438],
            'table_checksums' => ['wp_fct_orders' => 123456],
        ]);
        $this->writeJson($files, ['uploads/existing.jpg' => str_repeat('d', 64)]);
        $result = $this->runProcess([
            $this->scripts . '/verify-lapka-rollback-restoration.sh',
            '--baseline-report', $baseline, '--baseline-report-sha256', hash_file('sha256', $baseline),
            '--descriptor', $descriptor, '--database-projection', $database,
            '--files-projection', $files, '--output', $this->root . '/rollback-file-drift.json',
        ]);
        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('target files differ', $result['stderr']);
    }

    public function testRepeatRunnerRequiresTheCompletedDescriptorItMustVerify(): void
    {
        $result = $this->runProcess([$this->scripts . '/run-lapka-rehearsal.sh', '--mode', 'repeat']);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('repeat mode requires --resume-descriptor', $result['stderr']);
    }

    public function testSemanticComparisonAllowsOnlyCreatedVersusReusedDifferences(): void
    {
        $empty = $this->root . '/empty-report.json';
        $populated = $this->root . '/populated-report.json';
        $output = $this->root . '/comparison.json';
        $emptyReport = $this->rehearsalReport('empty', [
            'customer:created' => 1,
            'product:created' => 2,
        ], 3, 0);
        $emptyReport['record_counts']['media_asset'] = 1;
        $emptyReport['record_counts']['taxonomy_term'] = 1;
        $emptyReport['outcomes']['selected'] = 5;
        $this->writeJson($empty, $emptyReport);

        $populatedReport = $this->rehearsalReport('populated', [
            'customer:reused' => 1,
            'product:created' => 1,
            'product:reused' => 1,
        ], 1, 2);
        $populatedReport['record_counts']['media_asset'] = 1;
        $populatedReport['record_counts']['taxonomy_term'] = 1;
        $populatedReport['outcomes']['selected'] = 5;
        $this->writeJson($populated, $populatedReport);

        $result = $this->runProcess([
            $this->scripts . '/compare-lapka-rehearsals.sh',
            '--empty-report', $empty, '--empty-sha256', hash_file('sha256', $empty),
            '--populated-report', $populated, '--populated-sha256', hash_file('sha256', $populated),
            '--output', $output,
        ]);

        self::assertSame(0, $result['status'], $result['stderr']);
        $comparison = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('passed', $comparison['status']);
        self::assertSame([
            'customer' => ['empty' => ['created' => 1, 'reused' => 0], 'populated' => ['created' => 0, 'reused' => 1]],
            'product' => ['empty' => ['created' => 2, 'reused' => 0], 'populated' => ['created' => 1, 'reused' => 1]],
        ], $comparison['explained_action_differences']);

        $changed = $this->rehearsalReport('populated', [
            'customer:reused' => 1,
            'product:created' => 1,
            'product:reused' => 1,
        ], 1, 2);
        $changed['semantic_receipts']['lapka-web:product:10']['source_fingerprint'] = str_repeat('f', 64);
        $this->writeJson($populated, $changed);
        $result = $this->runProcess([
            $this->scripts . '/compare-lapka-rehearsals.sh',
            '--empty-report', $empty, '--empty-sha256', hash_file('sha256', $empty),
            '--populated-report', $populated, '--populated-sha256', hash_file('sha256', $populated),
            '--output', $this->root . '/changed-comparison.json',
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('semantic receipt coverage differs', $result['stderr']);

        $changed = $this->rehearsalReport('populated', [
            'customer:reused' => 1,
            'product:created' => 1,
            'product:reused' => 1,
        ], 1, 2);
        $changed['money']['total_amount'] = 1;
        $this->writeJson($populated, $changed);
        $result = $this->runProcess([
            $this->scripts . '/compare-lapka-rehearsals.sh',
            '--empty-report', $empty, '--empty-sha256', hash_file('sha256', $empty),
            '--populated-report', $populated, '--populated-sha256', hash_file('sha256', $populated),
            '--output', $this->root . '/money-comparison.json',
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('unexplained semantic difference', $result['stderr']);

        $changed = $this->rehearsalReport('populated', [
            'customer:reused' => 1,
            'product:created' => 1,
            'product:reused' => 1,
        ], 1, 2);
        $changed['candidate_zip_sha256'] = str_repeat('f', 64);
        $this->writeJson($populated, $changed);
        $result = $this->runProcess([
            $this->scripts . '/compare-lapka-rehearsals.sh',
            '--empty-report', $empty, '--empty-sha256', hash_file('sha256', $empty),
            '--populated-report', $populated, '--populated-sha256', hash_file('sha256', $populated),
            '--output', $this->root . '/candidate-comparison.json',
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('unexplained semantic difference', $result['stderr']);

        $changed = $this->rehearsalReport('populated', [
            'customer:reused' => 1,
            'product:created' => 1,
        ], 1, 2);
        $this->writeJson($populated, $changed);
        $result = $this->runProcess([
            $this->scripts . '/compare-lapka-rehearsals.sh',
            '--empty-report', $empty, '--empty-sha256', hash_file('sha256', $empty),
            '--populated-report', $populated, '--populated-sha256', hash_file('sha256', $populated),
            '--output', $this->root . '/missing-action-comparison.json',
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('populated report contract is invalid', $result['stderr']);
    }

    public function testRollbackArgumentsCannotLeakIntoAForwardRehearsal(): void
    {
        $result = $this->runProcess([
            $this->scripts . '/run-lapka-rehearsal.sh', '--mode', 'empty', '--rollback-action', 'plan',
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('rollback-only arguments are forbidden', $result['stderr']);
    }

    public function testInstalledHarnessRefusesToSubstituteSourceForAMissingCandidateZip(): void
    {
        $state = $this->root . '/installed-state';
        file_put_contents($state, '');
        chmod($state, 0600);
        $result = $this->runProcess(
            [$this->scripts . '/create-disposable-stack.sh'],
            [
                'CARTSHIFT_CONTRACT_STATE_FILE' => $state,
                'CARTSHIFT_CANDIDATE_ZIP' => '',
                'CARTSHIFT_CANDIDATE_SHA256' => '',
                'CARTSHIFT_WOO_ZIP' => '',
                'CARTSHIFT_WOO_SHA256' => '',
                'CARTSHIFT_WCS_ZIP' => '',
                'CARTSHIFT_WCS_SHA256' => '',
                'CARTSHIFT_FLUENTCART_ZIP' => '',
                'CARTSHIFT_FLUENTCART_SHA256' => '',
            ],
        );

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('CARTSHIFT_CANDIDATE_ZIP is required', $result['stderr']);
    }

    public function testSharedLapkaAuditRunsInAOneOffContainerWithoutReplacingTheWebService(): void
    {
        $fakeBin = $this->directory('shared-audit-bin');
        $dockerLog = $this->root . '/shared-audit-docker.log';
        $docker = $fakeBin . '/docker';
        file_put_contents($docker, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%q ' "$@" >> "$FAKE_DOCKER_LOG"
printf '\n' >> "$FAKE_DOCKER_LOG"
if [ "${1:-}" = inspect ]; then
  printf '[{"State":{"Status":"running","Health":{"Status":"healthy"}},"Config":{"Env":["WP_ENVIRONMENT_TYPE=local"]},"Mounts":[]}]\n'
elif [[ " $* " == *" ps -q app-web "* ]]; then
  printf 'shared-app-web-id\n'
elif [[ " $* " == *" run "* ]]; then
  printf '{"status":"audited","writes":0}\n'
fi
BASH);
        chmod($docker, 0700);
        $compose = $this->file('shared-compose.yaml', "services:\n  app-web:\n    image: lapka-web\n");
        $override = $this->file('shared-audit.override.yaml', "services:\n  app-web:\n    environment:\n      CARTSHIFT_ZERO_WRITE_GUARD: '1'\n");

        $result = $this->runProcess([
            $this->scripts . '/run-shared-lapka-audit.sh',
            '--project', 'wesolalapka-local',
            '--compose-file', $compose,
            '--override-file', $override,
            '--', 'wp', '--allow-root', '--quiet', 'cartshift', 'transfer', 'audit', '--format=json',
        ], [
            'PATH' => $fakeBin . ':' . (string) getenv('PATH'),
            'FAKE_DOCKER_LOG' => $dockerLog,
        ]);

        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame("{\"status\":\"audited\",\"writes\":0}\n", $result['stdout']);
        $calls = (string) file_get_contents($dockerLog);
        self::assertStringContainsString(' run ', $calls);
        self::assertStringContainsString(' --rm ', $calls);
        self::assertStringContainsString(' --no-deps ', $calls);
        self::assertStringNotContainsString(' up ', $calls);
        self::assertStringNotContainsString(' exec ', $calls);
    }

    public function testSharedLapkaAuditAllowsOnlyKnownUnrelatedBootstrapPluginsToBeSkipped(): void
    {
        $fakeBin = $this->directory('shared-audit-skip-bin');
        $dockerLog = $this->root . '/shared-audit-skip-docker.log';
        $docker = $fakeBin . '/docker';
        file_put_contents($docker, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%q ' "$@" >> "$FAKE_DOCKER_LOG"
printf '\n' >> "$FAKE_DOCKER_LOG"
if [ "${1:-}" = inspect ]; then
  printf '[{"State":{"Status":"running","Health":{"Status":"healthy"}},"Config":{"Env":["WP_ENVIRONMENT_TYPE=local"]},"Mounts":[]}]\n'
elif [[ " $* " == *" ps -q app-web "* ]]; then
  printf 'shared-app-web-id\n'
elif [[ " $* " == *" run "* ]]; then
  printf '{"status":"audited","writes":0}\n'
fi
BASH);
        chmod($docker, 0700);
        $compose = $this->file('shared-skip-compose.yaml', "services:\n  app-web:\n    image: lapka-web\n");
        $override = $this->file('shared-skip-audit.override.yaml', "services:\n  app-web:\n    environment:\n      CARTSHIFT_ZERO_WRITE_GUARD: '1'\n");

        $allowed = $this->runProcess([
            $this->scripts . '/run-shared-lapka-audit.sh',
            '--project', 'wesolalapka-local',
            '--compose-file', $compose,
            '--override-file', $override,
            '--', 'wp', '--allow-root', '--quiet',
            '--skip-plugins=fluentformpro,paid-memberships-pro-3.6.2,fluent-player',
            'cartshift', 'transfer', 'audit', '--format=json',
        ], [
            'PATH' => $fakeBin . ':' . (string) getenv('PATH'),
            'FAKE_DOCKER_LOG' => $dockerLog,
        ]);

        self::assertSame(0, $allowed['status'], $allowed['stderr']);
        self::assertStringContainsString(
            '--skip-plugins=fluentformpro\\,paid-memberships-pro-3.6.2\\,fluent-player',
            (string) file_get_contents($dockerLog),
        );

        foreach (['cartshift', 'woocommerce', 'woocommerce-subscriptions', 'fluent-cart', 'fluentformpro,woocommerce'] as $pluginList) {
            $rejected = $this->runProcess([
                $this->scripts . '/run-shared-lapka-audit.sh',
                '--project', 'wesolalapka-local',
                '--compose-file', $compose,
                '--override-file', $override,
                '--', 'wp', '--allow-root', '--quiet', '--skip-plugins=' . $pluginList,
                'cartshift', 'transfer', 'audit', '--format=json',
            ], [
                'PATH' => $fakeBin . ':' . (string) getenv('PATH'),
                'FAKE_DOCKER_LOG' => $dockerLog,
            ]);

            self::assertNotSame(0, $rejected['status'], $pluginList);
            self::assertStringContainsString('only CartShift transfer inspection commands are allowed', $rejected['stderr']);
        }
    }

    public function testSharedLapkaAuditFailsIfTheLongLivedWebContainerChangesDuringTheOneOffRun(): void
    {
        $fakeBin = $this->directory('changed-shared-audit-bin');
        $dockerState = $this->root . '/changed-shared-audit.state';
        file_put_contents($dockerState, "0\n");
        $docker = $fakeBin . '/docker';
        file_put_contents($docker, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
if [ "${1:-}" = inspect ]; then
  printf '[{"State":{"Status":"running","Health":{"Status":"healthy"}},"Config":{"Env":["WP_ENVIRONMENT_TYPE=local"]},"Mounts":[]}]\n'
elif [[ " $* " == *" ps -q app-web "* ]]; then
  count="$(cat "$FAKE_DOCKER_STATE")"
  if [ "$count" = 0 ]; then printf 'shared-before\n'; else printf 'shared-after\n'; fi
  printf '%s\n' "$((count + 1))" > "$FAKE_DOCKER_STATE"
elif [[ " $* " == *" run "* ]]; then
  printf '{"status":"audited","writes":0}\n'
fi
BASH);
        chmod($docker, 0700);
        $compose = $this->file('changed-shared-compose.yaml', "services:\n  app-web:\n    image: lapka-web\n");
        $override = $this->file('changed-shared-audit.override.yaml', "services:\n  app-web:\n    environment:\n      CARTSHIFT_ZERO_WRITE_GUARD: '1'\n");

        $result = $this->runProcess([
            $this->scripts . '/run-shared-lapka-audit.sh',
            '--project', 'wesolalapka-local',
            '--compose-file', $compose,
            '--override-file', $override,
            '--', 'wp', '--allow-root', '--quiet', 'cartshift', 'transfer', 'audit', '--format=json',
        ], [
            'PATH' => $fakeBin . ':' . (string) getenv('PATH'),
            'FAKE_DOCKER_STATE' => $dockerState,
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('shared app-web service changed', $result['stderr']);
    }

    public function testSharedLapkaAuditRefusesAnAlreadyContaminatedWebContainerBeforeRunningWp(): void
    {
        $fakeBin = $this->directory('contaminated-shared-audit-bin');
        $dockerLog = $this->root . '/contaminated-shared-audit.log';
        $docker = $fakeBin . '/docker';
        file_put_contents($docker, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%q ' "$@" >> "$FAKE_DOCKER_LOG"
printf '\n' >> "$FAKE_DOCKER_LOG"
if [ "${1:-}" = inspect ]; then
  printf '[{"State":{"Status":"running","Health":{"Status":"healthy"}},"Config":{"Env":["CARTSHIFT_ZERO_WRITE_GUARD=1"]},"Mounts":[{"Source":"/fixture/artifacts/cartshift-evidence/run/mu-plugins","Destination":"/var/www/html/wp-content/mu-plugins"}]}]\n'
elif [[ " $* " == *" ps -q app-web "* ]]; then
  printf 'contaminated-shared-app-web\n'
elif [[ " $* " == *" run "* ]]; then
  printf '{"status":"audited","writes":0}\n'
fi
BASH);
        chmod($docker, 0700);
        $compose = $this->file('contaminated-shared-compose.yaml', "services:\n  app-web:\n    image: lapka-web\n");
        $override = $this->file('contaminated-shared-audit.override.yaml', "services:\n  app-web:\n    environment:\n      CARTSHIFT_ZERO_WRITE_GUARD: '1'\n");

        $result = $this->runProcess([
            $this->scripts . '/run-shared-lapka-audit.sh',
            '--project', 'wesolalapka-local',
            '--compose-file', $compose,
            '--override-file', $override,
            '--', 'wp', '--allow-root', '--quiet', 'cartshift', 'transfer', 'audit', '--format=json',
        ], [
            'PATH' => $fakeBin . ':' . (string) getenv('PATH'),
            'FAKE_DOCKER_LOG' => $dockerLog,
        ]);

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('already contaminated', $result['stderr']);
        self::assertStringNotContainsString(' run ', (string) file_get_contents($dockerLog));
    }

    /** @return array{list<string>,array<string,string>,array<string,mixed>} */
    private function isolationFixture(): array
    {
        $project = 'cartshift-lapka-empty-test123';
        file_put_contents($this->root . '/.cartshift-lapka-fixture', $project . "\n");
        chmod($this->root . '/.cartshift-lapka-fixture', 0600);
        $evidence = $this->directory('evidence');
        $package = $this->directory('package');
        $artifacts = $this->directory('artifacts');
        $this->directory('candidate');
        $candidate = $this->directory('candidate/cartshift');
        file_put_contents($candidate . '/cartshift.php', "<?php\n");
        chmod($candidate . '/cartshift.php', 0600);
        $compose = $this->file('compose.yaml', "services: {}\n");
        $fakeBin = $this->directory('bin');
        $fakeConfig = $this->root . '/rendered.json';
        $docker = $fakeBin . '/docker';
        file_put_contents($docker, "#!/usr/bin/env bash\nset -eu\ncat \"\$FAKE_CONFIG\"\n");
        chmod($docker, 0700);
        $candidateMount = [
            'type' => 'bind',
            'source' => $candidate,
            'target' => '/var/www/html/wp-content/plugins/cartshift',
            'read_only' => true,
        ];
        $wordpressService = [
            'environment' => ['WORDPRESS_DB_HOST' => 'db', 'WORDPRESS_HOME' => 'http://example.invalid'],
            'ports' => [['published' => '0', 'host_ip' => '127.0.0.1']],
            'volumes' => [$candidateMount, ['type' => 'bind', 'source' => $artifacts, 'target' => '/fixture-artifacts', 'read_only' => true]],
        ];
        $config = [
            'name' => $project,
            'services' => [
                'source-cli' => $wordpressService,
                'target-cli' => $wordpressService,
                'source-wordpress' => $wordpressService,
                'target-wordpress' => $wordpressService,
                'db' => ['environment' => []],
            ],
            'networks' => ['isolated' => ['name' => $project . '_isolated', 'internal' => true, 'external' => false]],
            'volumes' => ['data' => ['name' => $project . '_data', 'external' => false]],
        ];
        return [[
            '--project', $project, '--compose-file', $compose, '--fixture-root', $this->root,
            '--evidence-dir', $evidence, '--package-dir', $package,
            '--candidate-dir', $candidate,
        ], [
            'PATH' => $fakeBin . ':' . (string) getenv('PATH'),
            'FAKE_CONFIG' => $fakeConfig,
            'TMPDIR' => dirname($this->root),
        ], $config];
    }

    private function baseline(string $role, string $sql, string $archive, string $prefix): string
    {
        $path = $this->root . '/' . $role . '-baseline.json';
        $this->writeJson($path, [
            'version' => 1, 'role' => $role, 'backup_sha256' => hash_file('sha256', $sql),
            'wp_content_sha256' => hash_file('sha256', $archive), 'table_prefix' => $prefix,
            'table_counts' => (object) [], 'table_checksums' => (object) [],
        ]);
        return $path;
    }

    /**
     * @param array<string,int> $actions
     * @return array<string,mixed>
     */
    private function rehearsalReport(string $mode, array $actions, int $created, int $reused): array
    {
        $hashes = [
            'package_manifest_sha256' => str_repeat('1', 64),
            'decision_set_sha256' => str_repeat('2', 64),
            'candidate_zip_sha256' => str_repeat('7', 64),
            'candidate_tree_sha256' => str_repeat('8', 64),
            'selection_fingerprint' => str_repeat('3', 64),
            'approval_reference' => str_repeat('4', 64),
        ];
        $receiptCounts = ['customer' => 1, 'product' => 2];

        return [
            'status' => 'passed',
            'mode' => $mode,
            ...$hashes,
            'record_counts' => $receiptCounts,
            'final_status' => ['state' => 'completed', 'receipt_counts' => $receiptCounts],
            'outcomes' => ['selected' => 3, 'created' => $created, 'reused' => $reused, 'blocked' => 0],
            'receipt_action_counts' => $actions,
            'semantic_receipts' => [
                'lapka-web:customer:20' => ['record_kind' => 'customer', 'source_fingerprint' => str_repeat('b', 64)],
                'lapka-web:product:10' => ['record_kind' => 'product', 'source_fingerprint' => str_repeat('a', 64)],
                'lapka-web:product:11' => ['record_kind' => 'product', 'source_fingerprint' => str_repeat('c', 64)],
            ],
            'map_counts' => $receiptCounts,
            'money' => ['order_count' => 0, 'total_amount' => 0],
            'spies' => ['lifecycle_event' => 0, 'mail_attempt' => 0, 'outbound_http_attempt' => 0],
            'outbox_rows' => 3,
            'blocking_findings' => 0,
            'dangling_maps' => 0,
            'target_table_deltas' => (object) [],
            'target_files' => ['added' => (object) [], 'changed' => (object) [], 'removed' => (object) []],
            'expectations_sha256' => str_repeat($mode === 'empty' ? '5' : '6', 64),
        ];
    }

    private function archive(string $name): string
    {
        $source = $this->directory('archive-' . $name);
        mkdir($source . '/wp-content', 0700);
        file_put_contents($source . '/wp-content/index.php', "<?php\n");
        $path = $this->root . '/' . $name;
        $status = 0;
        exec(sprintf('tar -cf %s -C %s wp-content', escapeshellarg($path), escapeshellarg($source)), $ignored, $status);
        self::assertSame(0, $status);
        chmod($path, 0600);
        return $path;
    }

    private function candidateArchive(string $name): string
    {
        $source = $this->directory('archive-' . $name);
        mkdir($source . '/cartshift', 0700);
        file_put_contents($source . '/cartshift/cartshift.php', "<?php\n/* Plugin Name: CartShift */\n");
        $path = $this->root . '/' . $name;
        $status = 0;
        exec(sprintf('cd %s && zip -q -X -r %s cartshift', escapeshellarg($source), escapeshellarg($path)), $ignored, $status);
        self::assertSame(0, $status);
        chmod($path, 0600);
        return $path;
    }

    private function directory(string $name): string
    {
        $path = $this->root . '/' . $name;
        mkdir($path, 0700);
        return $path;
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, $contents);
        chmod($path, 0600);
        return $path;
    }

    /** @param array<string,mixed> $document */
    private function writeJson(string $path, array $document): void
    {
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        chmod($path, 0600);
    }

    /** @param list<string> $command @param array<string,string> $environment */
    private function runProcess(array $command, array $environment = []): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [...$_ENV, ...$environment]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) return;
        $items = scandir($path);
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $child = $path . '/' . $item;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
