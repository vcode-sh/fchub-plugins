<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class TargetStateFingerprint
{
    public function __construct(
        public string $packageHash,
        public string $decisionHash,
        public string $compatibilityHash,
        public string $settingsHash,
        public string $gatewayHash,
        public string $selectionHash,
        public string $targetHash,
    ) {
        foreach ($this->toArray() as $hash) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Prepared transfer fingerprints must be lowercase SHA-256 values.');
            }
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'package' => $this->packageHash,
            'decision' => $this->decisionHash,
            'compatibility' => $this->compatibilityHash,
            'settings' => $this->settingsHash,
            'gateway' => $this->gatewayHash,
            'selection' => $this->selectionHash,
            'target' => $this->targetHash,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['compatibility', 'decision', 'gateway', 'package', 'selection', 'settings', 'target']) {
            throw new \InvalidArgumentException('Prepared target-state fingerprint shape is invalid.');
        }
        return new self(
            (string) $data['package'],
            (string) $data['decision'],
            (string) $data['compatibility'],
            (string) $data['settings'],
            (string) $data['gateway'],
            (string) $data['selection'],
            (string) $data['target'],
        );
    }

    public function fingerprint(): string
    {
        return CanonicalJson::fingerprint($this->toArray());
    }

    public function changedField(self $current): ?string
    {
        foreach ($this->toArray() as $field => $expected) {
            if (!hash_equals($expected, $current->toArray()[$field])) {
                return $field;
            }
        }
        return null;
    }

    public function withTargetHash(string $targetHash): self
    {
        return new self(
            $this->packageHash,
            $this->decisionHash,
            $this->compatibilityHash,
            $this->settingsHash,
            $this->gatewayHash,
            $this->selectionHash,
            $targetHash,
        );
    }
}
