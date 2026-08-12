<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Runtime;

defined('ABSPATH') || exit;

interface TransferRuntimeSymbols
{
    public function functionExists(string $function): bool;

    public function classExists(string $class): bool;

    public function methodExists(string $class, string $method): bool;

    public function constantValue(string $constant): ?string;

    /** @return list<string> */
    public function modelFillable(string $class): array;

    /** @return array<string, string> */
    public function modelCasts(string $class): array;

    public function runtimeVersion(string $component): ?string;

    public function runtimeDigest(string $component): ?string;
}
