<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

defined('ABSPATH') || exit;

interface WooStorageIntegrityReader
{
    /** @return list<array{code: string, identity: string, context: array<string, scalar|null>}> */
    public function inspect(string $sourceKey): array;
}
