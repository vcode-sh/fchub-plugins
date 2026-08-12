<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\RecordEnvelope;

defined('ABSPATH') || exit;

final readonly class TransferPackageReader
{
    private TransferManifest $validatedManifest;

    public function __construct(private string $path, private TransferPackageValidator $validator)
    {
        $this->validatedManifest = $validator->assertValid($path);
    }

    public function manifest(): TransferManifest
    {
        return $this->validatedManifest;
    }

    /** @return \Generator<int, RecordEnvelope> */
    public function records(): \Generator
    {
        $this->validator->assertValid($this->path);
        $stream = fopen($this->path . '/records.ndjson', 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Transfer record stream cannot be opened.');
        }
        try {
            while (($line = fgets($stream)) !== false) {
                $data = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new \RuntimeException('Transfer record row is invalid.');
                }
                yield TransferPackageValidator::hydrateRecord($data);
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    public function openAsset(string $sha256): mixed
    {
        $this->validator->assertValid($this->path);
        if (!isset($this->validatedManifest->assetSha256[$sha256])) {
            throw new \InvalidArgumentException('Asset is not present in the validated manifest.');
        }
        $stream = fopen($this->path . '/assets/' . $sha256, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Validated asset cannot be opened.');
        }
        return $stream;
    }
}
