<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Support\Logger;
use FChubMemberships\Domain\Lifecycle\MembershipLifecycleCoordinator;

defined('ABSPATH') || exit;

final class SubscriptionValidityCheckService
{
    public function __construct(
        AccessGrantService $grantService,
        private ?MembershipLifecycleCoordinator $coordinator = null
    ) {
        $this->coordinator ??= new MembershipLifecycleCoordinator($grantService);
    }

    public function run(): void
    {
        // --- Grant maintenance (always runs, regardless of subscription state) ---

        // Anchor grants must be paused before the generic expiry runs
        $result = $this->coordinator->checkValidity();
        $anchorPaused = (int) $result['anchor_paused'];
        if ($anchorPaused > 0) {
            Logger::log('Validity check', sprintf('%d overdue anchor grants paused', $anchorPaused));
        }

        // Term-expired grants (including lifetime with a term cap) must be
        // caught before the generic expiry — they may have expires_at = null
        $termExpired = (int) $result['term_expired'];
        if ($termExpired > 0) {
            Logger::log('Validity check', sprintf('%d term-expired grants expired', $termExpired));
        }

        $expired = (int) $result['expired'];

        if ($expired > 0) {
            Logger::log('Validity check', sprintf('%d overdue grants expired', $expired));
        }
    }

}
