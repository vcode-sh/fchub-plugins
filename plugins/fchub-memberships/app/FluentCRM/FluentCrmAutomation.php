<?php

namespace FChubMemberships\FluentCRM;

defined('ABSPATH') || exit;

class FluentCrmAutomation
{
    /** @var list<class-string> */
    public const TRIGGER_CLASSES = [
        Triggers\MembershipGrantedTrigger::class,
        Triggers\MembershipRevokedTrigger::class,
        Triggers\MembershipExpiredTrigger::class,
        Triggers\MembershipPausedTrigger::class,
        Triggers\MembershipResumedTrigger::class,
        Triggers\MembershipRenewedTrigger::class,
        Triggers\TrialStartedTrigger::class,
        Triggers\TrialConvertedTrigger::class,
        Triggers\TrialExpiredTrigger::class,
        Triggers\DripContentUnlockedTrigger::class,
        Triggers\MembershipExpiringSoonTrigger::class,
        Triggers\MembershipAnniversaryTrigger::class,
        Triggers\DripMilestoneTrigger::class,
        Triggers\TrialExpiringSoonTrigger::class,
        Triggers\PaymentFailedTrigger::class,
        Triggers\MembershipPlanChangedTrigger::class,
    ];

    public static function boot(): void
    {
        if (!defined('FLUENTCRM')) {
            return;
        }

        foreach (self::TRIGGER_CLASSES as $triggerClass) {
            new $triggerClass();
        }

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
