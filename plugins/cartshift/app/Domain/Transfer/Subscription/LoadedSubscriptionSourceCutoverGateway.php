<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Subscription\Source\WooSubscriptionRecordSource;
use CartShift\Domain\Subscription\SourceRenewalGuard;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

final readonly class LoadedSubscriptionSourceCutoverGateway implements SubscriptionSourceCutoverGateway
{
    public function __construct(private SourceRenewalGuard $guard = new SourceRenewalGuard()) {}

    public function inspect(SourceIdentity $identity): array
    {
        $subscription = $this->subscription($identity);
        $renewal = $this->guard->inspect($subscription);
        [$fingerprint, $comparison] = $this->fingerprints($identity);
        return [
            'source_fingerprint' => $fingerprint,
            'release_comparison_fingerprint' => $comparison,
            'renewal_fingerprint' => (string) $renewal['fingerprint'],
            'requires_manual_renewal' => (bool) $renewal['requires_manual_renewal'],
        ];
    }

    public function release(SourceIdentity $identity): array
    {
        $result = $this->guard->release($this->subscription($identity));
        if (($result['failures'] ?? []) !== []) {
            throw new \RuntimeException('subscription_source_release_guard_blocked:' . implode(',', array_column($result['failures'], 'code')));
        }
        [$fingerprint, $comparison] = $this->fingerprints($identity);
        return [
            'source_fingerprint' => $fingerprint,
            'release_comparison_fingerprint' => $comparison,
            'renewal_fingerprint' => (string) $result['post']['fingerprint'],
            'requires_manual_renewal' => (bool) $result['post']['requires_manual_renewal'],
            'previous_requires_manual_renewal' => (bool) $result['previous_requires_manual_renewal'],
        ];
    }

    private function subscription(SourceIdentity $identity): object
    {
        if ($identity->entityType !== 'subscription' || preg_match('/\A[1-9][0-9]*\z/D', $identity->sourceId) !== 1
            || !function_exists('wcs_get_subscription')) throw new \RuntimeException('subscription_source_cutover_identity_invalid');
        $subscription = wcs_get_subscription((int) $identity->sourceId);
        if (!is_object($subscription)) throw new \RuntimeException('subscription_source_cutover_hydration_failed');
        return $subscription;
    }

    /** @return array{string,string} */
    private function fingerprints(SourceIdentity $identity): array
    {
        $selection = new TransferSelection(
            $identity->sourceKey, SelectionClause::none(), SelectionClause::none(), SelectionClause::none(),
            SelectionClause::ids([(int) $identity->sourceId]),
        );
        $records = iterator_to_array((new WooSubscriptionRecordSource())->records($selection), false);
        if (count($records) !== 1 || $records[0]->identity->canonical() !== $identity->canonical()) {
            throw new \RuntimeException('subscription_source_cutover_fingerprint_unavailable');
        }
        $payload = $records[0]->payload;
        if (!is_array($payload['payment_ownership'] ?? null)) {
            throw new \RuntimeException('subscription_source_cutover_payment_ownership_missing');
        }
        $payload['payment_ownership']['source_requires_manual_renewal'] = false;
        return [
            $records[0]->privateContentDigest,
            \CartShift\Support\CanonicalJson::fingerprint(['normalised_source_subscription' => $payload]),
        ];
    }
}
