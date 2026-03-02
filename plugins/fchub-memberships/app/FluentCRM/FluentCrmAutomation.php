<?php

namespace FChubMemberships\FluentCRM;

defined('ABSPATH') || exit;

class FluentCrmAutomation
{
    public static function boot(): void
    {
        if (!defined('FLUENTCRM')) {
            return;
        }

        // Triggers
        new Triggers\MembershipGrantedTrigger();
        new Triggers\MembershipRevokedTrigger();
        new Triggers\MembershipExpiredTrigger();
        new Triggers\MembershipPausedTrigger();
        new Triggers\MembershipResumedTrigger();
        new Triggers\MembershipRenewedTrigger();
        new Triggers\TrialStartedTrigger();
        new Triggers\TrialConvertedTrigger();
        new Triggers\TrialExpiredTrigger();
        new Triggers\DripContentUnlockedTrigger();
        new Triggers\MembershipExpiringSoonTrigger();
        new Triggers\MembershipAnniversaryTrigger();
        new Triggers\DripMilestoneTrigger();
        new Triggers\TrialExpiringSoonTrigger();
        new Triggers\PaymentFailedTrigger();

        // Actions
        new Actions\GrantMembershipAction();
        new Actions\RevokeMembershipAction();
        new Actions\PauseMembershipAction();
        new Actions\ResumeMembershipAction();
        new Actions\ExtendMembershipAction();
        new Actions\ChangeMembershipPlanAction();
        new Actions\CreateFluentCartCouponAction();

        // Benchmarks
        new Benchmarks\HasActiveMembershipBenchmark();
        new Benchmarks\MembershipExpiredBenchmark();
        new Benchmarks\TrialConvertedBenchmark();
        new Benchmarks\PaymentRecoveredBenchmark();
        new Benchmarks\MembershipResumedBenchmark();
        new Benchmarks\MembershipPausedBenchmark();
        new Benchmarks\MembershipRevokedBenchmark();

        // Smart Codes
        SmartCodes\MembershipSmartCodes::register();

        // Profile Section
        (new ProfileSection\MembershipProfileSection())->register();

        // Segment Filters
        Filters\MembershipFilters::register();
    }
}
