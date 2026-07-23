<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\WebhookEndpointPolicy;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookEndpointPolicyTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_fchub_test_environment_type'] = 'production';
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_fchub_test_environment_type'],
            $GLOBALS['_fchub_test_wp_http_validate_url_override']
        );
        parent::tearDown();
    }

    public function test_empty_and_duplicate_endpoints_normalise_to_unique_canonical_urls(): void
    {
        $policy = $this->policy();

        self::assertTrue($policy->validate(''));
        self::assertSame([], $policy->normalise(''));
        self::assertSame([
            'https://example.com/hook',
            'https://example.com/other?event=grant',
        ], $policy->normalise(implode("\n", [
            ' HTTPS://EXAMPLE.COM:443/hook ',
            'https://example.com/hook',
            'https://Example.com/other?event=grant',
        ])));
    }

    public function test_malformed_non_http_credential_and_fragment_urls_are_rejected(): void
    {
        $policy = $this->policy();

        foreach ([
            'not-a-url',
            'ftp://example.com/hook',
            'https://user:pass@example.com/hook',
            'https://example.com/hook#private',
        ] as $raw) {
            $result = $policy->validate($raw);
            self::assertInstanceOf(\WP_Error::class, $result, $raw);
            self::assertSame('fchub_invalid_webhook_endpoints', $result->get_error_code(), $raw);
            self::assertSame(422, $result->get_error_data()['status'], $raw);
        }
    }

    public function test_production_requires_https_and_all_environments_reject_private_destinations(): void
    {
        $policy = $this->policy();

        self::assertInstanceOf(\WP_Error::class, $policy->validate('http://example.com/hook'));
        self::assertTrue($policy->validate('https://example.com/hook'));

        $GLOBALS['_fchub_test_environment_type'] = 'development';
        $policy = $this->policy();
        self::assertTrue($policy->validate('http://example.com/hook'));

        foreach ([
            'http://localhost/hook',
            'http://127.0.0.1/hook',
            'http://10.0.0.8/hook',
            'https://192.168.1.20/hook',
            'https://[::1]/hook',
        ] as $raw) {
            self::assertInstanceOf(\WP_Error::class, $policy->validate($raw), $raw);
        }
    }

    public function test_literal_and_every_resolved_address_must_be_public_before_wordpress_validation(): void
    {
        $GLOBALS['_fchub_test_wp_http_validate_url_override'] = static fn(string $url): string => $url;

        self::assertInstanceOf(
            \WP_Error::class,
            $this->policy()->validate('https://127.0.0.1/hook')
        );
        self::assertInstanceOf(
            \WP_Error::class,
            $this->policy(['8.8.8.8', '127.0.0.1'])->validate('https://mixed.example/hook')
        );
        self::assertTrue(
            $this->policy(['8.8.8.8', '2606:4700:4700::1111'])->validate('https://public.example/hook')
        );
    }

    public function test_more_than_ten_unique_endpoints_are_rejected(): void
    {
        $raw = implode("\n", array_map(
            static fn(int $index): string => "https://example.com/hook-{$index}",
            range(1, 11)
        ));

        self::assertInstanceOf(\WP_Error::class, $this->policy()->validate($raw));
    }

    /** @param list<string> $addresses */
    private function policy(array $addresses = ['8.8.8.8']): WebhookEndpointPolicy
    {
        return new WebhookEndpointPolicy(
            $GLOBALS['_fchub_test_environment_type'],
            static fn(string $host): array => $addresses
        );
    }
}
