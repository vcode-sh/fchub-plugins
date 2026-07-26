<?php

namespace FChubMemberships\Frontend;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\MemberPortal\MembershipAccountQuery;

class AccountPage
{
    private static string $shieldSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3.78307 2.82598L12 1L20.2169 2.82598C20.6745 2.92766 21 3.33347 21 3.80217V13.7889C21 15.795 19.9974 17.6684 18.3282 18.7812L12 23L5.6718 18.7812C4.00261 17.6684 3 15.795 3 13.7889V3.80217C3 3.33347 3.32553 2.92766 3.78307 2.82598ZM5 4.60434V13.7889C5 15.1263 5.6684 16.3752 6.7812 17.1171L12 20.5963L17.2188 17.1171C18.3316 16.3752 19 15.1263 19 13.7889V4.60434L12 3.04879L5 4.60434ZM11 10.5858L8.46447 8.05025L7.05025 9.46447L11 13.4142L16.6569 7.75736L15.2426 6.34315L11 10.5858Z"></path></svg>';

    public static function register(): void
    {
        if (!function_exists('fluent_cart_api')) {
            return;
        }

        fluent_cart_api()->addCustomerDashboardEndpoint('memberships', [
            'title'           => __('Memberships', 'fchub-memberships'),
            'icon_svg'        => self::$shieldSvg,
            'render_callback' => [self::class, 'renderAccountSection'],
        ]);
    }

    /**
     * Render the "My Memberships" section in FluentCart customer portal.
     */
    public static function renderAccountSection(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        echo wp_kses_post(Shortcodes::renderMyMemberships([]));
    }

    /**
     * Get all membership data for a user.
     *
     * @return array{plans: array, history: array}
     */
    public static function getAccessData(int $userId): array
    {
        $data = (new MembershipAccountQuery())->get($userId);

        return [
            'plans' => array_map(static fn(array $plan): array => [
                'id' => $plan['plan_id'],
                'title' => $plan['plan_title'],
                'slug' => $plan['plan_slug'] ?? '',
                'expires_at' => $plan['expires_at'],
                'content_items' => $plan['timeline'],
                'drip_progress' => $plan['progress'],
            ], $data['plans']),
            'history' => array_map(static fn(array $entry): array => [
                'plan_title' => $entry['plan_title'],
                'status' => $entry['status'],
                'date' => $entry['updated_at'],
            ], $data['history']),
        ];
    }
}
