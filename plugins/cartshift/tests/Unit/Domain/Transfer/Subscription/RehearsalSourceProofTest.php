<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Subscription\RehearsalSourceProof;
use CartShift\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class RehearsalSourceProofTest extends TestCase
{
    private string $root;
    private string $descriptor = 'tr-1234567890abcdef12345678';
    private string $production;
    private string $isolated;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('CARTSHIFT_REHEARSAL_ISOLATED')) define('CARTSHIFT_REHEARSAL_ISOLATED', true);
        $this->root = sys_get_temp_dir() . '/cartshift-rehearsal-proof-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->production = str_repeat('a', 64);
        $this->isolated = str_repeat('b', 64);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $path) unlink($path);
        rmdir($this->root);
        parent::tearDown();
    }

    public function testExactPrivateRestoreProofBridgesOnlyTheIsolatedInstanceIdentity(): void
    {
        $proof = $this->proof();

        self::assertSame($this->production, RehearsalSourceProof::assertAndResolve(
            $proof, $this->root, $this->descriptor, $this->production, $this->isolated,
        ));
    }

    public function testChangedRestoreReportIsRejected(): void
    {
        $proof = $this->proof();
        file_put_contents($this->root . '/cartshift-lapka-empty-test123-restore.json', "{}\n");

        $this->expectExceptionMessage('rehearsal_source_proof_restore_report_changed');
        RehearsalSourceProof::assertAndResolve($proof, $this->root, $this->descriptor, $this->production, $this->isolated);
    }

    public function testProductionOrIsolatedIdentityDriftIsRejected(): void
    {
        $proof = $this->proof();

        $this->expectExceptionMessage('rehearsal_source_proof_identity_changed');
        RehearsalSourceProof::assertAndResolve($proof, $this->root, $this->descriptor, str_repeat('c', 64), $this->isolated);
    }

    public function testProofOutsideTheConfiguredPrivateDirectoryIsRejected(): void
    {
        $proof = $this->proof();
        $outside = dirname($this->root) . '/cartshift-outside-proof-' . bin2hex(random_bytes(4)) . '.json';
        rename($proof, $outside);
        chmod($outside, 0600);
        try {
            $this->expectExceptionMessage('rehearsal_source_proof_not_private');
            RehearsalSourceProof::assertAndResolve($outside, $this->root, $this->descriptor, $this->production, $this->isolated);
        } finally {
            unlink($outside);
        }
    }

    private function proof(): string
    {
        $project = 'cartshift-lapka-empty-test123';
        $backup = str_repeat('d', 64);
        $reportName = $project . '-restore.json';
        $report = CanonicalJson::encode([
            'cron' => false, 'mail' => false, 'outbound_network' => false, 'payment_gateways' => false,
            'project' => $project, 'source_backup_sha256' => $backup, 'status' => 'restored',
        ]) . "\n";
        file_put_contents($this->root . '/' . $reportName, $report);
        chmod($this->root . '/' . $reportName, 0600);
        $proof = CanonicalJson::encode([
            'descriptor' => $this->descriptor,
            'isolated_source_instance_fingerprint' => $this->isolated,
            'production_source_instance_fingerprint' => $this->production,
            'project' => $project,
            'restore_report' => $reportName,
            'restore_report_sha256' => hash('sha256', $report),
            'source_backup_sha256' => $backup,
            'version' => 1,
        ]) . "\n";
        $path = $this->root . '/proof.json';
        file_put_contents($path, $proof);
        chmod($path, 0600);
        return $path;
    }
}
