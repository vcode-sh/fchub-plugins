<?php

namespace FChubHub\Catalogue;

defined('ABSPATH') || exit;

/**
 * Collects the optional `fchub/products` descriptors that active products may
 * publish about themselves, and throws away everything else.
 *
 * A descriptor may say where its settings live and how it is feeling. It may
 * not say what version it is, where to download it, what it requires, or where
 * its documentation lives — those come from the trusted catalogue and stay
 * there. Any descriptor that reaches for one of those fields is dropped whole,
 * on the grounds that it was probably not asking politely.
 */
final class DescriptorRegistry
{
    private const SCHEMA_VERSION = 1;

    private const KEYS = ['schema_version', 'plugin_file', 'admin_path', 'health'];

    private const HEALTH_KEYS = ['status', 'message'];

    private const HEALTH_STATUSES = ['healthy', 'attention', 'unknown'];

    /**
     * Health messages are a sentence, not an essay. Bounded for the same reason
     * the catalogue's free text is: it is the last unbounded string that would
     * otherwise reach the interface.
     */
    private const MAX_MESSAGE_LENGTH = 200;

    /**
     * @param array<string, mixed> $catalogue
     * @return array<string, array{admin_path: string|null, health: array{status: string, message: string|null}|null}>
     */
    public function collect(array $catalogue): array
    {
        $descriptors = apply_filters('fchub/products', []);
        $products = $catalogue['products'] ?? [];

        if (!is_array($descriptors) || !is_array($products)) {
            return [];
        }

        $accepted = [];

        foreach ($descriptors as $slug => $descriptor) {
            if (!is_string($slug) || !isset($products[$slug]) || !is_array($descriptor)) {
                continue;
            }

            $normalised = $this->normalise($descriptor, $products[$slug]);

            if ($normalised !== null) {
                $accepted[$slug] = $normalised;
            }
        }

        return $accepted;
    }

    /**
     * @param array<int|string, mixed> $descriptor
     * @param array<string, mixed> $product
     * @return array{admin_path: string|null, health: array{status: string, message: string|null}|null}|null
     */
    private function normalise(array $descriptor, array $product): ?array
    {
        if (($descriptor['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }

        if (($descriptor['plugin_file'] ?? null) !== ($product['plugin_file'] ?? null)) {
            return null;
        }

        if (array_diff(array_keys($descriptor), self::KEYS) !== []) {
            return null;
        }

        $adminPath = $descriptor['admin_path'] ?? null;

        if ($adminPath !== null) {
            if (!is_string($adminPath) || preg_match(CatalogueValidator::ADMIN_PATH_PATTERN, $adminPath) !== 1) {
                return null;
            }
        }

        $health = $descriptor['health'] ?? null;

        if ($health !== null) {
            $health = $this->normaliseHealth($health);

            if ($health === null) {
                return null;
            }
        }

        return [
            'admin_path' => $adminPath,
            'health' => $health,
        ];
    }

    /**
     * @param mixed $health
     * @return array{status: string, message: string|null}|null
     */
    private function normaliseHealth($health): ?array
    {
        if (!is_array($health) || array_diff(array_keys($health), self::HEALTH_KEYS) !== []) {
            return null;
        }

        $status = $health['status'] ?? null;

        if (!is_string($status) || !in_array($status, self::HEALTH_STATUSES, true)) {
            return null;
        }

        $message = $health['message'] ?? null;

        if ($message !== null && !is_string($message)) {
            return null;
        }

        $message = $message === null ? null : sanitize_text_field($message);

        // Measured after sanitising, and in bytes when mbstring is absent —
        // stricter, never looser. Over the cap invalidates the whole descriptor,
        // matching how the catalogue treats over-long text.
        if ($message !== null) {
            $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);

            if ($length > self::MAX_MESSAGE_LENGTH) {
                return null;
            }
        }

        return [
            'status' => $status,
            'message' => $message === '' ? null : $message,
        ];
    }
}
