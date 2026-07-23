<?php

declare(strict_types=1);

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

final class WebhookEnvelope
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function create(string $eventType, array $data): array
    {
        return [
            'id' => wp_generate_uuid4(),
            'schema_version' => '1.0',
            'event_type' => $eventType,
            'occurred_at' => current_datetime()
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(DATE_ATOM),
            'site_url' => get_site_url(),
            'data' => $data,
        ];
    }

    /** @param array<string, mixed> $envelope */
    public static function encode(array $envelope): string
    {
        $body = wp_json_encode($envelope);
        if (!is_string($body)) {
            throw new \RuntimeException('Unable to encode webhook event.');
        }

        return $body;
    }
}
