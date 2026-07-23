<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\WebhookEnvelope;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookEnvelopeTest extends PluginTestCase
{
    public function test_create_freezes_the_exact_versioned_utc_envelope(): void
    {
        $envelope = WebhookEnvelope::create('grant_created', ['grant_id' => 9]);

        self::assertSame(
            ['id', 'schema_version', 'event_type', 'occurred_at', 'site_url', 'data'],
            array_keys($envelope)
        );
        self::assertSame('00000000-0000-4000-8000-000000000000', $envelope['id']);
        self::assertSame('1.0', $envelope['schema_version']);
        self::assertSame('grant_created', $envelope['event_type']);
        self::assertSame('2026-03-13T22:00:00+00:00', $envelope['occurred_at']);
        self::assertSame('https://example.com/', $envelope['site_url']);
        self::assertSame(['grant_id' => 9], $envelope['data']);
    }

    public function test_encode_returns_the_exact_raw_json_body(): void
    {
        $envelope = WebhookEnvelope::create('grant_resumed', [
            'name' => 'José',
            'path' => 'https://example.com/member',
        ]);

        $encoded = WebhookEnvelope::encode($envelope);

        self::assertSame(wp_json_encode($envelope), $encoded);
        self::assertSame($envelope, json_decode($encoded, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_encode_fails_closed_when_json_encoding_fails(): void
    {
        $this->expectException(\RuntimeException::class);

        WebhookEnvelope::encode(WebhookEnvelope::create('grant_created', ['invalid' => NAN]));
    }
}
