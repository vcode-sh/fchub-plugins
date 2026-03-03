<?php
/**
 * Customer portal wishlist tab template.
 *
 * @var array $items Array of wishlist items with product data
 */

defined('ABSPATH') || exit;
?>
<div class="fchub-wishlist-portal" data-fchub-wishlist-page>
    <?php if (empty($items)) : ?>
        <div class="fchub-wishlist-empty">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <p class="fchub-wishlist-empty__title"><?= esc_html__('Your wishlist is empty', 'fchub-wishlist') ?></p>
            <p class="fchub-wishlist-empty__text"><?= esc_html__('Browse our products and add your favourites here.', 'fchub-wishlist') ?></p>
        </div>
    <?php else : ?>
        <div class="fchub-wishlist-portal__header">
            <h3 class="fchub-wishlist-portal__title">
                <?= esc_html(sprintf(
                    __('My Wishlist (%d)', 'fchub-wishlist'),
                    count($items)
                )) ?>
            </h3>
            <button class="fchub-wishlist-add-all-btn" data-fchub-wishlist-add-all type="button">
                <?= esc_html__('Add All to Cart', 'fchub-wishlist') ?>
            </button>
        </div>

        <div class="fchub-wishlist-portal__grid">
            <?php foreach ($items as $item) : ?>
                <?php
                $isAvailable = ($item['product_status'] === 'publish' && $item['variant_status'] === 'active');
                $permalink = get_permalink($item['product_id']);
                $thumbnail = get_the_post_thumbnail_url($item['product_id'], 'thumbnail');
                ?>
                <div class="fchub-wishlist-portal__item"
                     data-fchub-wishlist-item
                     data-product-id="<?= esc_attr((string) $item['product_id']) ?>"
                     data-variant-id="<?= esc_attr((string) $item['variant_id']) ?>">

                    <div class="fchub-wishlist-portal__item-image">
                        <?php if ($thumbnail) : ?>
                            <img src="<?= esc_url($thumbnail) ?>"
                                 alt="<?= esc_attr($item['product_title']) ?>"
                                 loading="lazy"/>
                        <?php endif; ?>
                    </div>

                    <div class="fchub-wishlist-portal__item-info">
                        <a href="<?= esc_url($permalink) ?>" class="fchub-wishlist-portal__item-title">
                            <?= esc_html($item['product_title']) ?>
                        </a>
                        <?php if (!empty($item['variant_title'])) : ?>
                            <span class="fchub-wishlist-portal__item-variant"><?= esc_html($item['variant_title']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="fchub-wishlist-portal__item-price">
                        <?php if ($item['current_price'] > 0) : ?>
                            <?= wp_kses_post(\FluentCart\Api\CurrencySettings::getPriceHtml($item['current_price'])) ?>
                        <?php endif; ?>
                    </div>

                    <div class="fchub-wishlist-portal__item-actions">
                        <?php if ($isAvailable) : ?>
                            <button class="fchub-wishlist-cart-btn fchub-wishlist-cart-btn--sm"
                                    data-fchub-wishlist-add-to-cart
                                    data-variant-id="<?= esc_attr((string) $item['variant_id']) ?>"
                                    type="button">
                                <?= esc_html__('Add to Cart', 'fchub-wishlist') ?>
                            </button>
                        <?php else : ?>
                            <span class="fchub-wishlist-portal__item-unavailable">
                                <?= esc_html__('Unavailable', 'fchub-wishlist') ?>
                            </span>
                        <?php endif; ?>

                        <button class="fchub-wishlist-remove-btn"
                                data-fchub-wishlist-remove
                                data-product-id="<?= esc_attr((string) $item['product_id']) ?>"
                                data-variant-id="<?= esc_attr((string) $item['variant_id']) ?>"
                                type="button"
                                aria-label="<?= esc_attr__('Remove from wishlist', 'fchub-wishlist') ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
