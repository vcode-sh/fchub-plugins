/**
 * Counter badge synchronisation.
 * Updates all [data-fchub-wishlist-count] elements with the current count.
 */

var CounterSync = {
    /**
     * Update all counter badge elements with the given count.
     *
     * @param {number} count
     */
    update(count) {
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
    },
};

export default CounterSync;
