<?php
/**
 * Heart button partial for product cards.
 *
 * @var int    $productId
 * @var int    $defaultVariantId
 * @var string $addLabel
 * @var string $removeLabel
 * @var string $heartSvg
 */

defined('ABSPATH') || exit;
?>
<button class="fchub-wishlist-heart"
        data-fchub-wishlist-toggle
        data-product-id="<?= esc_attr((string) $productId) ?>"
        data-variant-id="<?= esc_attr((string) $defaultVariantId) ?>"
        data-label-add="<?= esc_attr($addLabel) ?>"
        data-label-remove="<?= esc_attr($removeLabel) ?>"
        aria-label="<?= esc_attr($addLabel) ?>"
        title="<?= esc_attr($addLabel) ?>">
    <?= $heartSvg // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG filtered via fchub_wishlist/heart_icon_svg ?>
</button>
