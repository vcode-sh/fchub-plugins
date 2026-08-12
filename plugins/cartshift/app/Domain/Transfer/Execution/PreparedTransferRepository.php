<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class PreparedTransferRepository
{
    private string $directory;

    public function __construct(string $privateDirectory)
    {
        $this->directory = PrivateTransferFile::directory($privateDirectory);
    }

    public function save(PreparedTransfer $prepared): string
    {
        $document = CanonicalJson::encode([
            'descriptor_hash' => $prepared->descriptorHash(),
            'descriptor' => $prepared->toArray(),
        ]) . "\n";
        return PrivateTransferFile::writeImmutable(
            $this->directory,
            'prepared-' . $prepared->runId . '.json',
            $document,
            'prepared_transfer_immutable_conflict',
        );
    }

    public function get(string $runId): PreparedTransfer
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1) {
            throw new \InvalidArgumentException('Prepared run ID is invalid.');
        }
        $path = $this->directory . '/prepared-' . $runId . '.json';
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('prepared_transfer_not_found');
        $bytes = file_get_contents($path);
        $data = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data) || array_keys($data) !== ['descriptor', 'descriptor_hash'] || !is_array($data['descriptor']) || !is_string($data['descriptor_hash'])) {
            throw new \RuntimeException('prepared_transfer_descriptor_invalid');
        }
        $prepared = PreparedTransfer::fromArray($data['descriptor']);
        if (!hash_equals($prepared->descriptorHash(), $data['descriptor_hash'])) {
            throw new \RuntimeException('prepared_transfer_descriptor_hash_mismatch');
        }
        return $prepared;
    }
}
