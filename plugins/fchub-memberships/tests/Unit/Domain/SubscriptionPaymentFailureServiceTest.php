<?php

declare(strict_types=1);

namespace FluentCart\App\Models;

class Subscription
{
    public static function where(string $column, int $value): object
    {
        return new class {
            public function get(): object
            {
                return new class implements \IteratorAggregate {
                    public function isEmpty(): bool
                    {
                        return false;
                    }

                    public function getIterator(): \Traversable
                    {
                        yield (object) ['id' => 77, 'status' => 'active'];
                    }
                };
            }
        };
    }

    public static function find(int $id): ?object
    {
        return (object) ['id' => $id, 'status' => 'active'];
    }
}

namespace FluentCart\App\Events\Order;

class OrderPaymentFailed
{
    public function __construct(public ?object $order)
    {
    }
}

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\SubscriptionPaymentFailureService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class SubscriptionPaymentFailureServiceTest extends PluginTestCase
{
    public function test_payment_failure_service_handles_event_and_array_payloads(): void
    {
        $grantsRepo = new class extends GrantRepository {
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [['id' => 1], ['id' => 2]];
            }
        };

        $service = new SubscriptionPaymentFailureService($grantsRepo);

        $event = new \FluentCart\App\Events\Order\OrderPaymentFailed((object) ['id' => 5]);
        $service->handle($event, 'order');
        $service->handle(['subscription' => (object) ['id' => 88]], 'subscription');
        $service->handle([], 'ignored');

        self::assertCount(2, $GLOBALS['_fchub_test_fc_logs']);
        self::assertSame('Payment failed for membership', $GLOBALS['_fchub_test_fc_logs'][0][0]);
        self::assertStringContainsString('Subscription #77', $GLOBALS['_fchub_test_fc_logs'][0][1]);
    }
}
