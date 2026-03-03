<?php
/**
 * Wishlist counter badge partial.
 * JS populates the count from the /status endpoint response.
 */

defined('ABSPATH') || exit;
?>
<span class="fchub-wishlist-counter" data-fchub-wishlist-count aria-label="<?= esc_attr__('Wishlist items', 'fchub-wishlist') ?>"></span>
