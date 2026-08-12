<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class PreparedTargetBaselineRepository
{
    private string $directory;

    public function __construct(string $privateDirectory)
    {
        $this->directory = PrivateTransferFile::directory($privateDirectory);
    }

    public function save(string $runId, PreparedTargetBaseline $baseline): string
    {
        self::assertRunId($runId);
        return PrivateTransferFile::writeImmutable(
            $this->directory,
            'target-baseline-' . $runId . '.json',
            CanonicalJson::encode([
                'baseline' => $baseline->toArray(),
                'baseline_hash' => $baseline->fingerprint(),
            ]) . "\n",
            'prepared_target_baseline_immutable_conflict',
        );
    }

    public function get(string $runId): PreparedTargetBaseline
    {
        self::assertRunId($runId);
        $path = $this->directory . '/target-baseline-' . $runId . '.json';
        $bytes = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        $data = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data) || array_keys($data) !== ['baseline', 'baseline_hash']
            || !is_array($data['baseline']) || !is_string($data['baseline_hash'])
            || !hash_equals(CanonicalJson::encode($data) . "\n", (string) $bytes)) {
            throw new \RuntimeException('prepared_target_baseline_invalid');
        }
        $baseline = PreparedTargetBaseline::fromArray($data['baseline']);
        if (!hash_equals($baseline->fingerprint(), $data['baseline_hash'])) {
            throw new \RuntimeException('prepared_target_baseline_hash_mismatch');
        }
        return $baseline;
    }

    private static function assertRunId(string $runId): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1) {
            throw new \InvalidArgumentException('Prepared target baseline run ID is invalid.');
        }
    }
}
