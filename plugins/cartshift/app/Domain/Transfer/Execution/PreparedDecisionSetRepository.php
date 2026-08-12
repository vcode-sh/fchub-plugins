<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;

defined('ABSPATH') || exit;

final readonly class PreparedDecisionSetRepository
{
    private string $directory;

    public function __construct(string $privateDirectory)
    {
        $this->directory = PrivateTransferFile::directory($privateDirectory);
    }

    public function save(string $runId, TransferDecisionSet $decisions): string
    {
        self::assertRunId($runId);
        return PrivateTransferFile::writeImmutable(
            $this->directory,
            'decisions-' . $runId . '.json',
            $decisions->canonicalJson(),
            'prepared_decision_set_immutable_conflict',
        );
    }

    public function get(string $runId): TransferDecisionSet
    {
        self::assertRunId($runId);
        return TransferDecisionSet::fromFile($this->directory . '/decisions-' . $runId . '.json');
    }

    private static function assertRunId(string $runId): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1) {
            throw new \InvalidArgumentException('Prepared decision-set run ID is invalid.');
        }
    }
}
