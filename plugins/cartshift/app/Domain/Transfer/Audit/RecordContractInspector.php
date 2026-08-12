<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

final class RecordContractInspector implements SourceRecordContractInspector
{
    private readonly \Closure $attemptReader;

    /**
     * @param callable(TransferSelection): iterable<array{identity: SourceIdentity, assert: callable(): void}> $attemptReader
     */
    public function __construct(callable $attemptReader)
    {
        $this->attemptReader = $attemptReader(...);
    }

    public function inspect(TransferSelection $selection): SourceRecordContractReport
    {
        $findings = [];
        $counts = [];
        foreach (($this->attemptReader)($selection) as $attempt) {
            $identity = $attempt['identity'] ?? null;
            $assert = $attempt['assert'] ?? null;
            if (!$identity instanceof SourceIdentity || !is_callable($assert)) {
                throw new \LogicException('A record-contract attempt is malformed.');
            }
            if ($identity->sourceKey !== $selection->sourceKey) {
                throw new \LogicException('A record-contract attempt belongs to another source key.');
            }
            $kind = $identity->entityType;
            $counts[$kind] ??= ['considered' => 0, 'ready' => 0, 'blocked' => 0];
            ++$counts[$kind]['considered'];
            try {
                $assert();
                ++$counts[$kind]['ready'];
            } catch (SourceRecordException $exception) {
                ++$counts[$kind]['blocked'];
                $findings[] = $this->finding($identity, $exception->reasonCode, $exception);
            } catch (\Throwable $exception) {
                ++$counts[$kind]['blocked'];
                $findings[] = $this->finding($identity, 'record_contract_inspection_failed', $exception);
            }
        }
        ksort($counts, SORT_STRING);
        usort($findings, static fn (array $left, array $right): int => [$left['code'], $left['identity']]
            <=> [$right['code'], $right['identity']]);

        return new SourceRecordContractReport($counts, $findings);
    }

    /** @return array{code: string, identity: string, context: array<string, scalar|null>} */
    private function finding(SourceIdentity $identity, string $code, \Throwable $exception): array
    {
        return [
            'code' => $code,
            'identity' => $identity->canonical(),
            'context' => [
                'diagnostic_fingerprint' => hash('sha256', get_class($exception) . "\0" . $exception->getMessage()),
            ],
        ];
    }
}
