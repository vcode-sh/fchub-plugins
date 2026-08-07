<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

/**
 * Shared helpers for reading WooCommerce order storage.
 *
 * CartShift reads orders from HPOS ({prefix}wc_orders) only, which is the same
 * stance FluentCart's own WooCommerce migrator takes.
 *
 * The point of this class is agreement. Every COUNT query and every batch fetch
 * has to look at exactly the same set of rows, otherwise progress bars stop
 * short of 100% and — rather worse — abandoned checkout drafts get imported as
 * real FluentCart customers.
 */
final class WooStorage
{
    /** HPOS order type for regular orders. */
    public const string TYPE_ORDER = 'shop_order';

    /** HPOS order type for WooCommerce Subscriptions records. */
    public const string TYPE_SUBSCRIPTION = 'shop_subscription';

    /**
     * Statuses WooCommerce deliberately stores WITHOUT a `wc-` prefix.
     *
     * Everything else in wc_orders.status carries the prefix.
     *
     * @see woocommerce/includes/data-stores/abstract-wc-order-data-store-cpt.php::get_post_status() (v11.0.0, line 356)
     * @see woocommerce/src/Internal/DataStores/Orders/OrdersTableDataStore.php::get_db_row_from_order() (v11.0.0, line 2568)
     *
     * @var list<string>
     */
    private const array UNPREFIXED_STATUSES = ['auto-draft', 'draft', 'trash'];

    /**
     * The statuses wc_get_orders(['status' => 'any']) resolves to.
     *
     * Used only when WooCommerce is not loaded and wc_get_order_statuses() is
     * therefore unavailable. Note the absence of `wc-checkout-draft` and
     * `trash` — that omission is the whole point.
     *
     * @see woocommerce/includes/wc-order-functions.php::wc_get_order_statuses() (v11.0.0, line 104)
     *
     * @var list<string>
     */
    private const array FALLBACK_ORDER_STATUSES = [
        'wc-pending',
        'wc-processing',
        'wc-on-hold',
        'wc-completed',
        'wc-cancelled',
        'wc-refunded',
        'wc-failed',
    ];

    /**
     * The statuses WooCommerce Subscriptions registers for shop_subscription rows.
     *
     * Used only when wcs_get_subscription_statuses() is unavailable — which is
     * the normal case, since WooCommerce Subscriptions is a paid add-on. Callers
     * must still guard on function_exists('wcs_get_subscriptions') before doing
     * anything with subscriptions at all.
     *
     * @var list<string>
     */
    private const array FALLBACK_SUBSCRIPTION_STATUSES = [
        'wc-pending',
        'wc-active',
        'wc-on-hold',
        'wc-cancelled',
        'wc-switched',
        'wc-expired',
        'wc-pending-cancel',
    ];

    /**
     * Is High Performance Order Storage the active order backend?
     *
     * Falls back to the raw option when WooCommerce is absent or its container
     * is not booted yet, so this never fatals on a site without WooCommerce.
     */
    public static function isHposEnabled(): bool
    {
        $orderUtil = '\Automattic\WooCommerce\Utilities\OrderUtil';

        if (
            class_exists($orderUtil)
            && method_exists($orderUtil, 'custom_orders_table_usage_is_enabled')
        ) {
            try {
                return (bool) $orderUtil::custom_orders_table_usage_is_enabled();
            } catch (\Throwable) {
                // Container not booted yet — fall through to the option check.
            }
        }

        return get_option('woocommerce_custom_orders_table_enabled') === 'yes';
    }

    /**
     * The HPOS orders table name.
     */
    public static function ordersTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wc_orders';
    }

    /**
     * The status slugs wc_get_orders(['status' => 'any']) actually returns.
     *
     * Mirrors OrdersTableQuery::sanitize_status(): the keys of
     * wc_get_order_statuses() minus anything registered as exclude_from_search.
     * Slugs come back `wc-` prefixed, matching what wc_orders.status stores.
     *
     * @see woocommerce/src/Internal/DataStores/Orders/OrdersTableQuery.php::sanitize_status() (v11.0.0, line 682)
     *
     * @return list<string>
     */
    public static function migratableOrderStatuses(): array
    {
        if (!function_exists('wc_get_order_statuses')) {
            return self::FALLBACK_ORDER_STATUSES;
        }

        $statuses = array_keys(wc_get_order_statuses());

        if (function_exists('get_post_stati')) {
            $excluded = get_post_stati(['exclude_from_search' => true]);
            $statuses = array_diff($statuses, array_values((array) $excluded));
        }

        $statuses = self::normalizeStatuses($statuses);

        return $statuses === [] ? self::FALLBACK_ORDER_STATUSES : $statuses;
    }

    /**
     * The status slugs a shop_subscription row can carry.
     *
     * Derived from WooCommerce Subscriptions when it is installed, so a site
     * that registers extra statuses through the wcs_subscription_statuses
     * filter still counts them. Never fatals when the add-on is absent.
     *
     * @return list<string>
     */
    public static function migratableSubscriptionStatuses(): array
    {
        if (!function_exists('wcs_get_subscription_statuses')) {
            return self::FALLBACK_SUBSCRIPTION_STATUSES;
        }

        $statuses = self::normalizeStatuses(array_keys((array) wcs_get_subscription_statuses()));

        return $statuses === [] ? self::FALLBACK_SUBSCRIPTION_STATUSES : $statuses;
    }

    /**
     * Add the `wc-` prefix unless WooCommerce stores this status bare.
     *
     * Defensive: callers may hand us either form.
     */
    public static function normalizeStatus(string $status): string
    {
        $status = trim($status);

        if ($status === '' || str_starts_with($status, 'wc-')) {
            return $status;
        }

        if (in_array($status, self::UNPREFIXED_STATUSES, true)) {
            return $status;
        }

        return 'wc-' . $status;
    }

    /**
     * Build a prepared `<column> IN (...)` fragment.
     *
     * Every value goes through $wpdb->prepare. An empty status list yields a
     * clause that matches nothing rather than one that matches everything —
     * silently widening a filter is how abandoned carts got imported in the
     * first place.
     *
     * @param list<string> $statuses
     */
    public static function statusInClause(array $statuses, string $column = 'status'): string
    {
        global $wpdb;

        $statuses = self::normalizeStatuses($statuses);
        $column   = self::sanitizeIdentifier($column);

        if ($statuses === []) {
            return '1 = 0';
        }

        return (string) $wpdb->prepare(
            $column . ' IN (' . self::placeholders(count($statuses)) . ')',
            ...$statuses,
        );
    }

    /**
     * Unprepared `type = %s AND status IN (%s, ...)` fragment plus its ordered
     * values, for callers that need to fold the scope into a larger
     * $wpdb->prepare() call (LIMIT/OFFSET and friends) rather than nest one
     * prepared string inside another.
     *
     * @param list<string> $statuses
     * @return array{0: string, 1: list<string>}
     */
    public static function orderScopeTemplate(string $type, array $statuses): array
    {
        $statuses = self::normalizeStatuses($statuses);

        if ($statuses === []) {
            return ['type = %s AND 1 = 0', [$type]];
        }

        return [
            'type = %s AND status IN (' . self::placeholders(count($statuses)) . ')',
            [$type, ...$statuses],
        ];
    }

    /**
     * Build the prepared `type = ... AND status IN (...)` fragment used by the
     * wc_orders COUNT queries.
     *
     * @param list<string> $statuses
     */
    public static function orderScopeClause(string $type, array $statuses): string
    {
        global $wpdb;

        [$sql, $values] = self::orderScopeTemplate($type, $statuses);

        return (string) $wpdb->prepare($sql, ...$values);
    }

    /**
     * Prepared scope fragment for regular orders — the exact row set
     * wc_get_orders(['status' => 'any', 'type' => 'shop_order']) returns.
     */
    public static function orderScopeSql(): string
    {
        return self::orderScopeClause(self::TYPE_ORDER, self::migratableOrderStatuses());
    }

    /**
     * Unprepared counterpart of orderScopeSql().
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function orderScopeParts(): array
    {
        return self::orderScopeTemplate(self::TYPE_ORDER, self::migratableOrderStatuses());
    }

    /**
     * Prepared scope fragment for subscriptions.
     */
    public static function subscriptionScopeSql(): string
    {
        return self::orderScopeClause(self::TYPE_SUBSCRIPTION, self::migratableSubscriptionStatuses());
    }

    /**
     * Trim, prefix and de-duplicate a status list, dropping empties.
     *
     * @param array<array-key, string> $statuses
     * @return list<string>
     */
    private static function normalizeStatuses(array $statuses): array
    {
        $normalized = [];

        foreach ($statuses as $status) {
            $status = self::normalizeStatus((string) $status);

            if ($status !== '') {
                $normalized[$status] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * A comma separated run of %s placeholders.
     */
    private static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '%s'));
    }

    /**
     * Column names are never user input here, but strip anything that is not a
     * plausible identifier before it reaches a query string.
     */
    private static function sanitizeIdentifier(string $identifier): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.]/', '', $identifier) ?? '';

        return $clean === '' ? 'status' : $clean;
    }
}
