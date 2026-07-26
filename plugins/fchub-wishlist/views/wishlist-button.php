<?php
/**
 * "Add to Wishlist" button partial for single product pages.
 *
 * @var int    $productId
 * @var int    $defaultVariantId
 * @var string $buttonText
 * @var string $buttonTextRemove
 * @var string $heartSvg
 */

defined('ABSPATH') || exit;
?>
<div class="fchub-wishlist-add-wrap">
    <button class="fchub-wishlist-add-button"
            data-fchub-wishlist-toggle
            data-product-id="<?php echo esc_attr((string) $productId); ?>"
            data-variant-id="<?php echo esc_attr((string) $defaultVariantId); ?>"
            data-label-add="<?php echo esc_attr($buttonText); ?>"
            data-label-remove="<?php echo esc_attr($buttonTextRemove); ?>"
            aria-label="<?php echo esc_attr($buttonText); ?>">
        <?php echo $heartSvg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG filtered via fchub_wishlist/heart_icon_svg ?>
        <span class="fchub-wishlist-add-text"><?php echo esc_html($buttonText); ?></span>
    </button>
</div>
