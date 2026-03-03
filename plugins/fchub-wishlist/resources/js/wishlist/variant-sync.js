/**
 * Variant synchronisation.
 * Listens for FluentCart product variant change and modal events
 * to keep wishlist button data-variant-id attributes in sync.
 */

import UiState from './ui-state.js';

var VariantSync = {
    /**
     * Initialise event listeners for FluentCart variant and modal events.
     */
    init() {
        // When user selects a different variant on single product page
        document.addEventListener('fluentCartSingleProductVariationChanged', function (e) {
            var detail = e.detail || {};
            var productId = parseInt(detail.productId, 10);
            var variationId = parseInt(detail.variationId, 10);

            if (!productId || !variationId) return;

            // Update data-variant-id on all matching wishlist buttons for this product
            var buttons = document.querySelectorAll(
                '[data-fchub-wishlist-toggle][data-product-id="' + productId + '"]'
            );

            for (var i = 0; i < buttons.length; i++) {
                buttons[i].setAttribute('data-variant-id', String(variationId));

                // Re-apply active state for the new variant
                if (UiState.has(productId, variationId)) {
                    buttons[i].classList.add('is-active');
                    buttons[i].setAttribute('aria-pressed', 'true');
                } else {
                    buttons[i].classList.remove('is-active');
                    buttons[i].setAttribute('aria-pressed', 'false');
                }
            }
        });

        // When quick-view modal opens — re-apply active states to newly injected buttons
        document.addEventListener('fluentCartSingleProductModalOpened', function (e) {
            var detail = e.detail || {};
            var productId = parseInt(detail.productId, 10);

            if (!productId) return;

            // Small delay to let the modal render its content (hooks fire inside)
            setTimeout(function () {
                var buttons = document.querySelectorAll(
                    '[data-fchub-wishlist-toggle][data-product-id="' + productId + '"]'
                );

                for (var i = 0; i < buttons.length; i++) {
                    var vid = parseInt(buttons[i].getAttribute('data-variant-id'), 10) || 0;
                    if (UiState.has(productId, vid)) {
                        buttons[i].classList.add('is-active');
                        buttons[i].setAttribute('aria-pressed', 'true');
                    } else {
                        buttons[i].classList.remove('is-active');
                        buttons[i].setAttribute('aria-pressed', 'false');
                    }
                }
            }, 100);
        });
    },
};

export default VariantSync;
