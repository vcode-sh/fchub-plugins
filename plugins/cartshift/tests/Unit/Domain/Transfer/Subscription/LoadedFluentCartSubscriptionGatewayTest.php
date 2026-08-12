<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Subscription\LoadedFluentCartSubscriptionGateway;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedFluentCartSubscriptionGatewayTest extends PluginTestCase
{
    public function testDeterministicUuidCollisionFailsBeforeInsert(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): ?int =>
            str_contains($query, "uuid = 'collision-uuid'") ? 91 : null;

        try {
            (new LoadedFluentCartSubscriptionGateway())->create($this->row('collision-uuid'));
            self::fail('A known deterministic UUID collision reached insert.');
        } catch (\RuntimeException $exception) {
            self::assertSame('target_subscription_identity_conflict', $exception->getMessage());
        }

        self::assertSame([], array_values(array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $query): bool => $query[0] === 'insert',
        )));
    }

    public function testCollisionReadFailureFailsBeforeInsert(): void
    {
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $context): string =>
            str_contains($context, 'fct_subscriptions WHERE uuid') ? 'read failed' : '';

        try {
            (new LoadedFluentCartSubscriptionGateway())->create($this->row('new-uuid'));
            self::fail('A failed collision read reached insert.');
        } catch (\RuntimeException $exception) {
            self::assertSame('target_subscription_collision_read_failed', $exception->getMessage());
        }

        self::assertSame([], array_values(array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $query): bool => $query[0] === 'insert',
        )));
    }

    public function testAbsentCollisionAllowsOneInsert(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): null => null;

        $id = (new LoadedFluentCartSubscriptionGateway())->create($this->row('new-uuid'));

        self::assertGreaterThan(0, $id);
        self::assertCount(1, array_values(array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $query): bool => $query[0] === 'insert',
        )));
    }

    /** @return array<string,mixed> */
    private function row(string $uuid): array
    {
        return [
            'uuid' => $uuid,
            'config' => [],
            'original_plan' => [],
            'vendor_response' => [],
        ];
    }
}
