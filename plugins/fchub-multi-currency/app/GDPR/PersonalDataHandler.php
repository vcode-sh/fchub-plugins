<?php

declare(strict_types=1);

namespace FChubMultiCurrency\GDPR;

use FChubMultiCurrency\Storage\EventLogRepository;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class PersonalDataHandler
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerEraser']);
    }

    public static function registerExporter(array $exporters): array
    {
        $exporters['fchub-multi-currency'] = [
            'exporter_friendly_name' => __('FCHub Multi-Currency', 'fchub-multi-currency'),
            'callback'               => [self::class, 'exportPersonalData'],
        ];

        return $exporters;
    }

    public static function registerEraser(array $erasers): array
    {
        $erasers['fchub-multi-currency'] = [
            'eraser_friendly_name' => __('FCHub Multi-Currency', 'fchub-multi-currency'),
            'callback'             => [self::class, 'erasePersonalData'],
        ];

        return $erasers;
    }

    public static function exportPersonalData(string $emailAddress, int $page = 1): array
    {
        $user = get_user_by('email', $emailAddress);
        $exportItems = [];
        $page = max(1, $page);
        $pageSize = 50;
        $offset = ($page - 1) * $pageSize;
        $done = true;

        if ($user) {
            if ($page === 1) {
                $preference = get_user_meta($user->ID, Constants::USER_META_KEY, true);

                if ($preference) {
                    $exportItems[] = [
                        'group_id'    => 'fchub-multi-currency',
                        'group_label' => __('Multi-Currency Preferences', 'fchub-multi-currency'),
                        'item_id'     => 'fchub-mc-pref-' . $user->ID,
                        'data'        => [
                            [
                                'name'  => __('Preferred Currency', 'fchub-multi-currency'),
                                'value' => $preference,
                            ],
                        ],
                    ];
                }
            }

            $eventLogRepo = new EventLogRepository();
            $events = $eventLogRepo->findByUser($user->ID, $pageSize, $offset);
            $done = count($events) < $pageSize;

            foreach ($events as $event) {
                $exportItems[] = [
                    'group_id'    => 'fchub-multi-currency-events',
                    'group_label' => __('Multi-Currency Activity', 'fchub-multi-currency'),
                    'item_id'     => 'fchub-mc-event-' . $event->id,
                    'data'        => [
                        [
                            'name'  => __('Event', 'fchub-multi-currency'),
                            'value' => $event->event,
                        ],
                        [
                            'name'  => __('Date', 'fchub-multi-currency'),
                            'value' => $event->created_at,
                        ],
                    ],
                ];
            }
        }

        return [
            'data' => $exportItems,
            'done' => $done,
        ];
    }

    public static function erasePersonalData(string $emailAddress, int $page = 1): array
    {
        $user = get_user_by('email', $emailAddress);
        $itemsRemoved = 0;

        if ($user) {
            $prefRepo = new PreferenceRepository();
            $prefRepo->deleteUserMeta($user->ID);
            $itemsRemoved++;

            $eventLogRepo = new EventLogRepository();
            $itemsRemoved += $eventLogRepo->deleteByUser($user->ID);
        }

        return [
            'items_removed'  => $itemsRemoved,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
        ];
    }
}
