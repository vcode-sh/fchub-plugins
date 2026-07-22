<?php

declare(strict_types=1);

namespace FluentCrm\App\Services\Funnel {
    class ProvenanceBaseBenchmarkDouble
    {
        protected string $triggerName = '';
        protected int $actionArgNum = 1;
        protected int $priority = 10;
        public function __construct() {}
    }
    class ProvenanceFunnelHelperDouble
    {
        public static function getSubscriber(string $email): object|false { return $GLOBALS['_fchub_provenance_subscriber'] ?? false; }
    }
    class ProvenanceFunnelProcessorDouble
    {
        public function startFunnelFromSequencePoint(object $benchmark, object $subscriber): void { $GLOBALS['_fchub_provenance_goal_calls'][] = [$benchmark, $subscriber]; }
    }
}

namespace FluentCrm\Framework\Support {
    class ProvenanceArrDouble
    {
        public static function get(array $values, string $key, mixed $default = null): mixed { return $values[$key] ?? $default; }
    }
}

namespace FluentCart\App\Models {
    class ProvenanceSubscriptionDouble
    {
        public static function find(int $id): object { return (object) ['id' => $id, 'status' => 'active']; }
    }
}

namespace FChubMemberships\Tests\Unit\FluentCRM\Benchmarks {
    use FChubMemberships\FluentCRM\Benchmarks\PaymentRecoveredBenchmark;
    use FChubMemberships\FluentCRM\Benchmarks\TrialConvertedBenchmark;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class MembershipProvenanceBenchmarkTest extends PluginTestCase
    {
        protected function setUp(): void
        {
            class_alias(\FluentCrm\App\Services\Funnel\ProvenanceBaseBenchmarkDouble::class, 'FluentCrm\App\Services\Funnel\BaseBenchMark');
            class_alias(\FluentCrm\App\Services\Funnel\ProvenanceFunnelHelperDouble::class, 'FluentCrm\App\Services\Funnel\FunnelHelper');
            class_alias(\FluentCrm\App\Services\Funnel\ProvenanceFunnelProcessorDouble::class, 'FluentCrm\App\Services\Funnel\FunnelProcessor');
            class_alias(\FluentCrm\Framework\Support\ProvenanceArrDouble::class, 'FluentCrm\Framework\Support\Arr');
            class_alias(\FluentCart\App\Models\ProvenanceSubscriptionDouble::class, 'FluentCart\App\Models\Subscription');
            parent::setUp();
            $GLOBALS['_fchub_provenance_goal_calls'] = [];
            $GLOBALS['_fchub_provenance_subscriber'] = (object) ['id' => 91];
            $user = new \WP_User();
            $user->ID = 21;
            $user->user_email = 'member@example.test';
            $GLOBALS['_fchub_test_users'][21] = $user;
        }

        public function test_payment_recovered_requires_the_current_recovery_transition(): void
        {
            $benchmark = (object) ['settings' => ['plan_ids' => []]];
            $grant = ['user_id' => 21, 'plan_id' => 5, 'source_type' => 'subscription', 'source_id' => 77, 'meta' => []];
            $goal = new PaymentRecoveredBenchmark();

            $goal->handle($benchmark, [$grant, 3]);
            $grant['meta']['payment_incident'] = ['recovered_at' => '2026-07-22 10:00:00', 'recovery_renewal_count' => 2];
            $goal->handle($benchmark, [$grant, 3]);
            self::assertSame([], $GLOBALS['_fchub_provenance_goal_calls']);

            $grant['meta']['payment_incident']['recovery_renewal_count'] = 3;
            $goal->handle($benchmark, [$grant, 3]);
            self::assertCount(1, $GLOBALS['_fchub_provenance_goal_calls']);

            $goal->handle($benchmark, [$grant, 4]);
            self::assertCount(1, $GLOBALS['_fchub_provenance_goal_calls']);
        }

        public function test_trial_converted_rejects_ordinary_paid_grants_and_accepts_explicit_conversion(): void
        {
            $benchmark = (object) ['settings' => ['plan_ids' => []]];
            $grant = ['user_id' => 21, 'plan_id' => 5, 'meta' => []];
            $goal = new TrialConvertedBenchmark();

            $goal->handle($benchmark, [$grant, 5, 21]);
            self::assertSame([], $GLOBALS['_fchub_provenance_goal_calls']);

            $grant['meta'] = ['trial_started_at' => '2026-07-01 10:00:00', 'trial_converted_at' => '2026-07-22 10:00:00'];
            $goal->handle($benchmark, [$grant, 5, 21]);
            self::assertCount(1, $GLOBALS['_fchub_provenance_goal_calls']);
        }
    }
}
