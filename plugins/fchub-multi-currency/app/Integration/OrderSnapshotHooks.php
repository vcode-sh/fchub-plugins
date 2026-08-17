<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Actions\SaveOrderSnapshotAction;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\FluentCartEvent;
use FChubMultiCurrency\Support\Hooks;

defined('ABSPATH') || exit;

final class OrderSnapshotHooks
{
    public static function register(): void
    {
        add_action('fluent_cart/before_payment_methods', [self::class, 'renderCheckoutCurrencyField'], 10, 1);
        add_action('fluent_cart/checkout/prepare_other_data', [self::class, 'captureAtCheckout'], 10, 1);
        add_action('fluent_cart/order_paid_done', [self::class, 'saveSnapshot'], 10, 1);
    }

    /** Carries the display choice inside FluentCart's own checkout form. */
    public static function renderCheckoutCurrencyField(): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $context = self::resolveCurrentContext(new OptionStore());

        echo '<input type="hidden" name="'
            . esc_attr(Constants::CHECKOUT_CURRENCY_FIELD)
            . '" value="'
            . esc_attr($context->displayCurrency->code)
            . '" data-fchub-mc-checkout-currency />';
    }

    /**
     * Capture currency context at checkout time, while the customer's HTTP request is active.
     *
     * @param array<string, mixed> $data
     */
    public static function captureAtCheckout(array $data): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $order = $data['order'] ?? null;
        if ($order === null) {
            return;
        }

        $optionStore = new OptionStore();
        $context = self::resolveCheckoutContext($data, $optionStore);

        if (!$context->isBaseDisplay) {
            $order->updateMeta('_fchub_mc_display_currency', $context->displayCurrency->code);
            $order->updateMeta('_fchub_mc_base_currency', $context->baseCurrency->code);
            $order->updateMeta('_fchub_mc_rate', $context->rate->rate);
            $order->updateMeta('_fchub_mc_disclosure_version', FCHUB_MC_VERSION);
        } else {
            // Sentinel: mark as "captured" so saveSnapshot() knows checkout ran
            $order->updateMeta('_fchub_mc_display_currency', $context->baseCurrency->code);
        }
    }

    /**
     * Prefer the validated browser field because an edge-cached request can carry a stale cookie.
     *
     * @param array<string, mixed> $data
     */
    private static function resolveCheckoutContext(array $data, OptionStore $optionStore): CurrencyContext
    {
        $requestData = $data['request_data'] ?? null;
        $submitted = is_array($requestData)
            ? ($requestData[Constants::CHECKOUT_CURRENCY_FIELD] ?? null)
            : null;

        if (is_string($submitted)) {
            $code = strtoupper(sanitize_text_field(wp_unslash($submitted)));
            if (SelectableCurrencyCodes::fromSettings($optionStore->all())->contains($code)) {
                $context = ContextModule::resolveSelectablePreference($optionStore, $code);
                if ($context !== null) {
                    return $context;
                }
            }
        }

        return self::resolveCurrentContext($optionStore);
    }

    private static function resolveCurrentContext(OptionStore $optionStore): CurrencyContext
    {
        return (new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        ))->resolve();
    }

    public static function saveSnapshot($eventData): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $order = FluentCartEvent::extractOrder($eventData);
        if ($order === null) {
            return;
        }

        // If snapshot was captured at checkout, skip
        $existingMeta = $order->getMeta('_fchub_mc_display_currency', '');
        if ($existingMeta !== '' && $existingMeta !== null) {
            return;
        }

        // Fallback for manual/API orders or pre-1.2.1 upgrades:
        // try the order customer's stored preference, validated against enabled currencies
        $userId = (int) ($order->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        $prefRepo = new PreferenceRepository();
        $preferredCode = $prefRepo->getUserMeta($userId);
        if ($preferredCode === null) {
            return;
        }

        $optionStore = new OptionStore();
        $context = ContextModule::resolveSelectablePreference($optionStore, $preferredCode);
        if ($context === null || $context->isBaseDisplay) {
            return;
        }

        $contextService = new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );
        $action = new SaveOrderSnapshotAction($contextService);
        $action->execute($order, $context);
    }
}
