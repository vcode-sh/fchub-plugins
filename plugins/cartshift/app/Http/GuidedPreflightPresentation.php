<?php

declare(strict_types=1);

namespace CartShift\Http;

use CartShift\Validator\PreflightCheck;

defined('ABSPATH') || exit;

/** Evaluates the guided preflight policy and presents its owner-facing REST shape. */
final class GuidedPreflightPresentation
{
    /** @param array<string,mixed> $preflight @return array{ready:bool,checks:list<array<string,string>>} */
    public function evaluate(array $preflight): array
    {
        $payload = $this->payload($preflight);
        if (($payload['checks']['fc_data']['severity'] ?? null) === PreflightCheck::SEVERITY_WARN) {
            $payload['checks']['fc_data']['severity'] = PreflightCheck::SEVERITY_FAIL;
            $payload['ready'] = false;
        }
        $coupons = $this->standaloneCouponCount();
        $payload['checks']['standalone_coupons'] = [
            'label' => 'Standalone coupons',
            'severity' => $coupons > 0 ? PreflightCheck::SEVERITY_WARN : PreflightCheck::SEVERITY_PASS,
            'message' => $coupons > 0
                ? sprintf(
                    '%d standalone WooCommerce coupon%s will not be migrated yet. Applied coupon history stays on migrated orders.',
                    $coupons,
                    $coupons === 1 ? '' : 's',
                )
                : 'No standalone WooCommerce coupons need migration.',
        ];

        return [
            'ready' => ($payload['ready'] ?? false) === true,
            'checks' => array_values(is_array($payload['checks'] ?? null) ? $payload['checks'] : []),
        ];
    }

    /** @param array<string,mixed> $preflight @return array<string,mixed> */
    private function payload(array $preflight): array
    {
        $checks = [];
        foreach (is_array($preflight['checks'] ?? null) ? $preflight['checks'] : [] as $key => $check) {
            if (!is_array($check)) {
                continue;
            }
            $checks[$key] = [
                'label' => $this->checkLabel((string) $key),
                'severity' => (string) ($check['severity'] ?? PreflightCheck::SEVERITY_FAIL),
                'message' => $this->checkMessage((string) $key, $check),
            ];
        }

        return ['ready' => ($preflight['ready'] ?? false) === true, 'checks' => $checks];
    }

    private function checkLabel(string $key): string
    {
        return match ($key) {
            'woocommerce' => 'WooCommerce',
            'fluentcart' => 'FluentCart',
            'order_storage' => 'Order storage',
            'wc_subscriptions' => 'WooCommerce Subscriptions',
            'php_memory' => 'Server memory',
            'max_execution_time' => 'Server processing time',
            'product_types' => 'Product types',
            'fc_data' => 'Existing FluentCart records',
            'migration_tables' => 'CartShift storage',
            'entitlements' => 'Access granted by other plugins',
            default => 'Shop check',
        };
    }

    /** @param array<string,mixed> $check */
    private function checkMessage(string $key, array $check): string
    {
        $severity = (string) ($check['severity'] ?? PreflightCheck::SEVERITY_FAIL);
        $attention = $severity !== PreflightCheck::SEVERITY_PASS;

        return match ($key) {
            'woocommerce' => $attention ? 'Activate WooCommerce before continuing.' : 'WooCommerce is ready.',
            'fluentcart' => $attention ? 'Activate FluentCart before continuing.' : 'FluentCart is ready.',
            'order_storage' => $attention
                ? 'WooCommerce order storage is not ready for guided migration.'
                : 'WooCommerce order storage is ready.',
            'wc_subscriptions' => ($check['active'] ?? false) === true
                ? 'Subscriptions will be included in the readiness check.'
                : 'Subscriptions are not active and will be skipped.',
            'php_memory' => $attention
                ? 'The server needs more memory before this shop can migrate safely.'
                : 'Server memory is ready.',
            'max_execution_time' => $attention
                ? 'The server needs more processing time before this shop can migrate safely.'
                : 'Server processing time is ready.',
            'product_types' => $this->productTypeMessage($check),
            'fc_data' => $attention
                ? 'FluentCart already contains records, so continuing could create duplicates. '
                    . 'If they are test records, remove them in FluentCart and reload this screen. '
                    . 'If they must be kept, stop here; CartShift will not overwrite them.'
                : 'FluentCart has no existing records that could conflict with this migration.',
            'migration_tables' => $attention
                ? 'CartShift storage is not ready. Update or reactivate CartShift, then reload this screen.'
                : 'CartShift storage is ready.',
            'entitlements' => $attention
                ? 'Another plugin may grant access from WooCommerce purchases. That access is not migrated by CartShift.'
                : 'No known access-granting plugin needs separate migration.',
            default => $attention ? 'This shop check needs attention before migration.' : 'This shop check is ready.',
        };
    }

    /** @param array<string,mixed> $check */
    private function productTypeMessage(array $check): string
    {
        $unsupported = $check['unsupported_product_types'] ?? [];
        $types = is_array($unsupported) && is_array($unsupported['types'] ?? null)
            ? $unsupported['types']
            : [];
        $products = array_sum(array_map('intval', $types));
        if ($products === 0) {
            return 'Every detected product type is supported by the guided migration.';
        }
        $orders = max(0, (int) ($unsupported['orders_affected'] ?? 0));

        return sprintf(
            '%d product%s use unsupported types and appear in %d order%s. The orders keep their purchase details, but those items cannot link to migrated products.',
            $products,
            $products === 1 ? '' : 's',
            $orders,
            $orders === 1 ? '' : 's',
        );
    }

    private function standaloneCouponCount(): int
    {
        $counts = wp_count_posts('shop_coupon');
        $total = 0;
        foreach (['publish', 'draft', 'pending', 'private', 'future'] as $status) {
            $total += max(0, (int) ($counts->{$status} ?? 0));
        }

        return $total;
    }
}
