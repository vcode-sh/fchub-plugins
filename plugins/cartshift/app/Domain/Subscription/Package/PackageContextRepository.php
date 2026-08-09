<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Package;

defined('ABSPATH') || exit;

/**
 * Where a prepared package is, and what it was when it was prepared.
 *
 * Four strings per source key: the key, the absolute private path, the
 * records checksum, and the selection fingerprint. That is the entire
 * descriptor, and the shortness is the design. The mapping screen needs to
 * find a package's `ProductRecord` candidates across requests, which requires
 * knowing where the file is; it does not require a copy of the file, and it
 * emphatically does not require any commerce data to be written before the
 * operator has agreed to anything.
 *
 * This is the only thing in the subscription audit path that writes at all.
 * `audit`, `export`, `validate-package` and the whole live source touch it
 * never — an audit that quietly persisted its own findings would be a
 * different command wearing the word "read-only".
 */
final class PackageContextRepository
{
    private const string OPTION_KEY = 'cartshift_subscription_packages';

    /**
     * Remember one prepared package, replacing any previous one for that key.
     *
     * A source key holds at most one package. Two files claiming to be the same
     * source is not a state the cutover can reason about: the receipts, the
     * ID-map rows and the selection fingerprint all key on the source, so a
     * second file would only ever be a chance to stage the wrong one.
     */
    public function remember(
        string $sourceKey,
        string $path,
        string $checksum,
        string $selectionFingerprint,
    ): void {
        $stored = $this->all();

        $stored[$sourceKey] = self::descriptor($sourceKey, $path, $checksum, $selectionFingerprint);

        ksort($stored);

        update_option(self::OPTION_KEY, $stored, false);
    }

    /**
     * @return array<string, string>|null
     */
    public function get(string $sourceKey): ?array
    {
        $stored = $this->all();

        return $stored[$sourceKey] ?? null;
    }

    /**
     * The descriptor whose path matches, if there is one.
     *
     * `delete-package` needs this: it is handed a path and may only act when
     * that exact path is the one somebody prepared. Anything else is `rm` with
     * branding.
     *
     * @return array<string, string>|null
     */
    public function findByPath(string $path): ?array
    {
        foreach ($this->all() as $descriptor) {
            if (($descriptor['path'] ?? '') === $path) {
                return $descriptor;
            }
        }

        return null;
    }

    public function forget(string $sourceKey): bool
    {
        $stored = $this->all();

        if (!isset($stored[$sourceKey])) {
            return false;
        }

        unset($stored[$sourceKey]);

        update_option(self::OPTION_KEY, $stored, false);

        return true;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            return [];
        }

        $descriptors = [];

        foreach ($stored as $sourceKey => $descriptor) {
            if (!is_array($descriptor)) {
                continue;
            }

            $descriptors[(string) $sourceKey] = self::descriptor(
                (string) $sourceKey,
                (string) ($descriptor['path'] ?? ''),
                (string) ($descriptor['checksum'] ?? ''),
                (string) ($descriptor['selection_fingerprint'] ?? ''),
            );
        }

        ksort($descriptors);

        return $descriptors;
    }

    /**
     * @return array<string, string>
     */
    private static function descriptor(
        string $sourceKey,
        string $path,
        string $checksum,
        string $selectionFingerprint,
    ): array {
        // Sorted keys, because this ends up inside a fingerprinted document and
        // key order is an accident of assembly.
        return [
            'checksum'              => $checksum,
            'path'                  => $path,
            'selection_fingerprint' => $selectionFingerprint,
            'source_key'            => $sourceKey,
        ];
    }
}
