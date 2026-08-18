<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class CheckoutDisclosureService
{
    public function __construct(
        private OptionStore $optionStore,
    ) {
    }

    public function getDisclosure(CurrencyContext $context): ?string
    {
        $settings = $this->optionStore->all();

        if (($settings['checkout_disclosure_enabled'] ?? 'yes') !== 'yes') {
            return null;
        }

        if ($context->isBaseDisplay) {
            return null;
        }

        $template = $settings['checkout_disclosure_text']
            // translators: {base_currency} is a literal placeholder token replaced with the currency code.
            ?? __('Your payment will be processed in {base_currency}.', 'fchub-multi-currency');

        $text = str_replace(
            ['{base_currency}', '{display_currency}', '{rate}'],
            [
                esc_html($context->baseCurrency->code),
                esc_html($context->displayCurrency->code),
                esc_html($context->rate->rate),
            ],
            $template,
        );

        return wp_kses($text, [
            'strong' => [],
            'em'     => [],
            'br'     => [],
            'span'   => ['class' => true],
        ]);
    }
}
