<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Runtime;

defined('ABSPATH') || exit;

interface TransferSchemaInspector
{
    /**
     * @param list<string> $tables Unprefixed WordPress table names.
     * @return array<string, array{
     *     engine: string,
     *     columns: array<string, array{type: string, nullable: bool, default: mixed, extra: string}>,
     *     indexes: array<string, array{unique: bool, columns: list<string>}>
     * }>
     */
    public function inspect(array $tables): array;
}
