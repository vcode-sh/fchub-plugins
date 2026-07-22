<?php

declare(strict_types=1);

namespace FluentCrm\App\Services\Funnel {
    if (!class_exists(BaseAction::class)) {
        class BaseAction
        {
            public string $actionName = '';
            public int $priority = 10;

            public function __construct()
            {
            }
        }
    }

    if (!class_exists(FunnelHelper::class)) {
        class FunnelHelper
        {
            public static function changeFunnelSubSequenceStatus(
                $funnelSubscriberId,
                $sequenceId,
                string $status
            ): void {
                $GLOBALS['_fchub_test_funnel_statuses'][] = [$funnelSubscriberId, $sequenceId, $status];
            }
        }
    }
}

namespace FluentCrm\Framework\Support {
    if (!class_exists(Arr::class)) {
        class Arr
        {
            public static function get(array $values, string $key, mixed $default = null): mixed
            {
                $value = $values;
                foreach (explode('.', $key) as $segment) {
                    if (!is_array($value) || !array_key_exists($segment, $value)) {
                        return $default;
                    }
                    $value = $value[$segment];
                }
                return $value;
            }
        }
    }
}

namespace FluentCart\App\Models {
    class Coupon
    {
        public static function query(): object
        {
            return new class {
                public function where(string $column, string $value): self
                {
                    return $this;
                }

                public function first(): ?object
                {
                    return null;
                }
            };
        }
    }
}

namespace FluentCart\Api\Resource {
    class CouponResource
    {
        public static function create(array $couponData): mixed
        {
            $GLOBALS['_fchub_test_coupon_create_calls'][] = $couponData;
            return $GLOBALS['_fchub_test_coupon_create_result'];
        }
    }
}

namespace FChubMemberships\Tests\Unit\FluentCRM\Actions {

    use FChubMemberships\FluentCRM\Actions\CreateFluentCartCouponAction;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class CreateFluentCartCouponActionContractTest extends PluginTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['_fchub_test_coupon_create_calls'] = [];
            $GLOBALS['_fchub_test_coupon_create_result'] = ['data' => (object) ['id' => 91, 'code' => 'RENEW-AAAAAA']];
            $GLOBALS['_fchub_test_funnel_statuses'] = [];
        }

        public function test_success_uses_membership_meta_string_email_restrictions_and_full_coupon_event(): void
        {
            $subscriber = new CouponContractSubscriber('member@example.com');
            $eventPayload = null;
            add_action('fluent_cart/coupon_created', static function (array $payload) use (&$eventPayload): void {
                $eventPayload = $payload;
            });

            (new CreateFluentCartCouponAction())->handle(
                $subscriber,
                (object) ['id' => 32, 'settings' => ['amount' => 20, 'coupon_type' => 'percentage']],
                73,
                (object) []
            );

            self::assertSame('member@example.com', $GLOBALS['_fchub_test_coupon_create_calls'][0]['conditions']['email_restrictions']);
            self::assertSame([
                ['_fchub_last_coupon_code', 'RENEW-AAAAAA', 'fchub_memberships'],
                ['_fchub_last_coupon_amount', 20, 'fchub_memberships'],
                ['_fchub_last_coupon_type', 'percentage', 'fchub_memberships'],
            ], $subscriber->updateMetaCalls);
            self::assertSame($GLOBALS['_fchub_test_coupon_create_calls'][0], $eventPayload['data']);
            self::assertSame($GLOBALS['_fchub_test_coupon_create_result']['data'], $eventPayload['coupon']);
        }

        public function test_creation_failure_skips_metadata_and_coupon_event(): void
        {
            $GLOBALS['_fchub_test_coupon_create_result'] = new \WP_Error('coupon_failed', 'No coupon for you.');
            $subscriber = new CouponContractSubscriber('member@example.com');
            $eventCount = 0;
            add_action('fluent_cart/coupon_created', static function () use (&$eventCount): void {
                $eventCount++;
            });

            (new CreateFluentCartCouponAction())->handle(
                $subscriber,
                (object) ['id' => 32, 'settings' => ['amount' => 20]],
                73,
                (object) []
            );

            self::assertSame([], $subscriber->updateMetaCalls);
            self::assertSame(0, $eventCount);
            self::assertSame([[73, 32, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
        }
    }

    final class CouponContractSubscriber
    {
        /** @var list<array{string, mixed, ?string}> */
        public array $updateMetaCalls = [];

        public function __construct(public string $email, public string $first_name = 'Member')
        {
        }

        public function updateMeta(string $key, mixed $value, ?string $objectType = null): void
        {
            $this->updateMetaCalls[] = [$key, $value, $objectType];
        }
    }
}
