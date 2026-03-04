/**
 * FCHub Multi-Currency — Price Projection
 *
 * Reads data-fchub-mc-base attributes from price elements,
 * multiplies by the current exchange rate, and formats for display.
 *
 * Fires: fchub_mc:prices_projected
 */
(function () {
    'use strict';

    const config = window.fchubMcConfig || {};
    const rate = parseFloat(config.rate || '1');
    const displayCurrency = config.displayCurrency || '';
    const baseCurrency = config.baseCurrency || '';
    const decimals = parseInt(config.decimals || '2', 10);
    const symbol = config.symbol || '';
    const position = config.position || 'left';

    if (!displayCurrency || rate === 1 || displayCurrency === baseCurrency) {
        return;
    }

    function formatPrice(amount) {
        const formatted = amount.toFixed(decimals);
        switch (position) {
            case 'left':
                return symbol + formatted;
            case 'right':
                return formatted + symbol;
            case 'left_space':
                return symbol + ' ' + formatted;
            case 'right_space':
                return formatted + ' ' + symbol;
            default:
                return symbol + formatted;
        }
    }

    function projectPrices() {
        const elements = document.querySelectorAll('[data-fchub-mc-base]');

        elements.forEach(function (el) {
            const baseAmount = parseFloat(el.getAttribute('data-fchub-mc-base'));

            if (isNaN(baseAmount)) {
                return;
            }

            const projected = baseAmount * rate;
            el.textContent = formatPrice(projected);
            el.setAttribute('data-fchub-mc-projected', projected.toString());
        });

        document.dispatchEvent(new CustomEvent('fchub_mc:prices_projected', {
            detail: { rate: rate, currency: displayCurrency },
        }));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', projectPrices);
    } else {
        projectPrices();
    }
})();
