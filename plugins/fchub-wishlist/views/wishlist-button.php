<?php
/**
 * "Add to Wishlist" button partial for single product pages.
 *
 * @var int    $productId
 * @var int    $defaultVariantId
 * @var string $buttonText
 * @var string $heartSvg
 */

defined('ABSPATH') || exit;
?>
<div class="fchub-wishlist-add-wrap">
    <button class="fchub-wishlist-add-button"
            data-fchub-wishlist-toggle
            data-product-id="<?= esc_attr((string) $productId) ?>"
            data-variant-id="<?= esc_attr((string) $defaultVariantId) ?>"
            aria-label="<?= esc_attr__('Add to wishlist', 'fchub-wishlist') ?>">
        <?= $heartSvg // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG filtered via fchub_wishlist/heart_icon_svg ?>
        <span class="fchub-wishlist-add-text"><?= esc_html($buttonText) ?></span>
    </button>
</div>
