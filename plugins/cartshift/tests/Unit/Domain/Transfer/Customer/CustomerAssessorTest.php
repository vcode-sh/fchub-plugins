<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Customer;

use CartShift\Domain\Transfer\Customer\CustomerAssessor;
use CartShift\Domain\Transfer\Customer\CustomerRecord;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class CustomerAssessorTest extends PluginTestCase
{
    public function testEmailEqualityNeverSilentlyMergesAndDuplicatesBlock(): void
    {
        $record = $this->record();
        $single = new CustomerAssessor(static fn (): array => [['id' => 9, 'fingerprint' => str_repeat('9', 64)]]);
        $duplicates = new CustomerAssessor(static fn (): array => [['id' => 9], ['id' => 10]]);

        self::assertSame('requires_mapping_decision', $single->assess($record)->action);
        self::assertSame('blocked_ambiguous_identity', $duplicates->assess($record)->action);
    }

    public function testExactMapExplicitReuseAndSameSiteAttachmentAreSeparateDecisions(): void
    {
        $record = $this->record();
        $mapped = new CustomerAssessor(static fn (): array => [], static fn (): array => ['target_id' => 50, 'target_fingerprint' => str_repeat('a', 64)]);
        self::assertSame('reuse_exact_customer_map', $mapped->assess($record)->action);

        $explicit = new CustomerAssessor(static fn (): array => [], decisions: [
            $record->identity->canonical() => ['action' => 'reuse_explicit_target_customer', 'target_id' => 51, 'source_fingerprint' => $record->envelope()->privateContentDigest, 'target_fingerprint' => str_repeat('b', 64), 'operator' => 'owner', 'reason' => 'reviewed', 'decided_at' => '2026-08-10T12:00:00Z'],
        ]);
        self::assertSame('reuse_explicit_target_customer', $explicit->assess($record)->action);

        $sameSite = new CustomerAssessor(static fn (): array => [], sameSiteUserDecision: static fn (): int => 7);
        self::assertSame('attach_exact_same_site_user', $sameSite->assess($record)->action);
    }

    public function testExactMapReusePreservesAnApprovedSameSiteUserLinkForReconciliation(): void
    {
        $record = $this->record();
        $assessor = new CustomerAssessor(
            static fn (): array => [],
            static fn (): array => ['target_id' => 50, 'target_fingerprint' => str_repeat('a', 64)],
            [
                $record->identity->canonical() => [
                    'action' => 'attach_exact_same_site_user',
                    'user_id' => 7,
                    'source_fingerprint' => $record->envelope()->privateContentDigest,
                    'operator' => 'owner',
                    'reason' => 'Exact same-site user.',
                    'decided_at' => '2026-08-10T12:00:00Z',
                ],
            ],
        );

        $assessment = $assessor->assess($record);

        self::assertSame('reuse_exact_customer_map', $assessment->action);
        self::assertSame(7, $assessment->evidence['user_id']);
    }

    public function testUnlinkedDownloadCustomerRequiresExplicitAllowedLoss(): void
    {
        $record = $this->record();
        $assessor = new CustomerAssessor(static fn (): array => []);

        self::assertSame('requires_mapping_decision', $assessor->assess($record, downloadableOrderCount: 2)->action);
        self::assertSame('create_target_customer_unlinked', $assessor->assess($record, downloadableOrderCount: 2, allowUnlinkedDownloads: true)->action);
    }

    public function testSameSiteAndUnlinkedActionsAreFingerprintBoundRecordDecisions(): void
    {
        $registered = $this->record();
        $sameSite = new CustomerAssessor(static fn (): array => [], decisions: [
            $registered->identity->canonical() => [
                'action' => 'attach_exact_same_site_user',
                'user_id' => 7,
                'source_fingerprint' => $registered->envelope()->privateContentDigest,
                'operator' => 'owner',
                'reason' => 'Exact same-site user.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
        ]);
        self::assertSame('attach_exact_same_site_user', $sameSite->assess($registered)->action);

        $guest = CustomerRecord::create(new SourceIdentity('shop-alpha', 'customer', '91:guest'), null, 'guest', 'Guest', 'Buyer', 'guest@example.test', 'active', [], null, null, ['origin' => 'order_snapshot'], []);
        $allowed = new CustomerAssessor(static fn (): array => [], decisions: [
            $guest->identity->canonical() => [
                'action' => 'allow_unlinked_downloads',
                'affected_orders' => ['shop-alpha:order:91'],
                'downloadable_orders' => ['shop-alpha:order:91'],
                'downloadable_order_count' => 1,
                'source_fingerprint' => $guest->envelope()->privateContentDigest,
                'operator' => 'owner',
                'reason' => 'Guest cannot be attached to a WordPress account.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
        ]);
        self::assertSame('create_target_customer_unlinked', $allowed->assess($guest, downloadableOrderCount: 1)->action);
        self::assertSame('blocked_invalid_customer', $allowed->assess($guest, downloadableOrderCount: 0)->action);
    }

    private function record(): CustomerRecord
    {
        return CustomerRecord::create(new SourceIdentity('shop-alpha', 'customer', '7'), 7, 'registered', 'Ada', 'Lovelace', 'ada@example.test', 'active', [], null, null, ['origin' => 'source_user'], []);
    }
}
