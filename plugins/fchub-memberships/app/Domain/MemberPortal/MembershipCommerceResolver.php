<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\MemberPortal;

defined('ABSPATH') || exit;

use Closure;
use FChubMemberships\FluentCRM\Helpers\CheckoutUrlHelper;

final class MembershipCommerceResolver
{
    private Closure $manageUrl;
    private Closure $checkoutUrl;
    private Closure $nextBillingDate;

    public function __construct(
        ?callable $manageUrl = null,
        ?callable $checkoutUrl = null,
        ?callable $nextBillingDate = null
    ) {
        $this->manageUrl = Closure::fromCallable(
            $manageUrl ?? static fn(int $subscriptionId): string => CheckoutUrlHelper::getPaymentUpdateUrl($subscriptionId)
        );
        $this->checkoutUrl = Closure::fromCallable(
            $checkoutUrl ?? static fn(int $planId): string => CheckoutUrlHelper::getCheckoutUrl($planId)
        );
        $this->nextBillingDate = Closure::fromCallable(
            $nextBillingDate ?? static fn(int $subscriptionId): string => CheckoutUrlHelper::getNextBillingDate($subscriptionId)
        );
    }

    /**
     * @return array{action: ?array{kind: string, label: string, url: string}, next_billing_date: ?string}
     */
    public function resolve(array $episode): array
    {
        $sourceType = (string) ($episode['source_type'] ?? 'manual');
        $sourceId = (int) ($episode['source_id'] ?? 0);

        if ($sourceType === 'subscription' && $sourceId > 0) {
            $url = ($this->manageUrl)($sourceId);
            $billingDate = ($this->nextBillingDate)($sourceId);

            return [
                'action' => $url !== '' ? [
                    'kind' => 'manage_subscription',
                    'label' => __('Manage subscription', 'fchub-memberships'),
                    'url' => $url,
                ] : null,
                'next_billing_date' => $billingDate !== '' ? $billingDate : null,
            ];
        }

        $planId = (int) ($episode['plan_id'] ?? 0);
        if (($episode['status'] ?? '') !== 'expired' || $planId <= 0) {
            return ['action' => null, 'next_billing_date' => null];
        }

        $url = ($this->checkoutUrl)($planId);

        return [
            'action' => $url !== '' ? [
                'kind' => 'renew_membership',
                'label' => __('Renew membership', 'fchub-memberships'),
                'url' => $url,
            ] : null,
            'next_billing_date' => null,
        ];
    }
}
