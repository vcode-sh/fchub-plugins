/**
 * FCHub Wishlist v1.0.0
 * Bundled production build — IIFE wrapper around all modules.
 */
(function () {
    'use strict';

    /* ========================================================================
       Counter Sync
       ======================================================================== */

    var CounterSync = {
        update: function (count) {
            var badges = document.querySelectorAll('[data-fchub-wishlist-count]');
            var text = count > 0 ? String(count) : '';

            for (var i = 0; i < badges.length; i++) {
                badges[i].textContent = text;
                if (count > 0) {
                    badges[i].classList.add('has-items');
                } else {
                    badges[i].classList.remove('has-items');
                }
            }
        }
    };

    /* ========================================================================
       UI State
       ======================================================================== */

    var _items = new Map();

    var UiState = {
        load: function (items) {
            _items.clear();
            if (items && items.length) {
                for (var i = 0; i < items.length; i++) {
                    _items.set(this._key(items[i].product_id, items[i].variant_id), true);
                }
            }
            this._syncDom();
        },

        has: function (productId, variantId) {
            return _items.has(this._key(productId, variantId));
        },

        add: function (productId, variantId) {
            _items.set(this._key(productId, variantId), true);
            this._syncDom();
        },

        remove: function (productId, variantId) {
            _items.delete(this._key(productId, variantId));
            this._syncDom();
        },

        getCount: function () {
            return _items.size;
        },

        _syncDom: function () {
            var buttons = document.querySelectorAll('[data-fchub-wishlist-toggle]');
            for (var i = 0; i < buttons.length; i++) {
                var btn = buttons[i];
                var pid = parseInt(btn.getAttribute('data-product-id'), 10);
                var vid = parseInt(btn.getAttribute('data-variant-id'), 10) || 0;
                if (this.has(pid, vid)) {
                    btn.classList.add('is-active');
                    btn.setAttribute('aria-pressed', 'true');
                } else {
                    btn.classList.remove('is-active');
                    btn.setAttribute('aria-pressed', 'false');
                }
            }
        },

        _key: function (productId, variantId) {
            return productId + '-' + (variantId || 0);
        }
    };

    /* ========================================================================
       API Client
       ======================================================================== */

    var ApiClient = {
        toggle: function (productId, variantId) {
            return this._post('items/toggle', {
                product_id: productId,
                variant_id: variantId || 0
            });
        },

        getStatus: function () {
            return this._get('status');
        },

        getItems: function (page) {
            return this._get('items?page=' + (page || 1));
        },

        addAllToCart: function () {
            return this._post('add-all-to-cart', {});
        },

        remove: function (productId, variantId) {
            return this._delete('items', {
                product_id: productId,
                variant_id: variantId || 0
            });
        },

        _get: function (endpoint) {
            return fetch(this._url(endpoint), {
                method: 'GET',
                headers: this._headers(),
                credentials: 'same-origin'
            }).then(this._handleResponse);
        },

        _post: function (endpoint, body) {
            return fetch(this._url(endpoint), {
                method: 'POST',
                headers: this._headers(),
                credentials: 'same-origin',
                body: JSON.stringify(body)
            }).then(this._handleResponse);
        },

        _delete: function (endpoint, body) {
            return fetch(this._url(endpoint), {
                method: 'DELETE',
                headers: this._headers(),
                credentials: 'same-origin',
                body: JSON.stringify(body)
            }).then(this._handleResponse);
        },

        _url: function (endpoint) {
            var base = (window.fchubWishlistVars && window.fchubWishlistVars.restUrl) || '/wp-json/fchub-wishlist/v1/';
            return base + endpoint;
        },

        _headers: function () {
            var headers = { 'Content-Type': 'application/json' };
            var nonce = window.fchubWishlistVars && window.fchubWishlistVars.nonce;
            if (nonce) {
                headers['X-WP-Nonce'] = nonce;
            }
            return headers;
        },

        _handleResponse: function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    return Promise.reject(data);
                }
                return data;
            });
        }
    };

    /* ========================================================================
       Toggle Handler
       ======================================================================== */

    var _debounceTimers = new Map();
    var DEBOUNCE_MS = 300;

    var ToggleHandler = {
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
                if (_debounceTimers.has(key)) {
                    clearTimeout(_debounceTimers.get(key));
                }

                _debounceTimers.set(key, setTimeout(function () {
                    _debounceTimers.delete(key);
                    ToggleHandler._toggle(productId, variantId);
                }, DEBOUNCE_MS));
            });
        },

        _toggle: function (productId, variantId) {
            var wasActive = UiState.has(productId, variantId);

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
                    if (wasActive) {
                        UiState.add(productId, variantId);
                    } else {
                        UiState.remove(productId, variantId);
                    }
                    CounterSync.update(UiState.getCount());
                });
        }
    };

    /* ========================================================================
       Page Handler (remove, add-to-cart, add-all)
       ======================================================================== */

    var PageHandler = {
        init: function () {
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-fchub-wishlist-remove]');
                if (!btn) return;

                e.preventDefault();
                var productId = parseInt(btn.getAttribute('data-product-id'), 10);
                var variantId = parseInt(btn.getAttribute('data-variant-id'), 10) || 0;
                if (!productId) return;

                PageHandler._remove(productId, variantId, btn);
            });

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-fchub-wishlist-add-to-cart]');
                if (!btn) return;

                e.preventDefault();
                var variantId = parseInt(btn.getAttribute('data-variant-id'), 10);
                if (!variantId) return;

                PageHandler._addToCart(variantId, btn);
            });

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-fchub-wishlist-add-all]');
                if (!btn) return;

                e.preventDefault();
                PageHandler._addAllToCart(btn);
            });
        },

        _remove: function (productId, variantId, btn) {
            var itemEl = btn.closest('[data-fchub-wishlist-item]');

            UiState.remove(productId, variantId);
            if (itemEl) itemEl.classList.add('fchub-wishlist-item--removing');

            ApiClient.remove(productId, variantId)
                .then(function (res) {
                    var data = res.data || res;
                    CounterSync.update(data.count != null ? data.count : UiState.getCount());
                    if (itemEl) itemEl.remove();

                    var page = document.querySelector('[data-fchub-wishlist-page]');
                    if (page && !page.querySelector('[data-fchub-wishlist-item]')) {
                        location.reload();
                    }
                })
                .catch(function () {
                    UiState.add(productId, variantId);
                    if (itemEl) itemEl.classList.remove('fchub-wishlist-item--removing');
                });
        },

        _addToCart: function (variantId, btn) {
            btn.disabled = true;

            if (window.FluentCartCart && window.FluentCartCart.addProduct) {
                window.FluentCartCart.addProduct(variantId, 1);
                setTimeout(function () { btn.disabled = false; }, 1000);
                return;
            }

            var ajaxUrl = (window.fchubWishlistVars && window.fchubWishlistVars.ajaxUrl) || '/wp-admin/admin-ajax.php';
            var params = new URLSearchParams();
            params.set('action', 'fluent_cart_checkout_routes');
            params.set('fc_checkout_action', 'fluent_cart_cart_update');
            params.set('item_id', String(variantId));
            params.set('quantity', '1');

            fetch(ajaxUrl + '?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin'
            }).finally(function () {
                btn.disabled = false;
            });
        },

        _addAllToCart: function (btn) {
            btn.disabled = true;
            ApiClient.addAllToCart()
                .then(function () { btn.disabled = false; })
                .catch(function () { btn.disabled = false; });
        }
    };

    /* ========================================================================
       Variant Sync
       ======================================================================== */

    var VariantSync = {
        init: function () {
            document.addEventListener('fluentCartSingleProductVariationChanged', function (e) {
                var detail = e.detail || {};
                var productId = parseInt(detail.productId, 10);
                var variationId = parseInt(detail.variationId, 10);
                if (!productId || !variationId) return;

                var buttons = document.querySelectorAll(
                    '[data-fchub-wishlist-toggle][data-product-id="' + productId + '"]'
                );

                for (var i = 0; i < buttons.length; i++) {
                    buttons[i].setAttribute('data-variant-id', String(variationId));
                    if (UiState.has(productId, variationId)) {
                        buttons[i].classList.add('is-active');
                        buttons[i].setAttribute('aria-pressed', 'true');
                    } else {
                        buttons[i].classList.remove('is-active');
                        buttons[i].setAttribute('aria-pressed', 'false');
                    }
                }
            });

            document.addEventListener('fluentCartSingleProductModalOpened', function (e) {
                var detail = e.detail || {};
                var productId = parseInt(detail.productId, 10);
                if (!productId) return;

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
        }
    };

    /* ========================================================================
       Init
       ======================================================================== */

    document.addEventListener('DOMContentLoaded', function () {
        ApiClient.getStatus()
            .then(function (res) {
                var data = res.data || res;
                UiState.load(data.items || []);
                CounterSync.update(data.count != null ? data.count : UiState.getCount());
            })
            .catch(function () {
                // Silent fail — buttons work without pre-loaded state
            });

        ToggleHandler.init();
        PageHandler.init();
        VariantSync.init();
    });

    // FluentCart renders product listings asynchronously via Vue.
    // When the app finishes rendering, it fires this event — re-sync hearts.
    document.addEventListener('fluent_cart_app_loaded', function () {
        UiState._syncDom();
    });

})();
