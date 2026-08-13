<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Audit\LoadedWooSourceApi;
use CartShift\Domain\Transfer\Audit\WooSourceApi;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** The guided route moves current commerce data, not every account and historical subscription in WordPress. */
final readonly class GuidedSourceScope
{
    private const array HISTORICAL_SUBSCRIPTION_STATUSES = [
        'cancelled',
        'canceled',
        'expired',
        'switched',
        'trash',
        'draft',
        'auto-draft',
    ];

    public function __construct(
        public TransferSelection $selection,
        public int $wordpressUsers,
        public int $omittedSubscriptions,
    ) {
        if ($wordpressUsers < 0 || $omittedSubscriptions < 0) {
            throw new \InvalidArgumentException('guided_source_scope_invalid');
        }
    }

    /** @param null|callable(): iterable<int> $userReader */
    public static function read(
        string $sourceKey,
        bool $includeSubscriptions,
        WooSourceApi $source = new LoadedWooSourceApi(),
        ?callable $userReader = null,
    ): self {
        $includedSubscriptions = [];
        $omittedSubscriptions = 0;
        if ($includeSubscriptions) {
            foreach (self::subscriptionIds($source) as $id) {
                $facts = $source->subscription($id);
                if (!is_array($facts) || (int) ($facts['id'] ?? 0) !== $id) {
                    throw new \RuntimeException('guided_subscription_scope_unavailable');
                }
                $status = strtolower(trim((string) ($facts['status'] ?? '')));
                if (in_array($status, self::HISTORICAL_SUBSCRIPTION_STATUSES, true)) {
                    ++$omittedSubscriptions;
                } else {
                    $includedSubscriptions[] = $id;
                }
            }
        }

        $users = $userReader === null
            ? self::loadedUserIds()
            : iterator_to_array((static function () use ($userReader): iterable {
                yield from $userReader();
            })(), false);
        $users = array_values(array_unique(array_map('intval', $users)));
        if (array_filter($users, static fn (int $id): bool => $id <= 0) !== []) {
            throw new \RuntimeException('guided_customer_scope_unavailable');
        }

        return new self(
            new TransferSelection(
                $sourceKey,
                SelectionClause::all(),
                SelectionClause::none(),
                SelectionClause::all(),
                $includedSubscriptions === []
                    ? SelectionClause::none()
                    : SelectionClause::ids($includedSubscriptions),
            ),
            count($users),
            $omittedSubscriptions,
        );
    }

    /** @param iterable<RecordEnvelope> $records @return array<string,int> */
    public function summary(iterable $records): array
    {
        $registered = [];
        $guests = 0;
        $guestEmails = [];
        $unlinkedOrders = 0;
        foreach ($records as $record) {
            if (!$record instanceof RecordEnvelope) {
                continue;
            }
            if ($record->identity->kind() === RecordKind::Order
                && ($record->payload['customer'] ?? null) === null) {
                ++$unlinkedOrders;
            }
            if ($record->identity->kind() !== RecordKind::Customer) {
                continue;
            }
            $classification = $record->payload['classification'] ?? null;
            if ($classification === 'registered') {
                $registered[$record->identity->canonical()] = true;
                continue;
            }
            if ($classification === 'guest') {
                ++$guests;
                $digest = $record->payload['normalized_email_digest'] ?? null;
                if (is_string($digest) && preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1) {
                    $guestEmails[$digest] = true;
                }
            }
        }

        return [
            'included_subscriptions' => count($this->selection->subscriptions->ids),
            'omitted_subscriptions' => $this->omittedSubscriptions,
            'included_registered_customers' => count($registered),
            'omitted_wordpress_accounts' => max(0, $this->wordpressUsers - count($registered)),
            'guest_order_profiles' => $guests,
            'unique_guest_emails' => count($guestEmails),
            'unlinked_order_profiles' => $unlinkedOrders,
        ];
    }

    /** @return list<int> */
    private static function subscriptionIds(WooSourceApi $source): array
    {
        $ids = [];
        $page = 1;
        do {
            $batch = $source->subscriptionCensusPage($page, 100);
            array_push($ids, ...$batch);
            ++$page;
        } while ($batch !== []);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (array_filter($ids, static fn (int $id): bool => $id <= 0) !== []) {
            throw new \RuntimeException('guided_subscription_scope_unavailable');
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return list<int> */
    private static function loadedUserIds(): array
    {
        if (!function_exists('get_users')) {
            throw new \RuntimeException('guided_customer_scope_unavailable');
        }

        return array_map('intval', (array) get_users([
            'fields' => 'ID',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]));
    }
}
