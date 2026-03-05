import ApiClient from './api-client.js';
import UiState from './ui-state.js';
import CounterSync from './counter-sync.js';

function getErrorMessage(error) {
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message;
    }
    var i18n = (window.fchubWishlistVars && window.fchubWishlistVars.i18n) || {};
    return i18n.error || 'Something went wrong. Please try again.';
}

function notifyError(error) {
    var message = getErrorMessage(error);
    document.dispatchEvent(new CustomEvent('fchub_wishlist:error', {
        detail: { message: message },
    }));
    if (typeof window.alert === 'function') {
        window.alert(message);
    }
}

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
            .catch(function (error) {
                UiState.add(productId, variantId);
                if (itemEl) itemEl.classList.remove('fchub-wishlist-item--removing');
                notifyError(error);
            });
    },

    _addToCart: function (variantId, btn) {
        if (!variantId) return;
        btn.disabled = true;
        PageHandler._pushToCart(variantId)
            .catch(function (error) {
                notifyError(error);
            })
            .finally(function () {
                btn.disabled = false;
            });
    },

    _pushToCart: function (cartItemId) {
        if (window.FluentCartCart && window.FluentCartCart.addProduct) {
            try {
                var result = window.FluentCartCart.addProduct(cartItemId, 1);
                return Promise.resolve(result);
            } catch (e) {
                return Promise.reject(e);
            }
        }
        var ajaxUrl = (window.fchubWishlistVars && window.fchubWishlistVars.ajaxUrl) || '/wp-admin/admin-ajax.php';
        var cartAjax = (window.fchubWishlistVars && window.fchubWishlistVars.cartAjax) || {};
        var action = cartAjax.action || 'fluent_cart_checkout_routes';
        var checkoutAction = cartAjax.checkout_action || 'fluent_cart_cart_update';
        var params = new URLSearchParams();
        params.set('action', action);
        params.set('fc_checkout_action', checkoutAction);
        params.set('item_id', String(cartItemId));
        params.set('quantity', '1');

        return fetch(ajaxUrl + '?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin',
        }).then(function (response) {
            if (!response.ok) {
                return Promise.reject({ message: 'Could not add item to cart.' });
            }
                return response;
            });
    },

    _addAllToCart: function (btn) {
        btn.disabled = true;
        ApiClient.addAllToCart()
            .then(function (res) {
                var data = res.data || res;
                var items = Array.isArray(data.items) ? data.items : [];
                var queue = Promise.resolve();
                for (var i = 0; i < items.length; i++) {
                    var item = items[i];
                    var cartItemId = parseInt(item.variant_id || item.product_id, 10);
                    if (!cartItemId) {
                        continue;
                    }
                    queue = queue.then(function (id) {
                        return function () {
                            return PageHandler._pushToCart(id);
                        };
                    }(cartItemId));
                }
                return queue;
            })
            .catch(function (error) {
                notifyError(error);
            })
            .finally(function () {
                btn.disabled = false;
            });
    },
};

export default PageHandler;
