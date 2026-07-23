<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\WebhookSecret;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookSecretTest extends PluginTestCase
{
    public function test_generation_returns_a_one_time_64_character_lowercase_hex_secret(): void
    {
        $first = WebhookSecret::generate();
        $second = WebhookSecret::generate();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $second);
        self::assertNotSame($first, $second);
    }

    public function test_metadata_exposes_only_configuration_state(): void
    {
        self::assertSame(
            ['webhook_secret_configured' => false],
            WebhookSecret::metadata([])
        );
        self::assertSame(
            ['webhook_secret_configured' => true],
            WebhookSecret::metadata(['webhook_secret' => 'internal-secret'])
        );
    }

    public function test_sign_uses_the_exact_raw_body_and_stored_secret(): void
    {
        $body = "{\"event\":\"grant_created\",\"name\":\"Jos\\u00e9\"}";
        $settings = ['webhook_secret' => 'internal-secret'];

        self::assertSame(hash_hmac('sha256', $body, 'internal-secret'), WebhookSecret::sign($body, $settings));
        self::assertNotSame(WebhookSecret::sign($body, $settings), WebhookSecret::sign($body . "\n", $settings));
        self::assertSame('', WebhookSecret::sign($body, []));
    }
}
