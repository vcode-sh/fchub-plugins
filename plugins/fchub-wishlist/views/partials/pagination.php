<?php
/**
 * Wishlist pagination controls, shared by the shortcode page and the customer portal.
 *
 * @var array $pagination Pagination state: page, per_page, total, total_pages
 */

defined('ABSPATH') || exit;

if (($pagination['total_pages'] ?? 0) <= 1) {
    return;
}

$fchub_wishlist_current_page = (int) ($pagination['page'] ?? 1);
$fchub_wishlist_total_pages = (int) ($pagination['total_pages'] ?? 1);
?>
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
