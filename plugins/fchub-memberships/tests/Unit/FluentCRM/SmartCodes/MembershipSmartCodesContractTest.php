<?php

declare(strict_types=1);

namespace {
    if (!function_exists('date_i18n')) {
        function date_i18n(string|false $format, int $timestamp): string
        {
            return gmdate('Y-m-d', $timestamp);
        }
    }
}

namespace FChubMemberships\Tests\Unit\FluentCRM\SmartCodes {

    use FChubMemberships\FluentCRM\SmartCodes\MembershipSmartCodes;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class MembershipSmartCodesContractTest extends PluginTestCase
    {
        public function test_coupon_smart_codes_read_membership_meta_object_type(): void
        {
            $subscriber = new SmartCodeContractSubscriber();

            self::assertSame('RENEW-AAAAAA', MembershipSmartCodes::parseSmartCode('', 'coupon_code', '', $subscriber));
            self::assertSame('20%', MembershipSmartCodes::parseSmartCode('', 'coupon_amount', '', $subscriber));
            self::assertSame('2026-04-01', MembershipSmartCodes::parseSmartCode('', 'coupon_expires', '', $subscriber));
            self::assertSame([
                ['_fchub_last_coupon_code', 'fchub_memberships'],
                ['_fchub_last_coupon_amount', 'fchub_memberships'],
                ['_fchub_last_coupon_type', 'fchub_memberships'],
                ['_fchub_last_coupon_expires', 'fchub_memberships'],
            ], $subscriber->getMetaCalls);
        }
    }

    final class SmartCodeContractSubscriber
    {
        public int $user_id = 0;

        /** @var list<array{string, ?string}> */
        public array $getMetaCalls = [];

        public function getMeta(string $key, ?string $objectType = null): string
        {
            $this->getMetaCalls[] = [$key, $objectType];

            return match ($key) {
                '_fchub_last_coupon_code' => 'RENEW-AAAAAA',
                '_fchub_last_coupon_amount' => '20',
                '_fchub_last_coupon_type' => 'percentage',
                '_fchub_last_coupon_expires' => '2026-04-01',
                default => '',
            };
        }
    }
}
