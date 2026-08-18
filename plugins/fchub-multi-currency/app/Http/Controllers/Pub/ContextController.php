<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Pub;

use FChubMultiCurrency\Domain\Actions\PersistContextAction;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\Services\CurrencyResolution;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Frontend\CurrencyContextPayload;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Support\EventLogger;
use FChubMultiCurrency\Support\Hooks;
use FChubMultiCurrency\Support\IpResolver;

defined('ABSPATH') || exit;

final class ContextController
{
    /**
     * Machine-readable outcome codes. Clients branch on these; the message is
     * for humans and is translated, so matching on it would break the moment
     * somebody installs a language pack.
     */
    public const CODE_SAVED = 'preference_saved';
    public const CODE_MODULE_DISABLED = 'module_disabled';
    public const CODE_RATE_LIMITED = 'rate_limited';
    public const CODE_INVALID_PAYLOAD = 'invalid_payload';
    public const CODE_CURRENCY_REQUIRED = 'currency_required';
    public const CODE_INVALID_CURRENCY = 'invalid_currency';
    public const CODE_RATE_UNAVAILABLE = 'rate_unavailable';
    public const CODE_PERSISTENCE_UNAVAILABLE = 'persistence_unavailable';

    public function get(\WP_REST_Request $request): \WP_REST_Response
    {
        $optionStore = new OptionStore();
        $requestedCurrency = $request->get_param('currency');

        if ($requestedCurrency !== null) {
            $currencyCode = strtoupper(sanitize_text_field((string) $requestedCurrency));
            if (!SelectableCurrencyCodes::fromSettings($optionStore->all())->contains($currencyCode)) {
                return self::failure(
                    self::CODE_INVALID_CURRENCY,
                    __('Invalid currency code.', 'fchub-multi-currency'),
                    422,
                );
            }

            $context = CurrencyContextService::applyContextFilter(
                CurrencyResolution::explicitPreference($optionStore, $currencyCode),
            );
        } else {
            $context = CurrencyContextService::forVisitor($optionStore)->resolve();
        }

        return self::response([
            'data' => [
                'display_currency' => $context->displayCurrency->code,
                'base_currency'    => $context->baseCurrency->code,
                'rate'             => $context->rate->rate,
                'source'           => $context->source->value,
                'is_base_display'  => $context->isBaseDisplay,
                'context'          => CurrencyContextPayload::buildRecovery($context, $optionStore),
            ],
        ]);
    }

    public function set(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!Hooks::isEnabled()) {
            return self::failure(
                self::CODE_MODULE_DISABLED,
                __('Multi-Currency is disabled.', 'fchub-multi-currency'),
                403,
            );
        }

        // Rate limit: 30 requests per minute per resolved client IP, plus a
        // coarser ceiling on the socket address. The first is fair; the second
        // survives a client rotating forwarding headers to mint fresh buckets.
        $ip = IpResolver::resolve();
        $rateLimitKey = 'fchub_mc_rl_' . substr(md5($ip), 0, 12);
        $hits = (int) get_transient($rateLimitKey);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Hashed immediately; never echoed or stored raw.
        $socketAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $backstopKey = 'fchub_mc_rlb_' . substr(md5($socketAddress), 0, 12);
        $backstopHits = (int) get_transient($backstopKey);

        if ($hits >= 30 || $backstopHits >= 120) {
            return self::failure(
                self::CODE_RATE_LIMITED,
                __('Too many requests. Please try again later.', 'fchub-multi-currency'),
                429,
            );
        }

        // Every attempt spends budget, not just the ones that persist. The
        // rate_unavailable branch below writes an event-log row, and a client
        // whose failures never counted could write those rows without limit.
        set_transient($rateLimitKey, $hits + 1, MINUTE_IN_SECONDS);
        set_transient($backstopKey, $backstopHits + 1, MINUTE_IN_SECONDS);

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return self::failure(
                self::CODE_INVALID_PAYLOAD,
                __('Invalid JSON payload.', 'fchub-multi-currency'),
                400,
            );
        }

        $currencyCode = isset($params['currency']) ? sanitize_text_field($params['currency']) : '';

        if ($currencyCode === '') {
            return self::failure(
                self::CODE_CURRENCY_REQUIRED,
                __('Currency code is required.', 'fchub-multi-currency'),
                422,
            );
        }

        $currencyCode = strtoupper($currencyCode);

        $optionStore = new OptionStore();
        if (!SelectableCurrencyCodes::fromSettings($optionStore->all())->contains($currencyCode)) {
            return self::failure(
                self::CODE_INVALID_CURRENCY,
                __('Invalid currency code.', 'fchub-multi-currency'),
                422,
            );
        }

        if (CurrencyResolution::selectablePreference($optionStore, $currencyCode) === null) {
            EventLogger::log('context_switch_rate_unavailable', get_current_user_id(), [
                'currency' => $currencyCode,
                'source' => 'rest',
            ]);

            return self::failure(
                self::CODE_RATE_UNAVAILABLE,
                __(
                    'This currency cannot be selected because no usable exchange rate is available. Please try again later.',
                    'fchub-multi-currency',
                ),
                409,
                [
                    'currency'  => $currencyCode,
                    'persisted' => false,
                ],
            );
        }

        $action = new PersistContextAction(new PreferenceRepository(), $optionStore);
        $result = $action->execute($currencyCode);

        $userId = get_current_user_id();

        // Nowhere to put the preference — typically a logged-out visitor while cookie persistence
        // is disabled. Saying "saved" here is what made the switcher look broken: the next request
        // simply resolved back to the default currency.
        if (!$result->persisted()) {
            EventLogger::log('context_switch_not_persisted', $userId, [
                'currency' => $currencyCode,
                'source' => 'rest',
            ]);

            // 409 rather than 403: the module is working and another visitor on this
            // very site would succeed. What blocks this one is the current persistence
            // configuration, which the visitor can resolve by signing in and the site
            // owner can resolve in settings. 403 is reserved for the module being off.
            return self::failure(
                self::CODE_PERSISTENCE_UNAVAILABLE,
                $userId > 0
                    ? __(
                        'Currency preference could not be saved because both cookie and account persistence are disabled.',
                        'fchub-multi-currency',
                    )
                    : __(
                        'Currency preference could not be saved. Cookie persistence is disabled, so logged-out visitors cannot keep a currency choice.',
                        'fchub-multi-currency',
                    ),
                409,
                [
                    'currency'  => $currencyCode,
                    'persisted' => false,
                ],
            );
        }

        CurrencyContextService::reset();

        do_action('fchub_mc/context_switched', $currencyCode, $userId);
        EventLogger::log('context_switched', $userId, [
            'currency' => $currencyCode,
            'source' => 'rest',
        ]);

        return self::response([
            'data' => [
                'code'      => self::CODE_SAVED,
                'message'   => __('Currency preference saved.', 'fchub-multi-currency'),
                'currency'  => $currencyCode,
                'persisted' => true,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function failure(string $code, string $message, int $status, array $extra = []): \WP_REST_Response
    {
        return self::response([
            'data' => array_merge(
                [
                    'code'    => $code,
                    'message' => $message,
                ],
                $extra,
            ),
        ], $status);
    }

    /**
     * Context responses must never be reused across visitors or preferences.
     *
     * @param array<string, mixed> $data
     */
    private static function response(array $data, int $status = 200): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
