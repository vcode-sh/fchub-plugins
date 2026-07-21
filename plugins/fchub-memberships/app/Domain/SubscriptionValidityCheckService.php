<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

final class SubscriptionValidityCheckService
{
    public function __construct(
        private AccessGrantService $grantService
    ) {
    }

    public function run(): void
    {
        // --- Grant maintenance (always runs, regardless of subscription state) ---

        // Anchor grants must be paused before the generic expiry runs
        $anchorPaused = $this->grantService->pauseOverdueAnchorGrants();
        if ($anchorPaused > 0) {
            Logger::log('Validity check', sprintf('%d overdue anchor grants paused', $anchorPaused));
        }

        // Term-expired grants (including lifetime with a term cap) must be
        // caught before the generic expiry — they may have expires_at = null
        $termExpired = $this->grantService->expireTermExpiredGrants();
        if ($termExpired > 0) {
            Logger::log('Validity check', sprintf('%d term-expired grants expired', $termExpired));
        }

        $this->grantService->revokeExpiredGracePeriodGrants();
        $expired = $this->grantService->expireOverdueGrantsWithHooks();

        if ($expired > 0) {
            Logger::log('Validity check', sprintf('%d overdue grants expired', $expired));
        }
    }

}
