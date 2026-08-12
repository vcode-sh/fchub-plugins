<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class TransferAuditReport
{
    private const string SENSITIVE_CONTEXT_KEY = '/(?:email|name|address|phone|transaction|vendor|url|path|file|token|secret|credential)/i';

    /**
     * @param array<string, int> $counts
     * @param array<string, array<string, int>> $capabilities
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $blockers
     */
    private function __construct(
        public string $sourceKey,
        public string $selectionFingerprint,
        public string $decisionFingerprint,
        public string $runtimeFingerprint,
        public string $auditFingerprint,
        public bool $ready,
        public array $counts,
        public array $capabilities,
        public array $blockers,
    ) {}

    /**
     * @param array<string, int> $counts
     * @param array<string, array<string, int>> $capabilities
     * @param list<array{code: string, identity: string, context: array<string, mixed>}> $blockers
     */
    public static function create(
        string $sourceKey,
        string $selectionFingerprint,
        string $runtimeFingerprint,
        bool $ready,
        array $counts,
        array $capabilities,
        array $blockers,
        ?string $decisionFingerprint = null,
    ): self {
        $decisionFingerprint ??= CanonicalJson::fingerprint(['decisions' => []]);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $decisionFingerprint) !== 1) {
            throw new \InvalidArgumentException('Audit decision fingerprint is invalid.');
        }
        $blockers = self::sanitiseBlockers($blockers);
        usort(
            $blockers,
            static fn (array $left, array $right): int => [$left['code'], $left['identity']]
                <=> [$right['code'], $right['identity']],
        );
        $ready = $ready && $blockers === [];
        $public = [
            'source_key' => $sourceKey,
            'selection_fingerprint' => $selectionFingerprint,
            'decision_fingerprint' => $decisionFingerprint,
            'runtime_fingerprint' => $runtimeFingerprint,
            'ready' => $ready,
            'counts' => $counts,
            'capabilities' => $capabilities,
            'blockers' => $blockers,
        ];

        return new self(
            $sourceKey,
            $selectionFingerprint,
            $decisionFingerprint,
            $runtimeFingerprint,
            CanonicalJson::fingerprint($public),
            $ready,
            $counts,
            $capabilities,
            $blockers,
        );
    }

    /** @param list<array{code: string, identity: string, context: array<string, mixed>}> $blockers @return list<array{code: string, identity: string, context: array<string, scalar|null>}> */
    private static function sanitiseBlockers(array $blockers): array
    {
        $safe = [];

        foreach ($blockers as $blocker) {
            $context = [];

            foreach ((array) ($blocker['context'] ?? []) as $key => $value) {
                if (!is_string($key) || preg_match(self::SENSITIVE_CONTEXT_KEY, $key) === 1) {
                    continue;
                }

                if (is_scalar($value) || $value === null) {
                    $context[$key] = $value;
                }
            }

            ksort($context);
            $safe[] = [
                'code' => (string) ($blocker['code'] ?? 'runtime_contract_mismatch'),
                'identity' => (string) ($blocker['identity'] ?? 'unknown'),
                'context' => $context,
            ];
        }

        return $safe;
    }
}
