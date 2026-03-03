/**
 * REST API client for wishlist operations.
 * Uses the fchubWishlistVars global injected by AssetLoader.
 */

const ApiClient = {
    /**
     * Toggle a product in the wishlist (add if absent, remove if present).
     *
     * @param {number} productId
     * @param {number} variantId
     * @returns {Promise<{success: boolean, data: {action: string, item: object|null, count: number}}>}
     */
    toggle(productId, variantId) {
        return this._post('items/toggle', {
            product_id: productId,
            variant_id: variantId || 0,
        });
    },

    /**
     * Get current wishlist status (list of product/variant pairs + count).
     *
     * @returns {Promise<{success: boolean, data: {items: Array, count: number}}>}
     */
    getStatus() {
        return this._get('status');
    },

    /**
     * Get wishlist items with full product data, paginated.
     *
     * @param {number} page
     * @returns {Promise<{success: boolean, data: {items: Array, total: number}}>}
     */
    getItems(page) {
        return this._get('items?page=' + (page || 1));
    },

    /**
     * Add all wishlist items to the FluentCart cart.
     *
     * @returns {Promise<{success: boolean, data: {added: number, failed: Array}}>}
     */
    addAllToCart() {
        return this._post('add-all-to-cart', {});
    },

    /**
     * Remove a specific item from the wishlist.
     *
     * @param {number} productId
     * @param {number} variantId
     * @returns {Promise<{success: boolean, data: {count: number}}>}
     */
    remove(productId, variantId) {
        return this._delete('items', {
            product_id: productId,
            variant_id: variantId || 0,
        });
    },

    /**
     * @param {string} endpoint
     * @returns {Promise<object>}
     */
    _get(endpoint) {
        return fetch(this._url(endpoint), {
            method: 'GET',
            headers: this._headers(),
            credentials: 'same-origin',
        }).then(this._handleResponse);
    },

    /**
     * @param {string} endpoint
     * @param {object} body
     * @returns {Promise<object>}
     */
    _post(endpoint, body) {
        return fetch(this._url(endpoint), {
            method: 'POST',
            headers: this._headers(),
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).then(this._handleResponse);
    },

    /**
     * @param {string} endpoint
     * @param {object} body
     * @returns {Promise<object>}
     */
    _delete(endpoint, body) {
        return fetch(this._url(endpoint), {
            method: 'DELETE',
            headers: this._headers(),
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).then(this._handleResponse);
    },

    /**
     * @param {string} endpoint
     * @returns {string}
     */
    _url(endpoint) {
        var base = (window.fchubWishlistVars && window.fchubWishlistVars.restUrl) || '/wp-json/fchub-wishlist/v1/';
        return base + endpoint;
    },

    /**
     * @returns {object}
     */
    _headers() {
        var headers = { 'Content-Type': 'application/json' };
        var nonce = window.fchubWishlistVars && window.fchubWishlistVars.nonce;
        if (nonce) {
            headers['X-WP-Nonce'] = nonce;
        }
        return headers;
    },

    /**
     * @param {Response} response
     * @returns {Promise<object>}
     */
    _handleResponse(response) {
        return response.json().then(function (data) {
            if (!response.ok) {
                return Promise.reject(data);
            }
            return data;
        });
    },
};

export default ApiClient;
