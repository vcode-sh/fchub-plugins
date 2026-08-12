<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class SelectionFingerprint
{
    /** @param array<string, mixed> $canonicalSelection */
    public static function fromCanonical(array $canonicalSelection): string
    {
        return CanonicalJson::fingerprint(['root_selection' => $canonicalSelection]);
    }
}
