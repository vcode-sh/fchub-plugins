/**
 * FCHub Multi-Currency — Currency Switcher
 *
 * Handles currency selection UI, POSTs to REST API, fires context change event.
 *
 * Fires: fchub_mc:context_changed
 */
(function () {
    'use strict';

    const config = window.fchubMcConfig || {};
    const restUrl = config.restUrl || '/wp-json/fchub-mc/v1';
    const nonce = config.nonce || '';

    function switchCurrency(currencyCode) {
        return fetch(restUrl + '/context', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            body: JSON.stringify({ currency: currencyCode }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                document.dispatchEvent(new CustomEvent('fchub_mc:context_changed', {
                    detail: { currency: currencyCode, response: data },
                }));

                // Reload to reflect new prices
                window.location.reload();
            });
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches('[data-fchub-mc-switcher]')) {
            switchCurrency(e.target.value);
        }
    });

    // Expose for programmatic use
    window.fchubMcSwitchCurrency = switchCurrency;
})();
