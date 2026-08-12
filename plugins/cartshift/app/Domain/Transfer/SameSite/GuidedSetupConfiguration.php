<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;
use CartShift\Domain\Transfer\Execution\PrivateTransferFile;

defined('ABSPATH') || exit;

/** Durable WordPress defaults for the guided route's existing evidence adapter. */
final class GuidedSetupConfiguration
{
    public const string OPTION = 'cartshift_guided_setup_v1';

    public function store(string $privateDirectory, string $operatorId): void
    {
        $configuration = $this->validate([
            'private_directory' => $privateDirectory,
            'operator_id' => $operatorId,
        ]);

        if (!add_option(self::OPTION, $configuration, '', false)) {
            $configuration = $this->storedOrRepair($configuration);
        }

        $this->apply($configuration);
    }

    /** Remove configuration created by a setup attempt that did not complete. */
    public function forget(): void
    {
        delete_option(self::OPTION);
    }

    /** Load saved defaults before the transfer pipelines are constructed. */
    public function boot(): void
    {
        $stored = get_option(self::OPTION, null);
        if ($stored === null) {
            return;
        }

        $this->apply($this->validate($stored));
    }

    /** @param array{private_directory:string,operator_id:string} $configuration */
    private function apply(array $configuration): void
    {
        $values = [
            ConfiguredTransferEvidence::PRIVATE_DIRECTORY => $configuration['private_directory'],
            ConfiguredTransferEvidence::OPERATOR_ID => $configuration['operator_id'],
        ];

        foreach ($values as $name => $value) {
            $current = getenv($name);
            if (!defined($name) && (!is_string($current) || $current === '')) {
                putenv($name . '=' . $value);
            }
        }
    }

    /** @return array{private_directory:string,operator_id:string} */
    private function validate(mixed $configuration): array
    {
        if (!is_array($configuration)
            || !is_string($configuration['private_directory'] ?? null)
            || !is_string($configuration['operator_id'] ?? null)
            || strlen($configuration['operator_id']) > 64
            || preg_match('/\A[a-zA-Z0-9._:-]+\z/D', $configuration['operator_id']) !== 1) {
            throw new \RuntimeException('guided_transfer_setup_invalid');
        }

        PrivateTransferFile::directory($configuration['private_directory']);

        return [
            'private_directory' => $configuration['private_directory'],
            'operator_id' => $configuration['operator_id'],
        ];
    }

    /**
     * @param array{private_directory:string,operator_id:string} $candidate
     * @return array{private_directory:string,operator_id:string}
     */
    private function storedOrRepair(array $candidate): array
    {
        $stored = get_option(self::OPTION, null);
        try {
            return $this->validate($stored);
        } catch (\Throwable $invalid) {
            $owner = $this->repairableOwner($stored);
            if ($owner === null) {
                throw $invalid;
            }

            $lock = GuidedSetupLock::acquire('repair');

            try {
                return $this->validate(get_option(self::OPTION, null));
            } catch (\Throwable) {
                $candidate['operator_id'] = $owner;
                update_option(self::OPTION, $candidate, false);

                return $this->validate(get_option(self::OPTION, null));
            }
        }
    }

    private function repairableOwner(mixed $configuration): ?string
    {
        if (!is_array($configuration)
            || !is_string($configuration['private_directory'] ?? null)
            || !is_string($configuration['operator_id'] ?? null)
            || strlen($configuration['operator_id']) > 64
            || preg_match('/\A[a-zA-Z0-9._:-]+\z/D', $configuration['operator_id']) !== 1) {
            return null;
        }

        return $configuration['operator_id'];
    }
}
