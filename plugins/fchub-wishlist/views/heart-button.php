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
        data-product-id="<?php echo esc_attr((string) $productId); ?>"
        data-variant-id="<?php echo esc_attr((string) $defaultVariantId); ?>"
        data-label-add="<?php echo esc_attr($addLabel); ?>"
        data-label-remove="<?php echo esc_attr($removeLabel); ?>"
        aria-label="<?php echo esc_attr($addLabel); ?>"
        title="<?php echo esc_attr($addLabel); ?>">
    <?php echo $heartSvg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG filtered via fchub_wishlist/heart_icon_svg ?>
</button>
