<?php

declare(strict_types=1);

namespace CartShift\Validator;

use CartShift\Domain\Subscription\LoadedRuntimeSymbols;
use CartShift\Domain\Subscription\RuntimeSymbols;
use CartShift\Support\ProductTypes;
use CartShift\Support\WooStorage;

defined('ABSPATH') || exit;

final class PreflightCheck
{
    /**
     * Generic product/customer/order/coupon migration.
     *
     * Reads orders straight out of `{prefix}wc_orders`, so it keeps its HPOS
     * gate until that reader is refactored on its own terms.
     */
    public const string OPERATION_MIGRATION = 'migration';

    /**
     * Subscription audit, export and stage.
     *
     * Reads through WooCommerce's public data-store APIs, which means
     * WooCommerce picks its own configured backend and legacy CPT storage is
     * not a problem to be gated against. Lapka's authoritative store is legacy
     * CPT and the plan forbids forcing HPOS to make CartShift convenient.
     */
    public const string OPERATION_SUBSCRIPTION_DATASET = 'subscription_dataset';

    /** @var list<string> */
    public const array OPERATIONS = [
        self::OPERATION_MIGRATION,
        self::OPERATION_SUBSCRIPTION_DATASET,
    ];

    /**
     * The public lookups the subscription dataset source calls, and no more.
     *
     * @var list<string>
     */
    private const array SUBSCRIPTION_DATASET_APIS = [
        'wcs_get_subscriptions',
        'wcs_get_subscription',
        'wc_get_order',
        'wc_get_product',
    ];

    /** Nothing to see here. */
    public const string SEVERITY_PASS = 'pass';

    /** Worth knowing. Not worth stopping for. */
    public const string SEVERITY_WARN = 'warn';

    /** Migrate now and you will get wrong data. Blocks. */
    public const string SEVERITY_FAIL = 'fail';

    /** Recommended PHP memory limit, in bytes. Below this we grumble, we don't stop. */
    private const int RECOMMENDED_MEMORY_BYTES = 268435456; // 256 MB

    /** Recommended max_execution_time, in seconds. */
    private const int RECOMMENDED_EXECUTION_SECONDS = 300;

    /** How long the unsupported-type order count stays cached. */
    private const int ORDERS_AFFECTED_CACHE_SECONDS = 300;

    /** WooCommerce's HPOS opt-in option. */
    private const string HPOS_OPTION = 'woocommerce_custom_orders_table_enabled';

    /** WooCommerce's posts <-> HPOS realtime sync option. */
    private const string HPOS_SYNC_OPTION = 'woocommerce_custom_orders_table_data_sync_enabled';

    private const string ORDER_UTIL = '\Automattic\WooCommerce\Utilities\OrderUtil';

    private const string HPOS_DATA_STORE = '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore';

    /**
     * WooCommerce plugins that grant entitlements (course access, membership levels)
     * on the strength of a WooCommerce order. CartShift migrates orders, customers,
     * subscriptions and coupons — never entitlements, that boundary is deliberate and
     * belongs to fchub-memberships instead. But an admin who never hears about it will
     * watch a migration finish green and only discover the gap when a customer emails
     * asking where their course went.
     *
     * Basename => [grant, message]. Verified live: learndash-woocommerce 2.0.2 and
     * pmpro-woocommerce 1.10.1 both active alongside WooCommerce 11.0.0.
     *
     * @var array<string, array{grant: string, message: string}>
     */
    private const array ENTITLEMENT_BRIDGES = [
        'learndash-woocommerce/learndash_woocommerce.php' => [
            'grant'   => 'LearnDash course enrollment',
            'message' => 'LearnDash course access is granted by WooCommerce on this site. CartShift migrates '
                . 'orders and subscriptions, not course enrollments — customers will keep their purchase history '
                . 'but lose course access until you migrate that separately.',
        ],
        'pmpro-woocommerce/pmpro-woocommerce.php' => [
            'grant'   => 'Paid Memberships Pro membership level',
            'message' => 'Paid Memberships Pro membership levels are granted by WooCommerce on this site. '
                . 'CartShift migrates orders and subscriptions, not membership levels — customers will keep their '
                . 'purchase history but lose their membership until you migrate that separately.',
        ],
    ];

    /**
     * The symbol table, asked through a seam so the missing-API branch is testable.
     *
     * A function, once declared, cannot be undeclared, so a shared-process suite
     * that exercises the present-API path can never reach the absent one by any
     * other route.
     */
    public function __construct(
        private readonly RuntimeSymbols $symbols = new LoadedRuntimeSymbols(),
    ) {
    }

    /**
     * Run all preflight checks and return structured results.
     *
     * Readiness is derived from severity, not from vibes: any check marked
     * SEVERITY_FAIL blocks the migration, everything else does not. Warnings are for
     * things the admin should know about; failures are for things that would make
     * the migration produce quietly wrong data.
     *
     * The operation matters for exactly one check. Generic migration reads the
     * HPOS table directly and is gated on it; the subscription dataset path
     * reads through WooCommerce's public APIs and is not. Nothing else here
     * varies, and the default stays what it always was — a caller that has not
     * heard of operations gets the old behaviour.
     *
     * @return array{checks: array<string, array<string, mixed>>, ready: bool}
     */
    public function run(string $operation = self::OPERATION_MIGRATION): array
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            // Refused rather than defaulted. An unrecognised operation
            // defaulting to the permissive branch is how a gate stops being one.
            throw new \InvalidArgumentException(sprintf(
                'Unknown preflight operation "%s". Use one of: %s.',
                $operation,
                implode(', ', self::OPERATIONS),
            ));
        }

        $checks = [];

        $checks['woocommerce']        = $this->checkWooCommerce();
        $checks['fluentcart']         = $this->checkFluentCart();
        $checks['order_storage']      = $this->checkOrderStorage($operation);
        $checks['wc_subscriptions']   = $this->checkWcSubscriptions();
        $checks['php_memory']         = $this->checkPhpMemory();
        $checks['max_execution_time'] = $this->checkMaxExecutionTime();
        $checks['product_types']      = $this->checkProductTypes();
        $checks['fc_data']            = $this->checkExistingFcData();
        $checks['migration_tables']   = $this->checkMigrationTables();
        $checks['entitlements']       = $this->checkEntitlements();

        $ready = true;

        foreach ($checks as $check) {
            if (($check['severity'] ?? self::SEVERITY_PASS) === self::SEVERITY_FAIL) {
                $ready = false;
            }
        }

        return [
            'checks' => $checks,
            'ready'  => $ready,
        ];
    }

    /**
     * Build a check result.
     *
     * `pass` and `warning` are derived from severity so the admin UI keeps reading
     * exactly the keys it always read. `severity` is the thing to reason about.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function result(string $label, string $severity, string $message, array $extra = []): array
    {
        return [
            'label'    => $label,
            'severity' => $severity,
            'pass'     => $severity !== self::SEVERITY_FAIL,
            'warning'  => $severity === self::SEVERITY_WARN,
            'message'  => $message,
        ] + $extra;
    }

    /**
     * BLOCKING. No WooCommerce, nothing to migrate from.
     */
    private function checkWooCommerce(): array
    {
        $active  = class_exists('WooCommerce');
        $version = $active && defined('WC_VERSION') ? WC_VERSION : null;

        return $this->result(
            'WooCommerce',
            $active ? self::SEVERITY_PASS : self::SEVERITY_FAIL,
            $active
                ? sprintf('WooCommerce %s is active.', $version)
                : 'WooCommerce is not active. Activate it before migrating.',
            ['version' => $version],
        );
    }

    /**
     * BLOCKING. No FluentCart, nothing to migrate into.
     */
    private function checkFluentCart(): array
    {
        $active  = defined('FLUENTCART_PLUGIN_PATH');
        $version = defined('FLUENTCART_VERSION') ? FLUENTCART_VERSION : null;

        return $this->result(
            'FluentCart',
            $active ? self::SEVERITY_PASS : self::SEVERITY_FAIL,
            $active
                ? sprintf('FluentCart %s is active.', $version)
                : 'FluentCart is not active. Activate it before migrating.',
            ['version' => $version],
        );
    }

    /**
     * BLOCKING. The check that stops CartShift lying to you.
     *
     * CartShift reads orders, customers and subscriptions exclusively from the HPOS
     * table ({prefix}wc_orders). On a store still on legacy post storage that table is
     * empty or absent, every COUNT() comes back NULL, NULL casts to 0, and absolutely
     * nothing raises its hand. The migration then "succeeds": zero customers, every
     * order skipped for a missing customer mapping, every subscription skipped, and a
     * cheerful green results screen listing your products. Hence: fail, loudly, first.
     *
     * FluentCart's own WooCommerce migrator requires HPOS for the same reason, so this
     * is not CartShift being precious — it is how the ecosystem reads orders now.
     *
     * None of that applies to the subscription dataset path, which reads through
     * WooCommerce's public data-store APIs and therefore gets whichever backend
     * WooCommerce considers authoritative. Gating that on HPOS would block the
     * only store this plan was written for: Lapka's authoritative storage is
     * legacy CPT, its HPOS mirror disagrees about two next-payment dates, and
     * forcing the mirror would migrate the wrong ones. So the gate is
     * operation-aware, and generic migration is deliberately left as it was —
     * fixing it here would be scope creep with a silent failure mode attached.
     *
     * Sync state is a warning at most. See detectPendingSync() for why.
     */
    private function checkOrderStorage(string $operation): array
    {
        $label = $operation === self::OPERATION_SUBSCRIPTION_DATASET
            ? 'Order Storage (public API)'
            : 'Order Storage (HPOS)';

        if (! class_exists('WooCommerce')) {
            return $this->result(
                $label,
                self::SEVERITY_WARN,
                'Cannot determine order storage while WooCommerce is inactive.',
                ['hpos' => null],
            );
        }

        if ($operation === self::OPERATION_SUBSCRIPTION_DATASET) {
            return $this->checkSubscriptionDatasetStorage($label);
        }

        if (! $this->detectHpos()) {
            return $this->result(
                $label,
                self::SEVERITY_FAIL,
                'High-Performance Order Storage is off, so WooCommerce is still keeping orders in the posts table. '
                . 'CartShift reads orders, customers and subscriptions from the HPOS tables and nowhere else, so '
                . 'migrating now would silently produce products and nothing else. '
                . 'Fix it in WooCommerce > Settings > Advanced > Features: set order data storage to '
                . '"High-performance order storage", wait for the sync to finish, then re-run preflight.',
                ['hpos' => false],
            );
        }

        $pendingSync = $this->detectPendingSync();

        if ($pendingSync === true) {
            return $this->result(
                $label,
                self::SEVERITY_WARN,
                'HPOS is enabled, but WooCommerce still has orders pending synchronisation. If the posts-to-HPOS '
                . 'migration has not finished, the HPOS tables are incomplete and CartShift will migrate whatever '
                . 'is there so far. Let WooCommerce > Settings > Advanced > Features finish syncing first.',
                ['hpos' => true, 'pending_sync' => true],
            );
        }

        return $this->result(
            $label,
            self::SEVERITY_PASS,
            'High-Performance Order Storage is enabled. Orders, customers and subscriptions are readable.',
            ['hpos' => true, 'pending_sync' => $pendingSync],
        );
    }

    /**
     * Which of the subscription path's public lookups this runtime is missing.
     *
     * Public and free of side effects so a CLI command can ask the same
     * question without running the whole preflight — which reads product types
     * and, on a store with unsupported ones, caches the answer in a transient.
     * A zero-write audit calling a check that writes a transient would be an
     * amusing way to lose the one property the audit exists to have.
     *
     * @return list<string>
     */
    public function missingSubscriptionDatasetApis(): array
    {
        return array_values(array_filter(
            self::SUBSCRIPTION_DATASET_APIS,
            fn (string $function): bool => !$this->symbols->functionExists($function),
        ));
    }

    /**
     * The subscription path's gate: are the public lookups here?
     *
     * Which backend WooCommerce chose is reported, never required. What is
     * required is that the API through which WooCommerce would answer exists at
     * all — without `wcs_get_subscription()` there is nothing to hydrate and the
     * run would report a cheerful zero, which is the same silent-success failure
     * the HPOS gate was built to stop, arriving through a different door.
     *
     * @return array<string, mixed>
     */
    private function checkSubscriptionDatasetStorage(string $label): array
    {
        $authority = $this->detectHpos() ? 'hpos' : 'posts';

        $missing = $this->missingSubscriptionDatasetApis();

        if ($missing !== []) {
            return $this->result(
                $label,
                self::SEVERITY_FAIL,
                sprintf(
                    'WooCommerce is storing orders in %s, which is fine — CartShift reads subscriptions through '
                    . 'WooCommerce\'s own APIs and takes whichever backend WooCommerce considers authoritative. '
                    . 'What is missing is the API itself: %s. Activate WooCommerce Subscriptions and re-run '
                    . 'preflight.',
                    $authority === 'hpos' ? 'the HPOS tables' : 'the posts table',
                    implode(', ', $missing),
                ),
                [
                    'hpos'              => $authority === 'hpos',
                    'storage_authority' => $authority,
                    'access'            => 'unavailable',
                    'missing_apis'      => $missing,
                ],
            );
        }

        $pendingSync = $this->detectPendingSync();

        $extra = [
            'hpos'              => $authority === 'hpos',
            'storage_authority' => $authority,
            'access'            => 'public_api',
            'missing_apis'      => [],
            'pending_sync'      => $pendingSync,
        ];

        if ($pendingSync === true) {
            // Not a blocker for this path — the mirror is not what gets read —
            // but a mirror mid-migration is exactly the state in which its
            // values disagree with the authority, and the audit reports those
            // disagreements. Worth saying so before somebody reads the report.
            return $this->result(
                $label,
                self::SEVERITY_WARN,
                'WooCommerce still has orders pending synchronisation between the posts and HPOS tables. '
                . 'Subscription reads go through the authoritative backend either way, but the audit\'s '
                . 'mirror comparison will report differences that are simply sync lag.',
                $extra,
            );
        }

        return $this->result(
            $label,
            self::SEVERITY_PASS,
            sprintf(
                'WooCommerce is storing orders in %s. Subscriptions are read through WooCommerce\'s public '
                . 'data-store APIs, so that is the backend CartShift will read — no storage change is needed.',
                $authority === 'hpos' ? 'the HPOS tables' : 'the posts table',
            ),
            $extra,
        );
    }

    /**
     * Is HPOS the authoritative order store?
     *
     * Three routes, in order of how much we trust them:
     *   1. CartShift's own shared helper, if another module has shipped it.
     *   2. WooCommerce's public OrderUtil API (present since the HPOS rollout).
     *   3. The data-store class plus the opt-in option — the same pair FluentCart's
     *      migrator checks, and the only thing left on odd WooCommerce builds.
     */
    private function detectHpos(): bool
    {
        $helper = '\CartShift\Support\WooStorage';

        if (class_exists($helper) && method_exists($helper, 'isHposEnabled')) {
            try {
                return (bool) $helper::isHposEnabled();
            } catch (\Throwable) {
                // Helper blew up. Fall through and ask WooCommerce ourselves.
            }
        }

        if (class_exists(self::ORDER_UTIL) && method_exists(self::ORDER_UTIL, 'custom_orders_table_usage_is_enabled')) {
            try {
                return (bool) (self::ORDER_UTIL)::custom_orders_table_usage_is_enabled();
            } catch (\Throwable) {
                // OrderUtil resolves through WooCommerce's DI container, which can
                // throw if WooCommerce is half-booted. Fall through to the option.
            }
        }

        return class_exists(self::HPOS_DATA_STORE)
            && get_option(self::HPOS_OPTION) === 'yes';
    }

    /**
     * Does WooCommerce have orders waiting to sync between the posts and HPOS tables?
     *
     * Returns null when the question does not apply or cannot be answered.
     *
     * The trap here: OrderUtil::is_custom_order_tables_in_sync() returns false when
     * realtime sync is simply switched off — which is the recommended end state for an
     * HPOS store, not a fault. Calling it blind would slap a warning on every healthy
     * shop. So ask whether sync is even enabled first, and only then whether it has
     * caught up.
     */
    private function detectPendingSync(): ?bool
    {
        if (! class_exists(self::ORDER_UTIL)) {
            return null;
        }

        try {
            if (method_exists(self::ORDER_UTIL, 'custom_orders_table_data_sync_is_enabled')) {
                $syncEnabled = (bool) (self::ORDER_UTIL)::custom_orders_table_data_sync_is_enabled();
            } else {
                // WooCommerce below 11.0.0 has no cheap accessor. Read the option.
                $syncEnabled = get_option(self::HPOS_SYNC_OPTION) === 'yes';
            }

            if (! $syncEnabled) {
                return null;
            }

            if (! method_exists(self::ORDER_UTIL, 'is_custom_order_tables_in_sync')) {
                return null;
            }

            return ! (self::ORDER_UTIL)::is_custom_order_tables_in_sync();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * INFORMATIONAL. Optional dependency — its absence just means fewer entities.
     */
    private function checkWcSubscriptions(): array
    {
        $active  = class_exists('WC_Subscriptions');
        $version = $active && defined('WCS_VERSION') ? WCS_VERSION : null;

        return $this->result(
            'WooCommerce Subscriptions',
            self::SEVERITY_PASS,
            $active
                ? sprintf('WC Subscriptions %s detected. Subscription migration will be available.', $version)
                : 'WC Subscriptions not detected. Subscription migration will be skipped.',
            ['optional' => true, 'active' => $active, 'version' => $version],
        );
    }

    /**
     * ADVISORY. Never blocks.
     *
     * Migration runs in bounded batches, and the batch size is filterable, so a modest
     * memory limit is a comfort problem rather than a correctness one. If PHP does run
     * out of memory it does so with a fatal error, which nobody mistakes for success —
     * unlike the HPOS failure above, this one cannot be silent. A 128M limit on a
     * twenty-product store is fine and we are not going to pretend otherwise.
     */
    private function checkPhpMemory(): array
    {
        $limit = (string) ini_get('memory_limit');
        $bytes = $limit === '' ? -1 : wp_convert_hr_to_bytes($limit);

        // -1 means unlimited.
        $adequate = ($bytes === -1) || ($bytes >= self::RECOMMENDED_MEMORY_BYTES);

        return $this->result(
            'PHP Memory',
            $adequate ? self::SEVERITY_PASS : self::SEVERITY_WARN,
            $adequate
                ? sprintf('PHP memory limit is %s (recommended: 256M+).', $limit)
                : sprintf(
                    'PHP memory limit is %s. Batching keeps this survivable, but 256M+ gives you headroom on a '
                    . 'large store. Not a blocker.',
                    $limit,
                ),
            ['value' => $limit, 'optional' => true],
        );
    }

    /**
     * ADVISORY. Never blocks — batched migration is the whole point.
     */
    private function checkMaxExecutionTime(): array
    {
        $maxTime = (int) ini_get('max_execution_time');

        // 0 means unlimited.
        $adequate = ($maxTime === 0) || ($maxTime >= self::RECOMMENDED_EXECUTION_SECONDS);

        $message = match (true) {
            $maxTime === 0 => 'max_execution_time is unlimited.',
            $adequate      => sprintf('max_execution_time is %ds (adequate).', $maxTime),
            default        => sprintf(
                'max_execution_time is %ds (recommended: 300s+). Batched migration mitigates this, but consider '
                . 'increasing it for safety.',
                $maxTime,
            ),
        };

        return $this->result(
            'Max Execution Time',
            $adequate ? self::SEVERITY_PASS : self::SEVERITY_WARN,
            $message,
            ['value' => $maxTime, 'optional' => true],
        );
    }

    /**
     * ADVISORY. Never blocks.
     *
     * Unsupported product types are excluded by ProductTypes::migratableClause(),
     * which is what countTotal() and fetchBatch() filter on — by design, because
     * CartShift cannot map, say, a LearnDash course product's data and attempting
     * to would fail in worse and less visible ways than not migrating it at all.
     *
     * The trap: excluded from the denominator means invisible, not skipped. A store
     * with 27 products where 2 are `course` reports "25 / 25 migrated" and never
     * mentions the other 2 — worse than a skip, because a skip is at least reported.
     * This check is the only place that names them and says how many orders carry
     * them, so the admin can go find those orders rather than discover the gap later.
     */
    private function checkProductTypes(): array
    {
        $label = 'Product Types';

        if (! class_exists('WooCommerce')) {
            return $this->result(
                $label,
                self::SEVERITY_PASS,
                'WooCommerce not active. Skipping product type check.',
                ['optional' => true, 'types' => []],
            );
        }

        $types       = self::productTypeCounts();
        $unsupported = self::unsupportedFromTypeCounts($types);

        $unsupportedCount = array_sum($unsupported);
        $hasWarning       = $unsupportedCount > 0;

        $parts = [];
        foreach ($types as $slug => $count) {
            $typeLabel = ucfirst(str_replace('-', ' ', $slug));
            $marker    = array_key_exists($slug, $unsupported) ? ' (unsupported)' : '';
            $parts[]   = sprintf('%s: %d%s', $typeLabel, $count, $marker);
        }

        $message = $parts === []
            ? 'No WooCommerce products found.'
            : implode(', ', $parts) . '.';

        $ordersAffected = 0;

        if ($hasWarning) {
            $ordersAffected = self::countOrdersAffectedByTypes(array_keys($unsupported));
            $totalOrders    = self::countMigratableOrders();

            $typeNames = implode(', ', array_map(
                static fn(string $slug): string => str_replace('-', ' ', $slug),
                array_keys($unsupported),
            ));

            $message .= sprintf(
                ' %d product%s use a type CartShift can\'t migrate (%s). They appear in %d of your %d order%s '
                . '— those orders will still show what was bought and what it cost, but the items won\'t link '
                . 'to a product page.',
                $unsupportedCount,
                $unsupportedCount === 1 ? '' : 's',
                $typeNames,
                $ordersAffected,
                $totalOrders,
                $totalOrders === 1 ? '' : 's',
            );
        }

        return $this->result(
            $label,
            $hasWarning ? self::SEVERITY_WARN : self::SEVERITY_PASS,
            $message,
            [
                'optional'                  => true,
                'types'                     => $types,
                'unsupported'               => $unsupported,
                'unsupported_product_types' => [
                    'types'           => $unsupported,
                    'orders_affected' => $ordersAffected,
                ],
            ],
        );
    }

    /**
     * The catalogue's product types, keyed by slug, with how many products
     * carry each. publish/draft/private only — the same trio
     * countOrdersAffectedByTypes() restricts to, so a product sitting in the
     * trash cannot inflate either number.
     *
     * Products with no `product_type` term at all are counted under `simple`,
     * because that is what they are: WooCommerce resolves a missing term to
     * ProductType::SIMPLE, ProductTypes::migratableClause() therefore lets them
     * through, and the migrator's total includes them. A histogram that left
     * them out would report "Simple: 25" on a store the progress bar then takes
     * to 26 — the same disagreement, one screen earlier.
     *
     * @see \CartShift\Support\ProductTypes
     *
     * @return array<string, int>
     */
    private static function productTypeCounts(): array
    {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT t.slug, COUNT(*) as count
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'product_type'
               AND p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
             GROUP BY t.slug
             ORDER BY count DESC",
        );

        $types = [];
        foreach ($results as $row) {
            $types[$row->slug] = (int) $row->count;
        }

        $untyped = self::untypedProductCount();

        if ($untyped > 0) {
            $types['simple'] = ($types['simple'] ?? 0) + $untyped;
            arsort($types);
        }

        return $types;
    }

    /**
     * Products carrying no `product_type` term at all.
     *
     * Same status trio, same post_type, and the subquery comes from
     * ProductTypes rather than being spelled out a fourth time.
     */
    private static function untypedProductCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND p.ID NOT IN (" . ProductTypes::typedProductSubquery() . ')',
        );
    }

    /**
     * @param array<string, int> $types
     * @return array<string, int>
     */
    private static function unsupportedFromTypeCounts(array $types): array
    {
        $unsupported = [];

        foreach (array_diff(array_keys($types), ProductTypes::supported()) as $slug) {
            $unsupported[$slug] = $types[$slug];
        }

        return $unsupported;
    }

    /**
     * product_type slugs CartShift cannot migrate, each with how many products
     * in the catalogue carry it.
     *
     * Catalogue-dependent, which is why it lives here rather than on
     * ProductTypes: the answer is the difference between the terms this shop
     * actually uses and ProductTypes::supported(). The supported side is never
     * restated — see that class for why there must be only one of it, and note
     * that on a store where WooCommerce Subscriptions has been disabled the
     * subscription slugs appear here, deliberately.
     *
     * @return array<string, int>
     */
    public static function unsupportedProductTypeCounts(): array
    {
        return self::unsupportedFromTypeCounts(self::productTypeCounts());
    }

    /**
     * How many orders contain at least one product of an unsupported type.
     *
     * Line items live in {prefix}woocommerce_order_items /
     * {prefix}woocommerce_order_itemmeta under both legacy and HPOS order storage —
     * HPOS moved the order and order-meta tables, not the line-item tables — so this
     * join is valid regardless of which storage mode is active.
     *
     * Numerator and denominator must count the same row set or the sentence built
     * from them is nonsense. Without the join to wc_orders this counted refunds
     * (`shop_order_refund`), subscriptions (`shop_subscription`), trashed orders and
     * `checkout-draft` rows, none of which countMigratableOrders() counts — so on a
     * store with WooCommerce Subscriptions selling one unsupported product type the
     * message could read "in 812 of your 699 orders". Same for the product side: the
     * type histogram this number is quoted alongside is limited to
     * publish/draft/private, so an unsupported product sitting in the trash must not
     * inflate the count of orders it appears in.
     *
     * @param list<string> $slugs
     */
    public static function countOrdersAffectedByTypes(array $slugs): int
    {
        if ($slugs === []) {
            return 0;
        }

        $cached = self::cachedOrdersAffected($slugs);

        if ($cached !== null) {
            return $cached;
        }

        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($slugs), '%s'));
        $ordersTable  = WooStorage::ordersTable();
        [$scopeSql, $scopeValues] = WooStorage::orderScopeParts();

        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT oi.order_id)
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$ordersTable} o
                     ON o.id = oi.order_id AND {$scopeSql}
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im
                     ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id'
             INNER JOIN {$wpdb->posts} p
                     ON p.ID = CAST(im.meta_value AS UNSIGNED)
                    AND p.post_type = 'product'
                    AND p.post_status IN ('publish', 'draft', 'private')
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'product_type' AND t.slug IN ({$placeholders})",
            ...[...$scopeValues, ...$slugs],
        );

        $count = (int) $wpdb->get_var($sql);

        self::rememberOrdersAffected($slugs, $count);

        return $count;
    }

    /**
     * The remembered answer for this exact slug list, or null.
     *
     * This query cannot be made cheap. `CAST(im.meta_value AS UNSIGNED)` on the meta
     * side makes any index on meta_value unusable, so MySQL scans
     * woocommerce_order_itemmeta filtered by nothing more selective than the
     * meta_key index — free on a 699-order shop, several million rows on a 500k one.
     * And PreflightScreen.vue fires the preflight endpoint on mount, so that scan ran
     * on every visit to the screen, on exactly the store least able to afford it.
     *
     * A transient rather than an EXISTS rewrite: EXISTS changes which rows are
     * examined but not the fact that the meta side cannot be indexed for this
     * predicate, so it would move the cost rather than remove it. The number is
     * advisory — it names orders the admin should go and look at, it never gates
     * readiness — so a few minutes of staleness costs nothing, and the cache is
     * keyed on the slug list so a store whose unsupported types change gets a fresh
     * answer immediately.
     *
     * @param list<string> $slugs
     */
    private static function cachedOrdersAffected(array $slugs): ?int
    {
        if (!function_exists('get_transient')) {
            return null;
        }

        $cached = get_transient(self::ordersAffectedCacheKey($slugs));

        return is_numeric($cached) ? (int) $cached : null;
    }

    /**
     * @param list<string> $slugs
     */
    private static function rememberOrdersAffected(array $slugs, int $count): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        set_transient(self::ordersAffectedCacheKey($slugs), $count, self::ORDERS_AFFECTED_CACHE_SECONDS);
    }

    /**
     * Hashed because the slug list is unbounded and option names are not — a
     * WordPress transient key has 172 characters to play with once the `_transient_`
     * prefix is accounted for, and a store with a dozen third-party product types
     * would blow through that.
     *
     * @param list<string> $slugs
     */
    private static function ordersAffectedCacheKey(array $slugs): string
    {
        $normalized = $slugs;
        sort($normalized);

        return 'cartshift_orders_affected_' . md5(implode('|', $normalized));
    }

    /**
     * The order count the "N of your M orders" wording reports against — the same
     * migratable scope OrderMigrator::countTotal() uses, so this number matches
     * whatever the migration itself will report as the order total.
     */
    public static function countMigratableOrders(): int
    {
        global $wpdb;

        $scope = WooStorage::orderScopeSql();
        $table = WooStorage::ordersTable();

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE {$scope}",
        );
    }

    /**
     * BLOCKING. No tables, nowhere to write the ID map, no rollback, no log.
     */
    private function checkMigrationTables(): array
    {
        global $wpdb;

        $idMapTable = $wpdb->prefix . 'cartshift_id_map';
        $logTable   = $wpdb->prefix . 'cartshift_migration_log';

        $hasIdMap = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $idMapTable),
        );
        $hasLog = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $logTable),
        );

        $pass = $hasIdMap && $hasLog;

        $missing = [];
        if (! $hasIdMap) {
            $missing[] = $idMapTable;
        }
        if (! $hasLog) {
            $missing[] = $logTable;
        }

        return $this->result(
            'Migration Tables',
            $pass ? self::SEVERITY_PASS : self::SEVERITY_FAIL,
            $pass
                ? 'Migration tables exist and are ready.'
                : sprintf(
                    'Missing tables: %s. Deactivate and reactivate CartShift to create them.',
                    implode(', ', $missing),
                ),
        );
    }

    /**
     * ADVISORY. Never blocks.
     *
     * Pre-existing FluentCart data is a "know what you are doing" situation, not an
     * error — migration appends, it does not overwrite. Plenty of people migrate into
     * a store that already has a product or two.
     */
    private function checkExistingFcData(): array
    {
        global $wpdb;

        $counts = [];
        $tables = [
            // 'fluent-products' verified against FluentCart 1.6.0:
            // app/CPT/FluentProducts.php -> const CPT_NAME = 'fluent-products'.
            'products'      => $wpdb->posts,
            'customers'     => $wpdb->prefix . 'fct_customers',
            'orders'        => $wpdb->prefix . 'fct_orders',
            'subscriptions' => $wpdb->prefix . 'fct_subscriptions',
            'coupons'       => $wpdb->prefix . 'fct_coupons',
        ];

        foreach ($tables as $key => $table) {
            if ($key === 'products') {
                $counts[$key] = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$table} WHERE post_type = 'fluent-products' AND post_status != 'auto-draft'",
                );
                continue;
            }

            $tableExists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            $counts[$key] = $tableExists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : 0;
        }

        $hasData = array_sum($counts) > 0;

        return $this->result(
            'Existing FluentCart Data',
            $hasData ? self::SEVERITY_WARN : self::SEVERITY_PASS,
            $hasData
                ? 'FluentCart already contains data. Migration will add new records alongside existing ones.'
                : 'FluentCart database is empty. Ready for clean migration.',
            ['counts' => $counts],
        );
    }

    /**
     * ADVISORY. Never blocks — the commerce/entitlement split is a deliberate product
     * decision, not a defect.
     *
     * CartShift migrates orders, customers, subscriptions and coupons. It does not
     * migrate entitlements — course enrollments, membership levels — because those
     * belong to a separate plugin (fchub-memberships). On a store where a bridge
     * plugin like LearnDash-WooCommerce or Paid Memberships Pro grants those
     * entitlements straight off a WooCommerce order, a CartShift migration can finish
     * with every number green and still leave customers holding purchase history with
     * no course access and no membership. Say so before the run, not after.
     */
    private function checkEntitlements(): array
    {
        $label = 'Entitlement Bridges';

        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = [];
        foreach (self::ENTITLEMENT_BRIDGES as $basename => $bridge) {
            if (is_plugin_active($basename)) {
                $active[$basename] = $bridge;
            }
        }

        if ($active === []) {
            return $this->result(
                $label,
                self::SEVERITY_PASS,
                'No known entitlement-granting plugins detected. CartShift migrates commerce data only '
                . '(orders, customers, subscriptions, coupons) — entitlements are out of scope regardless.',
                ['bridges' => []],
            );
        }

        $message = implode(' ', array_map(
            static fn(array $bridge): string => $bridge['message'],
            array_values($active),
        ));

        return $this->result(
            $label,
            self::SEVERITY_WARN,
            $message,
            ['bridges' => array_keys($active)],
        );
    }
}
