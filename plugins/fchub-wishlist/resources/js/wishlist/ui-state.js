/**
 * In-memory wishlist state.
 * Tracks which product+variant combinations are wishlisted and updates DOM accordingly.
 */

var _items = new Map();

var UiState = {
    /**
     * Load initial state from API response items.
     *
     * @param {Array<{product_id: number, variant_id: number}>} items
     */
    load(items) {
        _items.clear();
        if (!items || !items.length) {
            this._syncDom();
            return;
        }
        for (var i = 0; i < items.length; i++) {
            var key = this._key(items[i].product_id, items[i].variant_id);
            _items.set(key, true);
        }
        this._syncDom();
    },

    /**
     * Check if a product+variant is in the wishlist.
     *
     * @param {number} productId
     * @param {number} variantId
     * @returns {boolean}
     */
    has(productId, variantId) {
        return _items.has(this._key(productId, variantId));
    },

    /**
     * Add a product+variant to state and update DOM.
     *
     * @param {number} productId
     * @param {number} variantId
     */
    add(productId, variantId) {
        _items.set(this._key(productId, variantId), true);
        this._syncDom();
    },

    /**
     * Remove a product+variant from state and update DOM.
     *
     * @param {number} productId
     * @param {number} variantId
     */
    remove(productId, variantId) {
        _items.delete(this._key(productId, variantId));
        this._syncDom();
    },

    /**
     * Get total item count.
     *
     * @returns {number}
     */
    getCount() {
        return _items.size;
    },

    /**
     * Sync DOM elements with current state.
     * Adds/removes .is-active class on all toggle buttons.
     */
    _syncDom() {
        var buttons = document.querySelectorAll('[data-fchub-wishlist-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            var btn = buttons[i];
            var pid = parseInt(btn.getAttribute('data-product-id'), 10);
            var vid = parseInt(btn.getAttribute('data-variant-id'), 10) || 0;
            var labels = this._labels(btn);
            var textEl = btn.querySelector('.fchub-wishlist-add-text');

            if (this.has(pid, vid)) {
                btn.classList.add('is-active');
                btn.setAttribute('aria-pressed', 'true');
                btn.setAttribute('aria-label', labels.remove);
                btn.setAttribute('title', labels.remove);
                if (textEl) {
                    textEl.textContent = labels.remove;
                }
            } else {
                btn.classList.remove('is-active');
                btn.setAttribute('aria-pressed', 'false');
                btn.setAttribute('aria-label', labels.add);
                btn.setAttribute('title', labels.add);
                if (textEl) {
                    textEl.textContent = labels.add;
                }
            }
        }
    },

    /**
     * Resolve add/remove labels from element attrs with localized fallback.
     *
     * @param {Element} btn
     * @returns {{add: string, remove: string}}
     */
    _labels(btn) {
        var i18n = (window.fchubWishlistVars && window.fchubWishlistVars.i18n) || {};
        var addLabel = btn.getAttribute('data-label-add') || i18n.add || 'Add to Wishlist';
        var removeLabel = btn.getAttribute('data-label-remove') || i18n.remove || 'Remove from Wishlist';

        return { add: addLabel, remove: removeLabel };
    },

    /**
     * @param {number} productId
     * @param {number} variantId
     * @returns {string}
     */
    _key(productId, variantId) {
        return productId + '-' + (variantId || 0);
    },
};

export default UiState;
