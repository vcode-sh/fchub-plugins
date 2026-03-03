/**
 * Click delegation handler for wishlist toggle buttons.
 * Implements optimistic UI updates with rollback on failure.
 */

import ApiClient from './api-client.js';
import UiState from './ui-state.js';
import CounterSync from './counter-sync.js';

var _debounceTimers = new Map();
var DEBOUNCE_MS = 300;

var ToggleHandler = {
    /**
     * Initialise event delegation on document for all wishlist toggle buttons.
     */
    init: function () {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-fchub-wishlist-toggle]');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            var productId = parseInt(btn.getAttribute('data-product-id'), 10);
            var variantId = parseInt(btn.getAttribute('data-variant-id'), 10) || 0;

            if (!productId) return;

            var key = productId + '-' + variantId;

            // Debounce rapid clicks
            if (_debounceTimers.has(key)) {
                clearTimeout(_debounceTimers.get(key));
            }

            _debounceTimers.set(key, setTimeout(function () {
                _debounceTimers.delete(key);
                ToggleHandler._toggle(productId, variantId);
            }, DEBOUNCE_MS));
        });
    },

    /**
     * @param {number} productId
     * @param {number} variantId
     */
    _toggle: function (productId, variantId) {
        var wasActive = UiState.has(productId, variantId);

        // Optimistic update
        if (wasActive) {
            UiState.remove(productId, variantId);
        } else {
            UiState.add(productId, variantId);
        }
        CounterSync.update(UiState.getCount());

        ApiClient.toggle(productId, variantId)
            .then(function (res) {
                var data = res.data || res;
                CounterSync.update(data.count != null ? data.count : UiState.getCount());
            })
            .catch(function () {
                // Rollback on error
                if (wasActive) {
                    UiState.add(productId, variantId);
                } else {
                    UiState.remove(productId, variantId);
                }
                CounterSync.update(UiState.getCount());
            });
    },
};

export default ToggleHandler;
