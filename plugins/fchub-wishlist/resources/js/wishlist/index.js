/**
 * FCHub Wishlist — frontend entry point.
 * Composes all modules: API client, UI state, toggle handler,
 * page handler, variant sync, counter sync.
 */

import ApiClient from './api-client.js';
import UiState from './ui-state.js';
import ToggleHandler from './toggle-handler.js';
import PageHandler from './page-handler.js';
import VariantSync from './variant-sync.js';
import CounterSync from './counter-sync.js';

document.addEventListener('DOMContentLoaded', function () {
    // Load initial wishlist state from API
    ApiClient.getStatus()
        .then(function (res) {
            var data = res.data || res;
            UiState.load(data.items || []);
            CounterSync.update(data.count != null ? data.count : UiState.getCount());
        })
        .catch(function () {
            // Silent fail — buttons work without initial state, just no .is-active pre-applied
        });

    // Initialise click handlers (event delegation)
    ToggleHandler.init();

    // Initialise wishlist page actions (remove, add-to-cart, add-all)
    PageHandler.init();

    // Initialise variant change and modal listeners
    VariantSync.init();
});

// FluentCart renders product listings asynchronously via Vue.
// When the app finishes rendering, it fires this event — re-sync hearts.
document.addEventListener('fluent_cart_app_loaded', function () {
    UiState._syncDom();
});
