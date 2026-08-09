<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/**
 * What one runtime looks like to the migration, and whether it may proceed.
 *
 * Two rules govern what goes in here.
 *
 * It carries versions, schema and capability booleans, storage authority, a
 * hashed table prefix, gateway registration and capability results, the raw and
 * effective subscription-management settings, a target census, and stable error
 * codes. It carries no credentials, no customer data, no provider identifiers
 * and no full URLs — this report is meant to be pasted into a ticket.
 *
 * And it serialises deterministically. Later tasks fingerprint the reviewed
 * settings and census with SHA-256 and bind an operator's system-collection
 * approval to that exact hash, so key order may never depend on the order the
 * probe happened to assemble things in. Hence toArray()'s recursive sort and an
 * explicit fingerprint() rather than json_encode() of whatever turns up.
 */
final readonly class RuntimeCompatibilityReport
{
    // Stable gate codes. A run stops on any of them; the plan is updated from
    // fresh source evidence rather than routed around with a fallback.
    public const string ERROR_WOOCOMMERCE_MISSING = 'woocommerce_missing';
    public const string ERROR_WCS_MISSING = 'wcs_missing';
    public const string ERROR_WCS_API_MISSING = 'wcs_api_missing';
    public const string ERROR_FLUENTCART_MISSING = 'fluentcart_missing';
    public const string ERROR_FLUENTCART_SCHEMA_DRIFT = 'fluentcart_schema_drift';
    public const string ERROR_FLUENTCART_MODEL_API_MISSING = 'fluentcart_model_api_missing';
    public const string ERROR_FLUENTCART_MODEL_FILLABLE_DRIFT = 'fluentcart_model_fillable_drift';
    public const string ERROR_FLUENTCART_GATEWAY_MANAGER_MISSING = 'fluentcart_gateway_manager_missing';
    public const string ERROR_FLUENTCART_COLLECTION_PROBE_MISSING = 'fluentcart_collection_method_probe_missing';
    public const string ERROR_FLUENTCART_GATEWAY_UNREGISTERED = 'fluentcart_gateway_unregistered';
    public const string ERROR_TARGET_CENSUS_UNAVAILABLE = 'target_census_unavailable';

    /** @var list<string> Sorted and de-duplicated, so two identical gates read identically. */
    public array $errors;

    /**
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $wooCommerce
     * @param array<string, mixed> $wooCommerceSubscriptions
     * @param array<string, mixed> $paypalAdapter
     * @param array<string, mixed> $fluentCart
     * @param array<string, mixed> $subscriptionSettings
     * @param array<string, mixed> $subscriptionCensus
     * @param list<string>         $errors
     */
    public function __construct(
        public string $role,
        public SourceTopology $topology,
        public array $runtime,
        public array $wooCommerce,
        public array $wooCommerceSubscriptions,
        public array $paypalAdapter,
        public array $fluentCart,
        public array $subscriptionSettings,
        public array $subscriptionCensus,
        array $errors,
    ) {
        $errors = array_values(array_unique($errors));
        sort($errors);

        $this->errors = $errors;
    }

    /**
     * Whether the runtime may carry on to the next task.
     */
    public function isReady(): bool
    {
        return $this->errors === [];
    }

    /**
     * The canonical, sorted representation. What `--format=json` prints.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return CanonicalJson::sortDeep([
            'role'                      => $this->role,
            'topology'                  => $this->topology->value,
            'requires_package'          => $this->topology->requiresPackage(),
            'ready'                     => $this->isReady(),
            'errors'                    => $this->errors,
            'fingerprint'               => $this->fingerprint(),
            'runtime'                   => $this->runtime,
            'woocommerce'               => $this->wooCommerce,
            'woocommerce_subscriptions' => $this->wooCommerceSubscriptions,
            'source_paypal_adapter'     => $this->paypalAdapter,
            'fluent_cart'               => $this->fluentCart,
            'subscription_settings'     => $this->subscriptionSettings,
            'subscription_census'       => $this->subscriptionCensus,
        ]);
    }

    /**
     * SHA-256 over the reviewed settings and census, bound to the role and to
     * FluentCart actually being here.
     *
     * This is what an operator approves with
     * `--approve-system-settings=<sha256>` after reading the report, so it
     * covers the inputs that decide whether a `system` subscription may be
     * staged: the store-wide mode, the system-charge switch, and the population
     * those settings would apply to. Versions, prefix hash and gateway
     * registration stay out — a plugin update must not silently invalidate an
     * approval, and a policy change must.
     *
     * Role and `fluent_cart.booted` are in the payload for a blunter reason. On
     * a cross-runtime source FluentCart is absent, so the settings and census
     * are all-null and would otherwise hash to one value shared by every source
     * report that will ever exist — a constant that looks exactly like an
     * approval token and would satisfy a target approval by construction.
     * Including them makes a source fingerprint structurally incapable of
     * standing in for a target one.
     *
     * The serialisation is CanonicalJson's, which sorts every associative key
     * and leaves lists alone. That is what this report needs: a census group
     * order is meaningful and is sorted where it is built, whereas a key order
     * is an accident of assembly and must never reach the hash.
     */
    public function fingerprint(): string
    {
        return CanonicalJson::fingerprint([
            'fluent_cart_booted'    => (bool) ($this->fluentCart['booted'] ?? false),
            'role'                  => $this->role,
            'subscription_census'   => $this->subscriptionCensus,
            'subscription_settings' => $this->subscriptionSettings,
        ]);
    }
}
