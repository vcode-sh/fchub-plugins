<?php

namespace FChubMemberships\Domain\Member;

use FChubMemberships\Storage\AuditLogRepository;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

defined('ABSPATH') || exit;

/**
 * Assembles what the member profile screen reads.
 *
 * Both the profile and its timeline start from the same memberships, so they
 * are composed once here rather than twice in the controller, and the reads
 * stay flat: one grant query, one plan query, one audit query.
 */
final class MemberProfileService
{
    public function __construct(
        private ?GrantRepository $grants = null,
        private ?PlanRepository $plans = null,
        private ?AuditLogRepository $audit = null,
        private ?DripScheduleRepository $drip = null,
        private ?MembershipGrouper $grouper = null,
        private ?GrantSourceResolver $sources = null,
        private ?MemberTimelineComposer $timeline = null
    ) {
        $this->grants ??= new GrantRepository();
        $this->plans ??= new PlanRepository();
        $this->audit ??= new AuditLogRepository();
        $this->drip ??= new DripScheduleRepository();
        $this->grouper ??= new MembershipGrouper();
        $this->sources ??= new GrantSourceResolver();
        $this->timeline ??= new MemberTimelineComposer();
    }

    /** @return list<array<string, mixed>> */
    public function memberships(int $userId): array
    {
        return $this->compose($userId)['memberships'];
    }

    /** @return list<array<string, mixed>> newest first */
    public function timeline(int $userId): array
    {
        $composed = $this->compose($userId);

        return $this->timeline->compose(
            $composed['memberships'],
            $composed['audit'],
            $this->drip->getByUserId($userId, ['per_page' => 100])
        );
    }

    /** @return array{memberships: list<array<string, mixed>>, audit: list<array<string, mixed>>} */
    private function compose(int $userId): array
    {
        $rows = $this->grants->getByUserId($userId);
        if ($rows === []) {
            return ['memberships' => [], 'audit' => []];
        }

        $planTitles = array_map(
            static fn(array $plan): string => (string) $plan['title'],
            $this->plans->findMany(array_column($rows, 'plan_id'))
        );
        $auditRecords = $this->audit->getByEntityIds('grant', array_column($rows, 'id'));

        $memberships = array_map(
            fn(array $membership): array => $membership + [
                'source' => $this->sources->resolve($membership, $this->recordsFor($membership, $auditRecords)),
            ],
            $this->grouper->group($rows, $planTitles)
        );

        return ['memberships' => $memberships, 'audit' => $auditRecords];
    }

    /**
     * @param array<string, mixed> $membership
     * @param list<array<string, mixed>> $auditRecords
     * @return list<array<string, mixed>>
     */
    private function recordsFor(array $membership, array $auditRecords): array
    {
        $grantIds = array_map('intval', $membership['grant_ids']);

        return array_values(array_filter(
            $auditRecords,
            static fn(array $record): bool => in_array((int) $record['entity_id'], $grantIds, true)
        ));
    }
}
