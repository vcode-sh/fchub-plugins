<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class SourceInventoryReport
{
    /**
     * @param array<string, int> $counts
     * @param array<string, array<string, int>> $capabilities
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $blockers
     */
    public function __construct(
        public string $sourceKey,
        public string $selectionFingerprint,
        public string $runtimeFingerprint,
        public array $counts,
        public array $capabilities,
        public array $blockers,
        public string $fingerprint,
    ) {
        $expected = self::calculateFingerprint(
            $sourceKey,
            $selectionFingerprint,
            $runtimeFingerprint,
            $counts,
            $capabilities,
            $blockers,
        );

        if (!hash_equals($expected, $fingerprint)) {
            throw new \InvalidArgumentException('Source inventory fingerprint does not match the report.');
        }
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, array<string, int>> $capabilities
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $blockers
     */
    public static function create(
        string $sourceKey,
        string $selectionFingerprint,
        string $runtimeFingerprint,
        array $counts,
        array $capabilities,
        array $blockers,
    ): self {
        return new self(
            $sourceKey,
            $selectionFingerprint,
            $runtimeFingerprint,
            $counts,
            $capabilities,
            $blockers,
            self::calculateFingerprint(
                $sourceKey,
                $selectionFingerprint,
                $runtimeFingerprint,
                $counts,
                $capabilities,
                $blockers,
            ),
        );
    }

    public function isReady(): bool
    {
        return $this->blockers === []
            && ($this->counts['product_duplicates'] ?? 0) === 0
            && ($this->counts['products_unaccounted'] ?? 0) === 0;
    }

    /** @param array<string, int> $counts @param array<string, array<string, int>> $capabilities @param list<array<string, mixed>> $blockers */
    private static function calculateFingerprint(
        string $sourceKey,
        string $selectionFingerprint,
        string $runtimeFingerprint,
        array $counts,
        array $capabilities,
        array $blockers,
    ): string {
        return CanonicalJson::fingerprint([
            'source_key' => $sourceKey,
            'selection_fingerprint' => $selectionFingerprint,
            'runtime_fingerprint' => $runtimeFingerprint,
            'counts' => $counts,
            'capabilities' => $capabilities,
            'blockers' => $blockers,
        ]);
    }
}
