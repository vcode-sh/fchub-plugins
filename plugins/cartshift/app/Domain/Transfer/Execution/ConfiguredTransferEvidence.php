<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

final class ConfiguredTransferEvidence
{
    public const string PRIVATE_DIRECTORY = 'CARTSHIFT_TRANSFER_PRIVATE_DIR';
    public const string CUTOVER_APPROVAL = 'CARTSHIFT_TRANSFER_CUTOVER_APPROVAL';
    public const string CUTOVER_MANIFEST = 'CARTSHIFT_TRANSFER_CUTOVER_MANIFEST';
    public const string OPERATOR_ID = 'CARTSHIFT_TRANSFER_OPERATOR_ID';

    public static function privateDirectory(): string
    {
        $value = self::value(self::PRIVATE_DIRECTORY);
        if ($value === null) {
            throw new \RuntimeException('transfer_private_directory_not_configured');
        }
        return PrivateTransferFile::directory($value);
    }

    public static function assertCutoverApproval(string $provided): CutoverApprovalManifest
    {
        $approved = self::value(self::CUTOVER_APPROVAL);
        $path = self::value(self::CUTOVER_MANIFEST);
        if ($approved === null
            || $path === null
            || preg_match('/\A[a-f0-9]{64}\z/D', $approved) !== 1
            || !hash_equals($approved, $provided)) {
            throw new \RuntimeException('cutover_approval_not_configured_or_changed');
        }
        $manifest = CutoverApprovalManifest::fromFile($path);
        $digest = hash_file('sha256', $path);
        if (!is_string($digest) || !hash_equals($approved, $digest)) {
            throw new \RuntimeException('cutover_approval_not_configured_or_changed');
        }
        if (!hash_equals($manifest->operatorId, self::operatorId())) {
            throw new \RuntimeException('cutover_approval_operator_changed');
        }
        return $manifest;
    }

    public static function operatorId(): string
    {
        $value = self::value(self::OPERATOR_ID);
        if ($value === null
            || strlen($value) > 64
            || preg_match('/\A[a-zA-Z0-9._:-]+\z/D', $value) !== 1) {
            throw new \RuntimeException('transfer_operator_id_not_configured_or_invalid');
        }
        return $value;
    }

    private static function value(string $name): ?string
    {
        if (defined($name)) {
            $value = constant($name);
            return is_string($value) && $value !== '' ? $value : null;
        }
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
