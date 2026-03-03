<?php
/**
 * Heart button partial for product cards.
 *
 * @var int    $productId
 * @var int    $defaultVariantId
 * @var string $heartSvg
 */

defined('ABSPATH') || exit;
?>
<button class="fchub-wishlist-heart"
        data-fchub-wishlist-toggle
        data-product-id="<?= esc_attr((string) $productId) ?>"
        data-variant-id="<?= esc_attr((string) $defaultVariantId) ?>"
        aria-label="<?= esc_attr__('Add to wishlist', 'fchub-wishlist') ?>"
        title="<?= esc_attr__('Add to wishlist', 'fchub-wishlist') ?>">
    <?= $heartSvg // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG filtered via fchub_wishlist/heart_icon_svg ?>
</button>
