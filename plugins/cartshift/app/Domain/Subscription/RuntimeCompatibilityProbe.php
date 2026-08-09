<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Support\WooStorage;

/**
 * Read-only proof that this runtime can do what the migration plan assumes.
 *
 * It writes nothing: no row, no option, no transient, no file, and no call to
 * Stripe or PayPal. Everything here is a symbol lookup, a SHOW COLUMNS, one
 * grouped SELECT, and FluentCart's own settings readers.
 *
 * Two rules it does not bend.
 *
 * It never reimplements FluentCart's settings-and-capability conjunction. The
 * only accepted way to claim system collection is
 * SubscriptionManagementMode::resolveCollectionMethodFor(), so that is what it
 * calls. What it adds is attribution: FluentCart answers `manual` for a store
 * that has not opted into store-managed billing and for a gateway that cannot
 * charge off-session, and those are entirely different problems for the person
 * reading the report. Blaming Stripe for a store setting sends someone hunting
 * a defect that does not exist.
 *
 * And it never changes `subscription_management_mode` or
 * `subscription_system_charge`. Those are store-wide policy affecting new
 * checkouts and existing renewals; a migration tool that quietly flips them has
 * exceeded its remit by some distance. The report exists so an operator can
 * decide, apply the change themselves, and re-run.
 */
final class RuntimeCompatibilityProbe
{
    public const string ROLE_SOURCE = 'source';
    public const string ROLE_TARGET = 'target';

    /** WooCommerce Subscriptions 8.7.1 functions the plan's reader depends on. */
    private const array WCS_FUNCTIONS = [
        'wcs_get_subscriptions',
        'wcs_get_subscription',
        'wcs_get_subscription_statuses',
    ];

    /**
     * WC_Subscription methods the plan names.
     *
     * `get_related_orders()` carries the dependency closure, `is_manual()` and
     * `set_requires_manual_renewal()` carry the source release, and the rest
     * carry the contract and its history. Nothing speculative: the WCS source
     * is not on this machine, so this list is exactly what the plan quotes plus
     * what CartShift already calls.
     */
    private const array WCS_SUBSCRIPTION_METHODS = [
        'get_related_orders',
        'get_payment_count',
        'get_date',
        'get_parent',
        'get_billing_period',
        'get_billing_interval',
        'is_manual',
        'set_requires_manual_renewal',
        'save',
    ];

    /**
     * The six NOT NULL columns FluentCart 1.6.0 requires, in the order its
     * migration declares them.
     *
     * @see fluent-cart/database/Migrations/SubscriptionsMigrator.php:18-23
     */
    private const array REQUIRED_COLUMNS = [
        'customer_id',
        'parent_order_id',
        'product_id',
        'item_name',
        'quantity',
        'variation_id',
    ];

    /** @see fluent-cart/database/Migrations/SubscriptionsMigrator.php:36 */
    private const array EXPECTED_COLLECTION_METHODS = ['automatic', 'manual', 'system'];

    /** The two gateways this plan deliberately supports, besides manual renewal. */
    private const array PROBED_GATEWAYS = ['stripe', 'paypal'];

    private const string WOOCOMMERCE_CLASS = 'WooCommerce';
    private const string WCS_CLASS = 'WC_Subscriptions';
    private const string WCS_SUBSCRIPTION_CLASS = 'WC_Subscription';
    private const string ORDER_UTIL_CLASS = 'Automattic\WooCommerce\Utilities\OrderUtil';

    /**
     * The WooCommerce PayPal Payments module class.
     *
     * Its absence is the expected Lapka case and is reported as such. The
     * source plugin is not in the restore, so no metadata contract is guessed
     * from it — an unknown adapter means the PayPal cohort takes the deliberate
     * manual route until someone produces the real plugin version.
     */
    private const string PPCP_MODULE_CLASS = 'WooCommerce\PayPalCommerce\PluginModule';
    private const string PPCP_ADAPTER_NAME = 'woocommerce-paypal-payments';

    private const string FC_SUBSCRIPTION_MODEL = 'FluentCart\App\Models\Subscription';
    private const string FC_GATEWAY_MANAGER = 'FluentCart\App\Modules\PaymentMethods\Core\GatewayManager';
    private const string FC_MANAGEMENT_MODE = 'FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode';

    private const string FC_STORE_SETTINGS_OPTION = 'fluent_cart_store_settings';
    private const string FC_SUBSCRIPTIONS_TABLE = 'fct_subscriptions';

    public function __construct(
        private readonly RuntimeSymbols $symbols = new LoadedRuntimeSymbols(),
    ) {
    }

    /**
     * Inspect this runtime in the given role.
     *
     * Both halves of the report are always filled in, because a same-runtime
     * store is genuinely both.
     *
     * Which of them *gate* depends on topology, not on the role alone.
     *
     * Cross-runtime, the role decides: a source with no FluentCart is the
     * ordinary case rather than a fault, and a target with no WooCommerce
     * likewise. Neither half can gate on something that is not there.
     *
     * Same-runtime, both gate, whichever role was asked for. A same-runtime
     * store *is* both halves — one WordPress, one prefix — so the FluentCart
     * schema this probe just found drifted is the schema the migration will
     * write into. Answering `ready: true` to `--role=source` with
     * `fluentcart_schema_drift` sitting in the report body would be the exact
     * failure this gate exists to prevent: green light, broken runtime.
     */
    public function inspect(string $role): RuntimeCompatibilityReport
    {
        if (!in_array($role, [self::ROLE_SOURCE, self::ROLE_TARGET], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown role "%s". Use "%s" or "%s".',
                $role,
                self::ROLE_SOURCE,
                self::ROLE_TARGET,
            ));
        }

        $wooBooted = $this->symbols->classExists(self::WOOCOMMERCE_CLASS);
        $wcsBooted = $this->symbols->classExists(self::WCS_CLASS);
        $fluentCartBooted = $this->symbols->classExists(self::FC_SUBSCRIPTION_MODEL);

        [$subscriptions, $wcsErrors] = $this->inspectWooSubscriptions($wooBooted, $wcsBooted);
        [$fluentCart, $settings, $census, $targetErrors] = $this->inspectFluentCart($fluentCartBooted);

        $topology = SourceTopology::decide($wooBooted, $wcsBooted, $fluentCartBooted);

        $errors = $topology === SourceTopology::SameRuntime
            ? array_merge($wcsErrors, $targetErrors)
            : match ($role) {
                self::ROLE_SOURCE => $wcsErrors,
                self::ROLE_TARGET => $targetErrors,
            };

        return new RuntimeCompatibilityReport(
            role: $role,
            topology: $topology,
            runtime: [
                'php'         => PHP_VERSION,
                'cartshift'   => $this->symbols->constantValue('CARTSHIFT_VERSION'),
                'prefix_hash' => $this->prefixHash(),
            ],
            wooCommerce: $this->inspectWooCommerce($wooBooted),
            wooCommerceSubscriptions: $subscriptions,
            paypalAdapter: $this->inspectSourcePayPalAdapter(),
            fluentCart: $fluentCart,
            subscriptionSettings: $settings,
            subscriptionCensus: $census,
            errors: $errors,
        );
    }

    /**
     * The table prefix, hashed.
     *
     * The prefix is the one piece of runtime identity the two reports have to
     * be comparable on — it is how "same WordPress runtime" is evidenced — and
     * it is also a detail nobody needs printed in a ticket. So: a hash.
     */
    private function prefixHash(): string
    {
        global $wpdb;

        return hash('sha256', (string) $wpdb->prefix);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectWooCommerce(bool $booted): array
    {
        [$syncEnabled, $mirrorInSync] = $this->hposSyncSignals($booted);

        return [
            'booted'              => $booted,
            'version'             => $this->symbols->constantValue('WC_VERSION'),
            'storage_authority'   => $booted ? (WooStorage::isHposEnabled() ? 'hpos' : 'posts') : null,
            'hpos_sync_enabled'   => $syncEnabled,
            'hpos_mirror_in_sync' => $mirrorInSync,
        ];
    }

    /**
     * Whether the HPOS mirror is being kept up to date, when there is one.
     *
     * Reported, never enforced. Lapka's authoritative store is legacy CPT and
     * its mirror disagrees on two active records; forcing HPOS to make CartShift
     * convenient would migrate the wrong dates. `is_custom_order_tables_in_sync()`
     * answers false when syncing is switched off entirely, which says nothing
     * about whether the tables agree, so it is only reported when sync is on.
     *
     * @return array{0: bool|null, 1: bool|null}
     */
    private function hposSyncSignals(bool $booted): array
    {
        if (!$booted || !$this->symbols->classExists(self::ORDER_UTIL_CLASS)) {
            return [null, null];
        }

        $orderUtil = self::ORDER_UTIL_CLASS;

        try {
            if (!$this->symbols->methodExists($orderUtil, 'custom_orders_table_data_sync_is_enabled')) {
                return [null, null];
            }

            $syncEnabled = (bool) $orderUtil::custom_orders_table_data_sync_is_enabled();

            if (!$syncEnabled || !$this->symbols->methodExists($orderUtil, 'is_custom_order_tables_in_sync')) {
                return [$syncEnabled, null];
            }

            return [$syncEnabled, (bool) $orderUtil::is_custom_order_tables_in_sync()];
        } catch (\Throwable) {
            // WooCommerce's container is not booted. Unknown, not false.
            return [null, null];
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function inspectWooSubscriptions(bool $wooBooted, bool $wcsBooted): array
    {
        $apis = [];

        foreach (self::WCS_FUNCTIONS as $function) {
            $apis[$function] = $this->symbols->functionExists($function);
        }

        foreach (self::WCS_SUBSCRIPTION_METHODS as $method) {
            $apis[self::WCS_SUBSCRIPTION_CLASS . '::' . $method] =
                $this->symbols->methodExists(self::WCS_SUBSCRIPTION_CLASS, $method);
        }

        $missing = array_keys(array_filter($apis, static fn (bool $present): bool => !$present));
        sort($missing);

        $errors = [];

        if (!$wooBooted) {
            $errors[] = RuntimeCompatibilityReport::ERROR_WOOCOMMERCE_MISSING;
        }

        if (!$wcsBooted) {
            $errors[] = RuntimeCompatibilityReport::ERROR_WCS_MISSING;
        }

        if ($missing !== []) {
            $errors[] = RuntimeCompatibilityReport::ERROR_WCS_API_MISSING;
        }

        return [
            [
                'booted'       => $wcsBooted,
                'version'      => $this->symbols->constantValue('WCS_VERSION'),
                'apis'         => $apis,
                'missing_apis' => $missing,
            ],
            $errors,
        ];
    }

    /**
     * Which source PayPal implementation is installed, if any.
     *
     * A name, not a contract. Whether a PayPal subscription can be adopted at
     * all depends on the exact plugin version's metadata, and an unrecognised
     * implementation means the cohort takes the deliberate manual route rather
     * than having a vendor identifier invented for it.
     *
     * @return array<string, mixed>
     */
    private function inspectSourcePayPalAdapter(): array
    {
        $present = $this->symbols->classExists(self::PPCP_MODULE_CLASS);

        return [
            'name'         => $present ? self::PPCP_ADAPTER_NAME : null,
            'marker'       => self::PPCP_MODULE_CLASS,
            'reason_codes' => $present ? [] : ['source_paypal_adapter_unknown'],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: list<string>}
     */
    private function inspectFluentCart(bool $booted): array
    {
        $errors = $booted ? [] : [RuntimeCompatibilityReport::ERROR_FLUENTCART_MISSING];

        [$schema, $schemaErrors] = $booted
            ? $this->inspectSchema()
            : [$this->unknownSchema(), []];

        [$model, $modelErrors] = $booted
            ? $this->inspectModel()
            : [$this->unknownModel(), []];

        [$collectionProbe, $probeErrors] = $this->inspectCollectionProbe($booted);

        $systemChargeEnabled = $booted && $collectionProbe['available']
            ? $this->effectiveSystemCharge()
            : null;

        [$gateways, $gatewayErrors] = $this->inspectGateways($booted, $collectionProbe['available'], $systemChargeEnabled);

        [$census, $censusErrors] = $booted
            ? $this->inspectCensus($collectionProbe['config_key'])
            : [['total' => null, 'groups' => []], []];

        return [
            [
                'booted'           => $booted,
                'version'          => $this->symbols->constantValue('FLUENTCART_VERSION'),
                // Pro spells its version differently from core. Not a typo.
                // @see fluent-cart-pro/fluent-cart-pro.php:16
                'pro_version'      => $this->symbols->constantValue('FLUENTCART_PRO_PLUGIN_VERSION'),
                'schema'           => $schema,
                'model'            => $model,
                'collection_probe' => $collectionProbe,
                'gateways'         => $gateways,
            ],
            $this->readSubscriptionSettings($booted, $collectionProbe['available'], $systemChargeEnabled),
            $census,
            array_merge($errors, $schemaErrors, $modelErrors, $probeErrors, $gatewayErrors, $censusErrors),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownSchema(): array
    {
        return [
            'table_present'            => false,
            'required_columns'         => array_fill_keys(self::REQUIRED_COLUMNS, 'unknown'),
            'collection_method_values' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownModel(): array
    {
        return [
            'calculate_bill_count'       => false,
            'created_at_fillable'        => null,
            'unfillable_required_fields' => [],
        ];
    }

    /**
     * The installed fct_subscriptions shape, straight from the database.
     *
     * SHOW COLUMNS rather than the migration file: what matters is the table a
     * live site actually has, which on an upgraded install is the sum of every
     * migration that has ever run and every hand-edit nobody admits to.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function inspectSchema(): array
    {
        // Not preparable — an identifier cannot be a placeholder — but built
        // from $wpdb->prefix, which is configuration, not input.
        [$rows, $failed] = $this->readQuietly(
            'SHOW COLUMNS FROM `' . $this->subscriptionsTable() . '`',
        );

        $types = [];

        foreach ($rows as $row) {
            $types[(string) ($row->Field ?? '')] = [
                'type'     => (string) ($row->Type ?? ''),
                'nullable' => strtoupper((string) ($row->Null ?? '')) !== 'NO',
            ];
        }

        $tablePresent = !$failed && $types !== [];

        $required = [];

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (!isset($types[$column])) {
                $required[$column] = 'missing';

                continue;
            }

            $required[$column] = $types[$column]['nullable'] ? 'nullable' : 'present';
        }

        $collectionMethods = $this->enumValues($types['collection_method']['type'] ?? '');

        $drifted = !$tablePresent
            || array_filter($required, static fn (string $state): bool => $state !== 'present') !== []
            || $collectionMethods !== self::EXPECTED_COLLECTION_METHODS;

        return [
            [
                'table_present'            => $tablePresent,
                'required_columns'         => $required,
                'collection_method_values' => $collectionMethods,
            ],
            $drifted ? [RuntimeCompatibilityReport::ERROR_FLUENTCART_SCHEMA_DRIFT] : [],
        ];
    }

    /**
     * The members of an `enum('a','b')` column type, in declaration order.
     *
     * @return list<string>
     */
    private function enumValues(string $type): array
    {
        if (preg_match("/^enum\((.*)\)$/i", trim($type), $matches) !== 1) {
            return [];
        }

        preg_match_all("/'((?:[^']|'')*)'/", $matches[1], $values);

        return array_map(
            static fn (string $value): string => str_replace("''", "'", $value),
            $values[1],
        );
    }

    /**
     * What FluentCart's Subscription model will and will not accept.
     *
     * `created_at` is excluded from $fillable in 1.6.0, so mass assignment
     * silently drops it and an imported subscription would claim to have been
     * created at import time. The writer has to set it on the instance. That is
     * a fact about the installed model, so it is read rather than assumed — and
     * the six required references are checked the other way round, because a
     * field that stopped being fillable would be dropped into a NOT NULL column.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function inspectModel(): array
    {
        $model = self::FC_SUBSCRIPTION_MODEL;

        $hasBillCount = $this->symbols->methodExists($model, 'calculateBillCount');
        $hasFillable = $this->symbols->methodExists($model, 'getFillable');

        if (!$hasFillable) {
            return [
                [
                    'calculate_bill_count'       => $hasBillCount,
                    'created_at_fillable'        => null,
                    'unfillable_required_fields' => [],
                ],
                [RuntimeCompatibilityReport::ERROR_FLUENTCART_MODEL_API_MISSING],
            ];
        }

        $fillable = $this->symbols->declaredFillable($model);

        $unfillable = array_values(array_filter(
            self::REQUIRED_COLUMNS,
            static fn (string $column): bool => !in_array($column, $fillable, true),
        ));

        $errors = [];

        if (!$hasBillCount) {
            $errors[] = RuntimeCompatibilityReport::ERROR_FLUENTCART_MODEL_API_MISSING;
        }

        if ($unfillable !== []) {
            $errors[] = RuntimeCompatibilityReport::ERROR_FLUENTCART_MODEL_FILLABLE_DRIFT;
        }

        return [
            [
                'calculate_bill_count'       => $hasBillCount,
                'created_at_fillable'        => in_array('created_at', $fillable, true),
                'unfillable_required_fields' => $unfillable,
            ],
            $errors,
        ];
    }

    /**
     * Whether FluentCart's canonical collection-method probe is here to be asked.
     *
     * CartShift is forbidden from carrying its own copy of the
     * settings-and-capability conjunction, so if this is missing the run stops
     * rather than falling back to a private reimplementation that would drift
     * the moment FluentCart changed its mind.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function inspectCollectionProbe(bool $fluentCartBooted): array
    {
        $class = self::FC_MANAGEMENT_MODE;
        $present = $fluentCartBooted && $this->symbols->classExists($class);

        $methods = [];

        foreach (['getMode', 'isSystemChargeEnabled', 'resolveCollectionMethodFor'] as $method) {
            $methods[$method] = $present && $this->symbols->methodExists($class, $method);
        }

        $configKey = $present
            ? $this->symbols->constantValue($class . '::CONFIG_KEY')
            : null;

        $available = $present
            && !in_array(false, $methods, true)
            && $configKey !== null;

        return [
            [
                'available'  => $available,
                'config_key' => $configKey,
                'methods'    => $methods,
            ],
            $fluentCartBooted && !$available
                ? [RuntimeCompatibilityReport::ERROR_FLUENTCART_COLLECTION_PROBE_MISSING]
                : [],
        ];
    }

    /**
     * FluentCart's own answer to "is system charging switched on for this store".
     */
    private function effectiveSystemCharge(): bool
    {
        $class = self::FC_MANAGEMENT_MODE;

        return (bool) $class::isSystemChargeEnabled();
    }

    /**
     * Registration, capability, and FluentCart's verdict — kept apart on purpose.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function inspectGateways(
        bool $fluentCartBooted,
        bool $probeAvailable,
        ?bool $systemChargeEnabled,
    ): array {
        $manager = self::FC_GATEWAY_MANAGER;
        $managerAvailable = $fluentCartBooted
            && $this->symbols->classExists($manager)
            && $this->symbols->methodExists($manager, 'gateway');

        $gateways = [];
        $errors = [];

        if ($fluentCartBooted && !$managerAvailable) {
            $errors[] = RuntimeCompatibilityReport::ERROR_FLUENTCART_GATEWAY_MANAGER_MISSING;
        }

        foreach (self::PROBED_GATEWAYS as $slug) {
            $gateway = $managerAvailable ? $manager::gateway($slug) : null;
            $registered = $gateway !== null;

            $systemFeature = $registered && (bool) $gateway->has('system_subscription');

            // FluentCart's own conjunction of store policy and gateway
            // capability. CartShift does not get to have an opinion here.
            $mode = self::FC_MANAGEMENT_MODE;
            $collectionMethod = $registered && $probeAvailable
                ? (string) $mode::resolveCollectionMethodFor($gateway)
                : null;

            $gateways[$slug] = [
                'registered'          => $registered,
                'subscriptions'       => $registered && (bool) $gateway->has('subscriptions'),
                'system_subscription' => $systemFeature,
                'collection_method'   => $collectionMethod,
                'reason_codes'        => $this->attributeCollectionMethod(
                    $collectionMethod,
                    $systemFeature,
                    $systemChargeEnabled,
                ),
            ];

            if ($managerAvailable && !$registered) {
                $errors[] = RuntimeCompatibilityReport::ERROR_FLUENTCART_GATEWAY_UNREGISTERED;
            }
        }

        return [$gateways, $errors];
    }

    /**
     * Why this gateway did not come back `system`.
     *
     * FluentCart returns the same word for two unrelated situations. The store
     * has not opted into store-managed billing with system charging on, which
     * is global policy an operator may review and change outside CartShift. Or
     * the gateway genuinely cannot charge off-session, which no setting fixes.
     * Both can be true at once, and then both are reported — picking a favourite
     * would send someone to fix the wrong one.
     *
     * A null method means FluentCart could not be asked at all: the gateway is
     * not registered, or the canonical probe itself is absent. Either way the
     * row says so rather than sitting there with no explanation, which is the
     * cheerful-default reading the plan forbids.
     *
     * @return list<string>
     */
    private function attributeCollectionMethod(
        ?string $collectionMethod,
        bool $systemFeature,
        ?bool $systemChargeEnabled,
    ): array {
        if ($collectionMethod === 'system') {
            return [];
        }

        if ($collectionMethod === null) {
            return ['system_collection_unavailable'];
        }

        $reasons = [];

        if (!$systemFeature) {
            $reasons[] = 'gateway_lacks_system_capability';
        }

        if ($systemChargeEnabled === false) {
            $reasons[] = 'system_store_mode_not_approved';
        }

        sort($reasons);

        return $reasons;
    }

    /**
     * The raw stored settings beside the values FluentCart actually acts on.
     *
     * Raw is null when nothing has ever been stored, which is a different thing
     * from the default having been chosen deliberately, and the difference
     * matters to whoever has to decide whether flipping it is safe. The
     * effective values come from FluentCart's own readers, filters and all.
     *
     * The option keys come from FluentCart's own constants with no hard-coded
     * fallback. If the constants are gone the run has already stopped on
     * `fluentcart_collection_method_probe_missing`, and a guessed key would only
     * let the report claim it had read a setting it never found.
     *
     * @return array<string, mixed>
     */
    private function readSubscriptionSettings(
        bool $fluentCartBooted,
        bool $probeAvailable,
        ?bool $systemChargeEnabled,
    ): array {
        $class = self::FC_MANAGEMENT_MODE;

        $modeKey = $this->symbols->constantValue($class . '::SETTING_KEY');
        $chargeKey = $this->symbols->constantValue($class . '::SYSTEM_CHARGE_KEY');

        $stored = get_option(self::FC_STORE_SETTINGS_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $effectiveMode = $fluentCartBooted && $probeAvailable
            ? (string) $class::getMode()
            : null;

        return [
            'management_mode' => [
                'key'       => $modeKey,
                'raw'       => $this->storedSetting($stored, $modeKey),
                'effective' => $effectiveMode,
            ],
            'system_charge' => [
                'key'       => $chargeKey,
                'raw'       => $this->storedSetting($stored, $chargeKey),
                'effective' => $systemChargeEnabled,
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $stored
     */
    private function storedSetting(array $stored, ?string $key): ?string
    {
        if ($key === null || !isset($stored[$key]) || !is_scalar($stored[$key])) {
            return null;
        }

        return (string) $stored[$key];
    }

    /**
     * What is already in the target, grouped the three ways that matter.
     *
     * Status, collection method, and the mode each subscription was stamped
     * with at checkout. An empty restore is not evidence that the cutover
     * target will still be empty, and a store-wide settings change lands on
     * whatever is in this table — so the operator sees the population before
     * approving anything.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function inspectCensus(?string $configKey): array
    {
        global $wpdb;

        $path = '$.' . ($configKey ?? 'management_mode');

        [$rows, $failed] = $this->readQuietly($wpdb->prepare(
            'SELECT status, collection_method,'
            . ' JSON_UNQUOTE(JSON_EXTRACT(config, %s)) AS management_mode,'
            . ' COUNT(*) AS total'
            . ' FROM `' . $this->subscriptionsTable() . '`'
            . ' GROUP BY status, collection_method, management_mode',
            $path,
        ));

        if ($failed) {
            // Reporting zero here would read as "the target is empty", which is
            // the one conclusion a failed count must never be mistaken for.
            return [
                ['total' => null, 'groups' => []],
                [RuntimeCompatibilityReport::ERROR_TARGET_CENSUS_UNAVAILABLE],
            ];
        }

        $total = 0;
        $groups = [];

        foreach ($rows as $row) {
            $count = (int) ($row->total ?? 0);
            $total += $count;

            // Keys in sorted order, so a group survives canonicalisation
            // unchanged and the fingerprint does not depend on assembly.
            $groups[] = [
                'collection_method' => (string) ($row->collection_method ?? ''),
                'count'             => $count,
                'management_mode'   => isset($row->management_mode) ? (string) $row->management_mode : null,
                'status'            => (string) ($row->status ?? ''),
            ];
        }

        usort($groups, static fn (array $a, array $b): int =>
            [$a['status'], $a['collection_method'], $a['management_mode'] ?? '']
            <=> [$b['status'], $b['collection_method'], $b['management_mode'] ?? '']);

        return [['total' => $total, 'groups' => $groups], []];
    }

    /**
     * Run a read with wpdb's own error printing switched off, then put it back.
     *
     * Both of this class's reads can legitimately fail — a target without
     * FluentCart's table, or a server whose JSON functions are too old — and
     * both failures are already reported as structured findings. Left
     * unsuppressed, wpdb would additionally echo the raw MySQL error straight
     * into the `--format=json` stream whenever WP_DEBUG_DISPLAY is on, which
     * breaks both the JSON and the byte-identical-summary promise the audit
     * commands rest on. The previous suppression state is restored so this
     * cannot quietly silence errors for the rest of the request.
     *
     * @return array{0: list<object>, 1: bool} Rows, and whether it failed.
     */
    private function readQuietly(string $sql): array
    {
        global $wpdb;

        $previous = $wpdb->suppress_errors(true);

        try {
            $rows = $wpdb->get_results($sql);

            return [is_array($rows) ? $rows : [], $wpdb->last_error !== ''];
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private function subscriptionsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::FC_SUBSCRIPTIONS_TABLE;
    }
}
