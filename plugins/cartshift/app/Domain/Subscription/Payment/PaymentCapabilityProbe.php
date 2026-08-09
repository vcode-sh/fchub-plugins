<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\LoadedRuntimeSymbols;
use CartShift\Domain\Subscription\RuntimeSymbols;

defined('ABSPATH') || exit;

/**
 * The only way CartShift may claim system collection.
 *
 * `systemCollectionMethod()` obtains the registered gateway with
 * `GatewayManager::gateway()` and returns exactly what
 * `SubscriptionManagementMode::resolveCollectionMethodFor()` returned. It does
 * nothing cleverer, and specifically it does not carry a private copy of
 * FluentCart's settings-and-capability conjunction — a copy would drift the
 * moment FluentCart changed its mind, and the difference would show up as
 * subscriptions billing when nobody expected them to.
 *
 * @see fluent-cart/app/Modules/Subscriptions/Services/SubscriptionManagementMode.php:67-74
 * @see fluent-cart/app/Modules/PaymentMethods/Core/GatewayManager.php:59-62
 *
 * What it adds is attribution. FluentCart answers `manual` for two unrelated
 * situations — a store that has not opted into store-managed billing with
 * system charging on, and a gateway that genuinely cannot charge off-session —
 * and those send an operator to entirely different screens. `diagnose()`
 * reports the inputs separately so `manual` is never misread as a gateway
 * defect, which is exactly what plan section 8.1 requires and what Task 0's
 * runtime report already established for the compatibility probe.
 *
 * It changes nothing. `subscription_management_mode` and
 * `subscription_system_charge` are store-wide policy affecting new checkouts
 * and existing renewals; a migration tool that quietly flipped them would have
 * exceeded its remit by some distance.
 */
final class PaymentCapabilityProbe
{
    public const string GATEWAY_STRIPE = 'stripe';
    public const string GATEWAY_PAYPAL = 'paypal';

    /** FluentCart's two answers. */
    public const string METHOD_SYSTEM = 'system';
    public const string METHOD_MANUAL = 'manual';

    /**
     * Not one of FluentCart's answers, and deliberately so.
     *
     * It means FluentCart was never asked: the gateway is not registered, or
     * the canonical probe itself is absent. Reporting `manual` here would be
     * attributing an opinion to FluentCart that FluentCart never expressed.
     */
    public const string METHOD_UNAVAILABLE = 'unavailable';

    public const string REASON_UNAVAILABLE = 'system_collection_unavailable';
    public const string REASON_GATEWAY_LACKS_CAPABILITY = 'gateway_lacks_system_capability';
    public const string REASON_STORE_MODE_NOT_APPROVED = 'system_store_mode_not_approved';

    private const string FC_GATEWAY_MANAGER = 'FluentCart\App\Modules\PaymentMethods\Core\GatewayManager';
    private const string FC_MANAGEMENT_MODE = 'FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode';

    public function __construct(
        private readonly RuntimeSymbols $symbols = new LoadedRuntimeSymbols(),
    ) {
    }

    /**
     * FluentCart's own verdict, unedited.
     */
    public function systemCollectionMethod(string $targetGateway): string
    {
        $gateway = $this->gateway($targetGateway);

        if ($gateway === null || !$this->probeAvailable()) {
            return self::METHOD_UNAVAILABLE;
        }

        $mode = self::FC_MANAGEMENT_MODE;

        return (string) $mode::resolveCollectionMethodFor($gateway);
    }

    public function isSystemCapable(string $targetGateway): bool
    {
        return $this->systemCollectionMethod($targetGateway) === self::METHOD_SYSTEM;
    }

    public function isRegistered(string $targetGateway): bool
    {
        return $this->gateway($targetGateway) !== null;
    }

    /**
     * The verdict, its inputs, and why it came out that way.
     *
     * @return array{
     *     gateway: string,
     *     registered: bool,
     *     system_subscription: bool|null,
     *     store_managed: bool|null,
     *     system_charge_enabled: bool|null,
     *     collection_method: string,
     *     reason_codes: list<string>,
     * }
     */
    public function diagnose(string $targetGateway): array
    {
        $gateway          = $this->gateway($targetGateway);
        $registered       = $gateway !== null;
        $probeAvailable   = $this->probeAvailable();
        $collectionMethod = $this->systemCollectionMethod($targetGateway);

        $mode = self::FC_MANAGEMENT_MODE;

        $storeManaged  = $probeAvailable ? $mode::getMode() === $mode::STORE_MANAGED : null;
        $systemCharge  = $probeAvailable ? (bool) $mode::isSystemChargeEnabled() : null;
        $systemFeature = $registered ? (bool) $gateway->has('system_subscription') : null;

        return [
            'gateway'               => $targetGateway,
            'registered'            => $registered,
            'system_subscription'   => $systemFeature,
            'store_managed'         => $storeManaged,
            'system_charge_enabled' => $systemCharge,
            'collection_method'     => $collectionMethod,
            'reason_codes'          => $this->attribute($collectionMethod, $systemFeature, $systemCharge),
        ];
    }

    /**
     * Why this gateway did not come back `system`.
     *
     * Both a policy failure and a capability failure can be true at once, and
     * then both are reported — picking a favourite would send somebody to fix
     * the wrong one. A `null` method means FluentCart could not be asked, which
     * is neither.
     *
     * @return list<string>
     */
    private function attribute(string $collectionMethod, ?bool $systemFeature, ?bool $systemChargeEnabled): array
    {
        if ($collectionMethod === self::METHOD_SYSTEM) {
            return [];
        }

        if ($collectionMethod === self::METHOD_UNAVAILABLE) {
            return [self::REASON_UNAVAILABLE];
        }

        $reasons = [];

        if ($systemFeature === false) {
            $reasons[] = self::REASON_GATEWAY_LACKS_CAPABILITY;
        }

        if ($systemChargeEnabled === false) {
            $reasons[] = self::REASON_STORE_MODE_NOT_APPROVED;
        }

        sort($reasons);

        return $reasons;
    }

    private function gateway(string $targetGateway): ?object
    {
        $manager = self::FC_GATEWAY_MANAGER;

        if (!$this->symbols->classExists($manager) || !$this->symbols->methodExists($manager, 'gateway')) {
            return null;
        }

        return $manager::gateway($targetGateway);
    }

    /**
     * Whether FluentCart's canonical probe is here to be asked.
     *
     * Absent, the run reports `system_collection_unavailable` rather than
     * falling back to a private reimplementation of the conjunction.
     */
    private function probeAvailable(): bool
    {
        $class = self::FC_MANAGEMENT_MODE;

        if (!$this->symbols->classExists($class)) {
            return false;
        }

        foreach (['getMode', 'isSystemChargeEnabled', 'resolveCollectionMethodFor'] as $method) {
            if (!$this->symbols->methodExists($class, $method)) {
                return false;
            }
        }

        return true;
    }
}
