<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

defined('ABSPATH') || exit;

final class TransferPackagePath
{
    public static function destination(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            throw new \InvalidArgumentException('Package destination must be an absolute real directory.');
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real) || !is_writable($real)) {
            throw new \InvalidArgumentException('Package destination is unavailable.');
        }
        chmod($real, 0700);
        return $real;
    }

    public static function completedName(string $sourceKey, string $selectionFingerprint, string $recordsSha256): string
    {
        \CartShift\Domain\Transfer\SourceIdentity::assertValidSourceKey($sourceKey);
        foreach ([$selectionFingerprint, $recordsSha256] as $hash) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Package name fingerprint is invalid.');
            }
        }
        return 'cartshift-transfer-v2-' . $sourceKey . '-' . $selectionFingerprint . '-' . $recordsSha256;
    }
}
