<?php

declare(strict_types=1);

namespace FChubMemberships\FluentCRM\Actions\Contracts;

defined('ABSPATH') || exit;

interface MembershipActionRuntimeInterface
{
    public function planExists(int $planId): bool;

    public function getActiveGrants(int $userId, ?int $planId): array;

    public function getPausedGrants(int $userId, ?int $planId): array;

    public function revokePlan(int $userId, int $planId, array $context): array;

    public function grantPlan(int $userId, int $planId, array $context): array;

    public function pauseGrant(int $grantId, string $reason): array;

    public function resumeGrant(int $grantId): array;

    public function extendExpiry(int $userId, int $planId, string $newExpiresAt): int;
}
