<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Fingerprints settings and gateway registrations without reading gateway state or credentials. */
final class LoadedTargetSettingsInspector implements TargetSettingsInspector
{
    private const array OPTIONS = [
        'fluent_cart_store_settings',
        'gmt_offset',
        'timezone_string',
        'upload_path',
        'upload_url_path',
        'woocommerce_currency',
        'woocommerce_downloads_grant_access_after_payment',
        'woocommerce_downloads_require_login',
        'woocommerce_manage_stock',
        'woocommerce_prices_include_tax',
        'woocommerce_tax_round_at_subtotal',
    ];

    /** @var \Closure(string):mixed */
    private readonly \Closure $optionReader;

    /** @var \Closure():array<string,mixed> */
    private readonly \Closure $uploadReader;

    /** @var \Closure():array<string,mixed> */
    private readonly \Closure $gatewayReader;

    /**
     * @param (callable(string):mixed)|null $optionReader
     * @param (callable():array<string,mixed>)|null $uploadReader
     * @param (callable():array<string,mixed>)|null $gatewayReader
     */
    public function __construct(
        ?callable $optionReader = null,
        ?callable $uploadReader = null,
        ?callable $gatewayReader = null,
    ) {
        $this->optionReader = $optionReader === null
            ? static fn (string $key): mixed => get_option($key, null)
            : $optionReader(...);
        $this->uploadReader = $uploadReader === null
            ? static fn (): array => function_exists('wp_get_upload_dir') ? (array) wp_get_upload_dir() : []
            : $uploadReader(...);
        $this->gatewayReader = $gatewayReader === null
            ? static function (): array {
                $manager = 'FluentCart\\App\\Modules\\PaymentMethods\\Core\\GatewayManager';
                if (!class_exists($manager) || !method_exists($manager, 'getInstance')) {
                    return [];
                }
                $instance = $manager::getInstance();
                return is_object($instance) && method_exists($instance, 'all')
                    ? (array) $instance->all()
                    : [];
            }
            : $gatewayReader(...);
    }

    public function fingerprint(): string
    {
        $options = [];
        foreach (self::OPTIONS as $key) {
            $options[$key] = $this->normalise(($this->optionReader)($key));
        }
        $uploads = ($this->uploadReader)();
        $uploadIdentity = [
            'basedir' => is_string($uploads['basedir'] ?? null) ? $uploads['basedir'] : null,
            'baseurl' => is_string($uploads['baseurl'] ?? null) ? $uploads['baseurl'] : null,
            'error' => $uploads['error'] ?? null,
        ];
        return CanonicalJson::fingerprint([
            'target_options' => $options,
            'upload_identity' => $this->normalise($uploadIdentity),
        ]);
    }

    public function gatewayFingerprint(): string
    {
        $gatewayRegistrations = [];
        foreach (($this->gatewayReader)() as $slug => $gateway) {
            if (!is_string($slug) || $slug === '') {
                throw new \RuntimeException('target_gateway_registration_unrepresentable');
            }
            $class = is_object($gateway) ? get_class($gateway) : $gateway;
            if (!is_string($class) || $class === '') {
                throw new \RuntimeException('target_gateway_registration_unrepresentable');
            }
            $gatewayRegistrations[$slug] = $class;
        }
        ksort($gatewayRegistrations, SORT_STRING);
        return CanonicalJson::fingerprint(['gateway_registrations' => $gatewayRegistrations]);
    }

    private function normalise(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) return $value;
        if (!is_array($value)) throw new \RuntimeException('target_settings_unrepresentable');
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) throw new \RuntimeException('target_settings_unrepresentable');
            $value[$key] = $this->normalise($item);
        }
        return CanonicalJson::canonicalise($value);
    }
}
