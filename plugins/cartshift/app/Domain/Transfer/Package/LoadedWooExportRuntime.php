<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Audit\TransferAuditReport;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Builds the immutable source drift descriptor from a freshly successful audit. */
final class LoadedWooExportRuntime
{
    private const array SETTINGS = [
        'gmt_offset',
        'timezone_string',
        'woocommerce_currency',
        'woocommerce_custom_orders_table_data_sync_enabled',
        'woocommerce_custom_orders_table_enabled',
        'woocommerce_dimension_unit',
        'woocommerce_downloads_grant_access_after_payment',
        'woocommerce_downloads_require_login',
        'woocommerce_prices_include_tax',
        'woocommerce_weight_unit',
    ];

    /** @var \Closure(string): mixed */
    private readonly \Closure $optionReader;

    /** @var \Closure(): string */
    private readonly \Closure $homeUrlReader;

    /** @var \Closure(): string */
    private readonly \Closure $clock;

    /** @var \Closure(): string */
    private readonly \Closure $sourceInstanceFingerprint;

    /**
     * @param (callable(string): mixed)|null $optionReader
     * @param (callable(): string)|null $homeUrlReader
     * @param (callable(): string)|null $clock
     * @param (callable(): string)|null $sourceInstanceFingerprint
     */
    public function __construct(
        private readonly TransferRuntimeInspector $runtime = new TransferRuntimeProbe(),
        ?callable $optionReader = null,
        ?callable $homeUrlReader = null,
        ?callable $clock = null,
        ?callable $sourceInstanceFingerprint = null,
    ) {
        $this->optionReader = $optionReader === null
            ? static fn (string $key): mixed => function_exists('get_option') ? get_option($key, null) : null
            : $optionReader(...);
        $this->homeUrlReader = $homeUrlReader === null
            ? static fn (): string => function_exists('home_url') ? (string) home_url('/') : ''
            : $homeUrlReader(...);
        $this->clock = $clock === null
            ? static fn (): string => gmdate('Y-m-d\TH:i:s\Z')
            : $clock(...);
        $this->sourceInstanceFingerprint = $sourceInstanceFingerprint === null
            ? (new LoadedSourceInstanceFingerprint())->fingerprint(...)
            : $sourceInstanceFingerprint(...);
    }

    /** @return array<string, mixed> */
    public function descriptor(
        string $destination,
        TransferAuditReport $audit,
        SourceInstanceRegistry $registry,
    ): array {
        if (!$audit->ready) {
            throw new \RuntimeException('source_audit_not_ready');
        }
        $current = $this->runtime->inspect(TransferRuntimeProbe::ROLE_SOURCE);
        if (!$current->isReady() || !hash_equals($audit->runtimeFingerprint, $current->fingerprint)) {
            throw new \RuntimeException('source_runtime_drifted_after_audit');
        }
        $sourceInstanceFingerprint = ($this->sourceInstanceFingerprint)();
        if ($registry->binding($audit->sourceKey) === null) {
            throw new \RuntimeException('source_instance_binding_missing');
        }
        $registry->requireBinding($audit->sourceKey, $sourceInstanceFingerprint);
        $settings = [];
        foreach (self::SETTINGS as $key) {
            $value = ($this->optionReader)($key);
            if (!is_scalar($value) && $value !== null) {
                throw new \RuntimeException('source_settings_unrepresentable');
            }
            $settings[$key] = $value;
        }
        ksort($settings, SORT_STRING);
        $url = $this->normalisePublicUrl(($this->homeUrlReader)());
        $versions = $current->versions;

        return [
            'destination' => $destination,
            'source_instance_fingerprint' => $sourceInstanceFingerprint,
            'source_url_hash' => hash('sha256', $url),
            'source_runtime_fingerprint' => $current->fingerprint,
            'source_settings_fingerprint' => CanonicalJson::fingerprint(['source_settings' => $settings]),
            'source_capability_fingerprint' => CanonicalJson::fingerprint([
                'audit_fingerprint' => $audit->auditFingerprint,
                'capabilities' => $audit->capabilities,
            ]),
            'cartshift_version' => (string) ($versions['cartshift'] ?? (defined('CARTSHIFT_VERSION') ? CARTSHIFT_VERSION : '')),
            'woocommerce_version' => (string) ($versions['woocommerce'] ?? ''),
            'wcs_version' => isset($versions['wcs']) ? (string) $versions['wcs'] : null,
            'created_at_utc' => ($this->clock)(),
        ];
    }

    private function normalisePublicUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('source_public_url_invalid');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $portPart = $port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))
            ? ':' . $port
            : '';
        $path = '/' . trim((string) ($parts['path'] ?? ''), '/');
        $path = $path === '/' ? '/' : $path . '/';

        return $scheme . '://' . $host . $portPart . $path;
    }
}
