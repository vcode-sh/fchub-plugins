<?php

declare(strict_types=1);

namespace FChubWishlist\FluentCRM\ProfileSection;

defined('ABSPATH') || exit;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Html\TableBuilder;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Storage\WishlistItemRepository;

class WishlistProfileSection
{
    public function register(): void
    {
        add_filter('fluentcrm_profile_sections', [$this, 'addSection']);
        // Note: FluentCRM has a typo in this hook name - "fluencrm" not "fluentcrm"
        add_filter('fluencrm_profile_section_fchub_wishlist', [$this, 'getSection'], 10, 2);
    }

    public function addSection(array $sections): array
    {
        $sections['fchub_wishlist'] = [
            'name'    => 'fluentcrm_profile_extended',
            'title'   => __('Wishlist', 'fchub-wishlist'),
            'handler' => 'route',
            'query'   => ['handler' => 'fchub_wishlist'],
        ];

        return $sections;
    }

    public function getSection($section, Subscriber $subscriber): array
    {
        $section['heading'] = __('Wishlist', 'fchub-wishlist');
        $userId = (int) $subscriber->getWpUserId();

        if (!$userId) {
            $section['content_html'] = '<p>' . __('This contact is not linked to a WordPress user.', 'fchub-wishlist') . '</p>';
            return $section;
        }

        $wishlist = (new WishlistRepository())->findByUserId($userId);
        if (!$wishlist || $wishlist['item_count'] === 0) {
            $section['content_html'] = '<p>' . __('No wishlist items found.', 'fchub-wishlist') . '</p>';
            return $section;
        }

        $section['content_html'] = $this->renderHtml($wishlist);
        return $section;
    }

    private function renderHtml(array $wishlist): string
    {
        $itemRepo = new WishlistItemRepository();
        $items = $itemRepo->getItemsWithProductData($wishlist['id']);
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');

        $html = '<div style="margin-bottom:12px;">';
        $html .= '<strong>' . __('Items:', 'fchub-wishlist') . '</strong> ' . (int) $wishlist['item_count'];
        $html .= ' &nbsp;|&nbsp; ';
        $html .= '<strong>' . __('Last Updated:', 'fchub-wishlist') . '</strong> ';
        $html .= esc_html(gmdate($dateFormat, strtotime($wishlist['updated_at'])));
        $html .= '</div>';

        if (empty($items)) {
            return $html;
        }

        $table = new TableBuilder();
        $table->setHeader([
            'product' => __('Product', 'fchub-wishlist'),
            'variant' => __('Variant', 'fchub-wishlist'),
            'price'   => __('Price at Addition', 'fchub-wishlist'),
            'added'   => __('Added', 'fchub-wishlist'),
        ]);

        foreach (array_slice($items, 0, 20) as $item) {
            $productTitle = !empty($item['product_title'])
                ? '<a href="'
                    . esc_url(admin_url('admin.php?page=fluent-cart#/products/' . $item['product_id']))
                    . '">' . esc_html($item['product_title']) . '</a>'
                : __('(Deleted)', 'fchub-wishlist');

            $variantTitle = !empty($item['variant_title'])
                ? esc_html($item['variant_title'])
                : '—';

            $price = $item['price_at_addition'] > 0
                ? number_format($item['price_at_addition'], 2)
                : '—';

            $table->addRow([
                'product' => $productTitle,
                'variant' => $variantTitle,
                'price'   => $price,
                'added'   => gmdate($dateFormat, strtotime($item['created_at'])),
            ]);
        }

        $html .= $table->getHtml();

        if (count($items) > 20) {
            $html .= '<p style="color:#909399;font-size:12px;">';
            $html .= sprintf(
                /* translators: %d: total number of wishlist items */
                __('Showing 20 of %d items', 'fchub-wishlist'),
                count($items)
            );
            $html .= '</p>';
        }

        return $html;
    }
}
