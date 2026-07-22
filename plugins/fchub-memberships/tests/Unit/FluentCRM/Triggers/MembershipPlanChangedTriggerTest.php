<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\FluentCRM\Triggers;

use FChubMemberships\Domain\MembershipPlanChangePublisher;
use FChubMemberships\FluentCRM\Triggers\MembershipPlanChangedTrigger;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MembershipPlanChangedTriggerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        class_alias(PlanChangedBaseTriggerDouble::class, 'FluentCrm\App\Services\Funnel\BaseTrigger');
        class_alias(PlanChangedFunnelHelperDouble::class, 'FluentCrm\App\Services\Funnel\FunnelHelper');
        class_alias(PlanChangedFunnelProcessorDouble::class, 'FluentCrm\App\Services\Funnel\FunnelProcessor');
        class_alias(PlanChangedArrDouble::class, 'FluentCrm\Framework\Support\Arr');
        parent::setUp();
        $GLOBALS['_fchub_plan_changed_sequences'] = [];
        $GLOBALS['_fchub_plan_changed_subscriber'] = false;
    }

    public function test_publisher_emits_a_canonical_sorted_plan_change_envelope(): void
    {
        $published = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/plan_changed'] = [static function (array $change) use (&$published): void { $published[] = $change; }];

        (new MembershipPlanChangePublisher())->publish(44, [9, 3, 9], 11, 'exclusive_replacement', ['source_type' => 'automation', 'source_id' => 501]);

        self::assertSame([3, 9], $published[0]['from_plan_ids']);
        self::assertSame(44, $published[0]['user_id']);
        self::assertSame(11, $published[0]['to_plan_id']);
        self::assertSame('exclusive_replacement', $published[0]['change_type']);
        self::assertSame('automation', $published[0]['source_type']);
        self::assertSame(501, $published[0]['source_id']);
        self::assertNotEmpty($published[0]['occurred_at']);
    }

    public function test_trigger_filters_source_target_and_transition_type(): void
    {
        $user = new \WP_User();
        $user->ID = 44;
        $user->user_email = 'member@example.test';
        $GLOBALS['_fchub_test_users'][44] = $user;
        $change = ['user_id' => 44, 'from_plan_ids' => [3, 5], 'to_plan_id' => 8, 'change_type' => 'automation_change'];
        $funnel = (object) [
            'id' => 91,
            'settings' => ['subscription_status' => 'subscribed'],
            'conditions' => ['from_plan_ids' => [3], 'to_plan_ids' => [8], 'change_types' => ['automation_change'], 'run_multiple' => 'yes'],
        ];

        (new MembershipPlanChangedTrigger())->handle($funnel, [$change]);
        self::assertCount(1, $GLOBALS['_fchub_plan_changed_sequences']);

        foreach ([
            ['from_plan_ids' => [99]],
            ['to_plan_ids' => [99]],
            ['change_types' => ['level_upgrade']],
        ] as $mismatch) {
            $GLOBALS['_fchub_plan_changed_sequences'] = [];
            $funnel->conditions = array_merge($funnel->conditions, $mismatch);
            self::assertFalse((new MembershipPlanChangedTrigger())->handle($funnel, [$change]));
            self::assertSame([], $GLOBALS['_fchub_plan_changed_sequences']);
            $funnel->conditions = ['from_plan_ids' => [3], 'to_plan_ids' => [8], 'change_types' => ['automation_change'], 'run_multiple' => 'yes'];
        }
    }
}

class PlanChangedBaseTriggerDouble
{
    protected string $triggerName = '';
    protected int $priority = 10;
    protected int $actionArgNum = 1;
    public function __construct() {}
}

class PlanChangedFunnelHelperDouble
{
    public static function prepareUserData(object $user): array { return ['email' => $user->user_email]; }
    public static function getSubscriber(string $email): object|false { return $GLOBALS['_fchub_plan_changed_subscriber']; }
    public static function ifAlreadyInFunnel(int $funnelId, int $subscriberId): bool { return false; }
    public static function removeSubscribersFromFunnel(int $funnelId, array $subscriberIds): void {}
}

class PlanChangedFunnelProcessorDouble
{
    public function startFunnelSequence(object $funnel, array $data, array $context): void { $GLOBALS['_fchub_plan_changed_sequences'][] = [$data, $context]; }
}

class PlanChangedArrDouble
{
    public static function get(array $values, string $key, mixed $default = null): mixed { return $values[$key] ?? $default; }
}
