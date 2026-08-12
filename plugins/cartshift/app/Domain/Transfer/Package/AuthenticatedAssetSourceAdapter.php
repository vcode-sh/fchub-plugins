<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

defined('ABSPATH') || exit;

/**
 * Opens a private source without leaking its authentication material into a
 * transfer bundle. Implementations own credential retrieval and renewal.
 */
interface AuthenticatedAssetSourceAdapter
{
    /** @return resource */
    public function open(string $locator): mixed;

    public function originalName(string $locator): string;
}
