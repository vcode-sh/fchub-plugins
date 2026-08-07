<?php

declare(strict_types=1);

namespace CartShift\Domain\Scope;

defined('ABSPATH') || exit;

/**
 * What the owner asked to migrate.
 *
 * Immutable and dumb on purpose: it holds a choice, it does not resolve one.
 * The closure — which orders follow which customers, which products an order
 * drags in — lives in ScopeResolver, because that needs the database and this
 * needs to be constructible from a REST body in a unit test.
 *
 * Three modes and no more. A record picker was rejected in design: a
 * 50,000-order shop makes it unusable, and letting someone tick orders and
 * products independently invites contradictions the system then has to
 * adjudicate.
 */
final class MigrationScope
{
    public const string MODE_EVERYTHING = 'everything';
    public const string MODE_SINCE      = 'since';
    public const string MODE_EXPLICIT   = 'explicit';

    /**
     * @param list<int>    $productIds
     * @param list<int>    $customerIds
     * @param list<string> $guestEmails
     */
    private function __construct(
        private readonly string $mode,
        private readonly ?string $sinceDate,
        private readonly array $productIds,
        private readonly array $customerIds,
        private readonly array $guestEmails,
        private readonly bool $includeOrdersForProducts,
    ) {
    }

    public static function everything(): self
    {
        return new self(self::MODE_EVERYTHING, null, [], [], [], false);
    }

    /**
     * Build a scope from whatever a REST body or a stored option holds.
     *
     * Never throws. An unusable value is not a reason to fail a migration the
     * owner has already confirmed — it is a reason to fall back to the mode
     * that cannot lose anything, which is "everything".
     */
    public static function fromArray(mixed $raw): self
    {
        if (!is_array($raw)) {
            return self::everything();
        }

        $mode = is_string($raw['mode'] ?? null) ? $raw['mode'] : self::MODE_EVERYTHING;

        if (!in_array($mode, [self::MODE_EVERYTHING, self::MODE_SINCE, self::MODE_EXPLICIT], true)) {
            return self::everything();
        }

        $since = self::normalizeDate($raw['since'] ?? null);

        if ($mode === self::MODE_SINCE && $since === null) {
            return self::everything();
        }

        return new self(
            $mode,
            $mode === self::MODE_SINCE ? $since : null,
            $mode === self::MODE_EXPLICIT ? self::normalizeIds($raw['product_ids'] ?? []) : [],
            $mode === self::MODE_EXPLICIT ? self::normalizeIds($raw['customer_ids'] ?? []) : [],
            $mode === self::MODE_EXPLICIT ? self::normalizeEmails($raw['guest_emails'] ?? []) : [],
            $mode === self::MODE_EXPLICIT && !empty($raw['include_orders_for_products']),
        );
    }

    /**
     * The persisted and wire shape. Keys are contract — MigrationState, the
     * REST controllers and the Vue app all read exactly these.
     *
     * @return array{mode: string, since: string|null, product_ids: list<int>, customer_ids: list<int>, guest_emails: list<string>, include_orders_for_products: bool}
     */
    public function toArray(): array
    {
        return [
            'mode'                        => $this->mode,
            'since'                       => $this->sinceDate,
            'product_ids'                 => $this->productIds,
            'customer_ids'                => $this->customerIds,
            'guest_emails'                => $this->guestEmails,
            'include_orders_for_products' => $this->includeOrdersForProducts,
        ];
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * The date bound as MySQL wants it: the start of the chosen day, GMT.
     *
     * wc_orders.date_created_gmt is a DATETIME, so a bare Y-m-d compared with
     * `>=` would work by accident and a `<=` would not. Normalising here means
     * no caller ever has to think about it.
     */
    public function since(): ?string
    {
        return $this->sinceDate !== null ? $this->sinceDate . ' 00:00:00' : null;
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return $this->productIds;
    }

    /** @return list<int> */
    public function customerIds(): array
    {
        return $this->customerIds;
    }

    /** @return list<string> */
    public function guestEmails(): array
    {
        return $this->guestEmails;
    }

    public function includesOrdersForProducts(): bool
    {
        return $this->includeOrdersForProducts;
    }

    public function isEverything(): bool
    {
        return $this->mode === self::MODE_EVERYTHING;
    }

    /** A strict Y-m-d, or null. Nothing looser — a fuzzy date bound is a lie. */
    private static function normalizeDate(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $date = trim($raw);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        [$y, $m, $d] = array_map(intval(...), explode('-', $date));

        return checkdate($m, $d, $y) ? $date : null;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function normalizeIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            if (!is_int($value) && !is_string($value)) {
                continue;
            }

            $id = (int) $value;

            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function normalizeEmails(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $emails = [];

        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }

            // Lower-cased because wc_orders.billing_email is compared under a
            // case-insensitive collation, and a scope that disagrees with the
            // query it feeds is a scope that drops customers.
            $email = strtolower(trim($value));

            if ($email !== '') {
                $emails[$email] = true;
            }
        }

        $emails = array_keys($emails);
        sort($emails);

        return $emails;
    }
}
