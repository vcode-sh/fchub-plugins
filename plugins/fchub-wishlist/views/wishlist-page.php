<?php
/**
 * Wishlist shortcode page template.
 *
 * @var array $items Array of wishlist items with product data
 */

defined('ABSPATH') || exit;
?>
<div class="fchub-wishlist-page" data-fchub-wishlist-page>
    <?php if (empty($items)) : ?>
        <div class="fchub-wishlist-empty">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <p class="fchub-wishlist-empty__title"><?php echo esc_html__('Your wishlist is empty', 'fchub-wishlist'); ?></p>
            <p class="fchub-wishlist-empty__text"><?php echo esc_html__('Browse our products and add your favourites here.', 'fchub-wishlist'); ?></p>
        </div>
    <?php else : ?>
        <div class="fchub-wishlist-actions">
            <button class="fchub-wishlist-add-all-btn" data-fchub-wishlist-add-all type="button">
                <?php echo esc_html__('Add All to Cart', 'fchub-wishlist'); ?>
            </button>
        </div>

        <div class="fchub-wishlist-grid">
            <?php foreach ($items as $fchub_wishlist_item) : ?>
                <?php
                $fchub_wishlist_is_available = ($fchub_wishlist_item['product_status'] === 'publish' && $fchub_wishlist_item['variant_status'] === 'active');
                $fchub_wishlist_permalink = get_permalink($fchub_wishlist_item['product_id']);
                $fchub_wishlist_thumbnail = get_the_post_thumbnail_url($fchub_wishlist_item['product_id'], 'medium');
                ?>
                <div class="fchub-wishlist-item"
                     data-fchub-wishlist-item
                     data-product-id="<?php echo esc_attr((string) $fchub_wishlist_item['product_id']); ?>"
                     data-variant-id="<?php echo esc_attr((string) $fchub_wishlist_item['variant_id']); ?>">

                    <div class="fchub-wishlist-item__image">
                        <?php if ($fchub_wishlist_thumbnail) : ?>
                            <a href="<?php echo esc_url($fchub_wishlist_permalink); ?>">
                                <img src="<?php echo esc_url($fchub_wishlist_thumbnail); ?>"
                                     alt="<?php echo esc_attr($fchub_wishlist_item['product_title']); ?>"
                                     loading="lazy"/>
                            </a>
                        <?php else : ?>
                            <a href="<?php echo esc_url($fchub_wishlist_permalink); ?>" class="fchub-wishlist-item__placeholder"></a>
                        <?php endif; ?>
                    </div>

                    <div class="fchub-wishlist-item__details">
                        <h3 class="fchub-wishlist-item__title">
                            <a href="<?php echo esc_url($fchub_wishlist_permalink); ?>"><?php echo esc_html($fchub_wishlist_item['product_title']); ?></a>
                        </h3>

                        <?php if (!empty($fchub_wishlist_item['variant_title'])) : ?>
                            <span class="fchub-wishlist-item__variant"><?php echo esc_html($fchub_wishlist_item['variant_title']); ?></span>
                        <?php endif; ?>

                        <span class="fchub-wishlist-item__price">
                            <?php if ($fchub_wishlist_item['current_price'] > 0) : ?>
                                <?php echo wp_kses_post(\FluentCart\Api\CurrencySettings::getPriceHtml($fchub_wishlist_item['current_price'])); ?>
                            <?php endif; ?>
                        </span>

                        <?php if (!$fchub_wishlist_is_available) : ?>
                            <span class="fchub-wishlist-item__stock fchub-wishlist-item__stock--out">
                                <?php echo esc_html__('Unavailable', 'fchub-wishlist'); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="fchub-wishlist-item__actions">
                        <?php if ($fchub_wishlist_is_available) : ?>
                            <button class="fchub-wishlist-cart-btn"
                                    data-fchub-wishlist-add-to-cart
                                    data-variant-id="<?php echo esc_attr((string) $fchub_wishlist_item['variant_id']); ?>"
                                    type="button">
                                <?php echo esc_html__('Add to Cart', 'fchub-wishlist'); ?>
                            </button>
                        <?php endif; ?>

                        <button class="fchub-wishlist-remove-btn"
                                data-fchub-wishlist-remove
                                data-product-id="<?php echo esc_attr((string) $fchub_wishlist_item['product_id']); ?>"
                                data-variant-id="<?php echo esc_attr((string) $fchub_wishlist_item['variant_id']); ?>"
                                type="button"
                                aria-label="<?php echo esc_attr__('Remove from wishlist', 'fchub-wishlist'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (($pagination['total_pages'] ?? 0) > 1) : ?>
            <?php $fchub_wishlist_current_page = (int) ($pagination['page'] ?? 1); $fchub_wishlist_total_pages = (int) ($pagination['total_pages'] ?? 1); ?>
            <div class="fchub-wishlist-pagination" role="navigation" aria-label="<?php echo esc_attr__('Wishlist pagination', 'fchub-wishlist'); ?>">
                <?php if ($fchub_wishlist_current_page > 1) : ?>
                    <a class="fchub-wishlist-pagination__link" href="<?php echo esc_url('?' . http_build_query(['wishlist_page' => $fchub_wishlist_current_page - 1])); ?>"><?php echo esc_html__('Previous', 'fchub-wishlist'); ?></a>
                <?php endif; ?>
                <span class="fchub-wishlist-pagination__meta"><?php
                echo esc_html(sprintf(
                    /* translators: 1: current page number, 2: total number of pages. */
                    __('Page %1$d of %2$d', 'fchub-wishlist'),
                    $fchub_wishlist_current_page,
                    $fchub_wishlist_total_pages
                ));
                ?></span>
                <?php if ($fchub_wishlist_current_page < $fchub_wishlist_total_pages) : ?>
                    <a class="fchub-wishlist-pagination__link" href="<?php echo esc_url('?' . http_build_query(['wishlist_page' => $fchub_wishlist_current_page + 1])); ?>"><?php echo esc_html__('Next', 'fchub-wishlist'); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
