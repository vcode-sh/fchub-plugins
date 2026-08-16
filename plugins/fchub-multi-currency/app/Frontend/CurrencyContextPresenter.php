<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Frontend;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class CurrencyContextPresenter
{
    public static function resolveContext(): \FChubMultiCurrency\Domain\ValueObjects\CurrencyContext
    {
        $optionStore = new OptionStore();
        $service = new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );

        return $service->resolve();
    }

    /**
     * @return array{code: string, name: string, symbol: string, flag: string, is_base_display: bool}
     */
    public static function currentCurrencyParts(): array
    {
        $context = self::resolveContext();

        return [
            'code' => $context->displayCurrency->code,
            'name' => $context->displayCurrency->name,
            'symbol' => $context->displayCurrency->symbol,
            'flag' => CurrencyCatalogueController::codeToFlagImg($context->displayCurrency->code),
            'is_base_display' => $context->isBaseDisplay,
        ];
    }

    public static function renderCurrentCurrency(string $displayMode = 'flag_code'): string
    {
        return CurrencyContextPresentation::renderCurrent(self::resolveContext(), $displayMode);
    }

    public static function renderRateValue(int $precision = 4, string $format = 'compact', bool $hideWhenBase = false): string
    {
        $context = self::resolveContext();

        if ($hideWhenBase && $context->isBaseDisplay) {
            return '';
        }

        return CurrencyContextPresentation::renderRate($context, $precision, $format);
    }

    public static function renderNotice(string $mode = 'compact', bool $hideWhenBase = true): string
    {
        $context = self::resolveContext();
        if ($hideWhenBase && $context->isBaseDisplay) {
            return '';
        }

        return CurrencyContextPresentation::renderNotice($context, new OptionStore(), $mode);
    }
}
