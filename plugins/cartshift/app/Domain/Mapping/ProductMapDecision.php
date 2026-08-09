<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

/**
 * One decision the shop owner made about one WooCommerce product.
 *
 * Immutable and dumb, in the same spirit as MigrationScope: it holds a choice,
 * it does not act on one. Promotion into the ID map is MappingPromoter's job,
 * matching is ProductMatcher's, and this is what travels between them.
 */
final class ProductMapDecision
{
    public const string LINK   = 'link';
    public const string CREATE = 'create';
    public const string SKIP   = 'skip';

    /**
     * What `fct_product_variations.fulfillment_type` is allowed to say.
     *
     * The same three VariationMapper derives from a WooCommerce product, and
     * the reason this is a whitelist rather than a passthrough is that the
     * orphan descriptor makes a round trip through the browser: the value that
     * comes back is client input, and it is written into a column FluentCart
     * branches on when it decides whether an order needs a shipping address.
     *
     * @var list<string>
     */
    private const array FULFILLMENT_TYPES = ['physical', 'digital', 'service'];

    /**
     * @param array<int, int>                                 $variantMap Woo variation ID => FC variation ID
     * @param list<array{id: int, sku: string, name: string, price: int|null, fulfillment_type: string, downloadable: string}> $orphans Woo variations with no FC counterpart
     */
    private function __construct(
        private readonly int $wcId,
        private readonly string $wcType,
        private readonly string $decision,
        private readonly ?int $fcPostId,
        private readonly string $band,
        private readonly array $variantMap,
        private readonly array $orphans,
        private readonly bool $allowSharedTarget,
    ) {
    }

    /**
     * @param array<int, int> $variantMap
     * @param list<array{id: int, sku: string, name: string, price: int|null, fulfillment_type: string, downloadable: string}> $orphans
     *        Woo variations with no counterpart, carried so promotion can add
     *        them to the FC product. The descriptor carries what the variant
     *        will be *created with*, not just what it is called: a price of
     *        zero is a free item in the owner's live catalogue, and CartShift
     *        knows the real one only here, where the WooCommerce object is
     *        still loaded. Optional, so a caller that has not resolved variants
     *        yet still gets a usable decision.
     */
    public static function link(
        int $wcId,
        string $wcType,
        int $fcPostId,
        string $band,
        array $variantMap,
        array $orphans = [],
        bool $allowSharedTarget = false,
    ): self {
        return new self(
            $wcId,
            $wcType,
            self::LINK,
            $fcPostId,
            $band,
            self::normalizeMap($variantMap),
            self::normalizeOrphans($orphans),
            $allowSharedTarget,
        );
    }

    public static function create(int $wcId, string $wcType, string $band): self
    {
        return new self($wcId, $wcType, self::CREATE, null, $band, [], [], false);
    }

    public static function skip(int $wcId, string $wcType, string $band): self
    {
        return new self($wcId, $wcType, self::SKIP, null, $band, [], [], false);
    }

    /**
     * Rebuild from a database row.
     *
     * A `link` row missing its fc_post_id is downgraded to `create` rather than
     * returned as a link with nowhere to point — the alternative is a promotion
     * that writes an ID map row aimed at nothing.
     */
    public static function fromRow(object $row): self
    {
        $decision = (string) $row->decision;
        $fcPostId = $row->fc_post_id !== null ? (int) $row->fc_post_id : null;

        if ($decision === self::LINK && ($fcPostId === null || $fcPostId <= 0)) {
            $decision = self::CREATE;
            $fcPostId = null;
        }

        $variantMap  = [];
        $orphans     = [];
        $allowShared = false;

        if ($decision === self::LINK && is_string($row->variant_map ?? null)) {
            $decoded = json_decode($row->variant_map, true);

            if (is_array($decoded)) {
                // Three shapes live in this column now: the legacy bare map, the
                // envelope that also carries orphans, and the envelope that
                // additionally carries the shared-target opt-in. Reading all
                // three means an upgrade does not have to rewrite existing rows —
                // and a decision saved before the opt-in existed reads as `false`,
                // which is the only safe default: it is the operator's explicit
                // permission, and a row that never gave it never gave it.
                $variantMap = is_array($decoded['map'] ?? null)
                    ? self::normalizeMap($decoded['map'])
                    : self::normalizeMap($decoded);

                $orphans = is_array($decoded['orphans'] ?? null)
                    ? self::normalizeOrphans($decoded['orphans'])
                    : [];

                // Identity, not a cast. This value makes a round trip through
                // the browser, and `'no'`, `'0'` and `'false'` are all truthy
                // strings — each of which would read as the operator having
                // approved a shared billing contract they never saw.
                $allowShared = ($decoded['allow_shared_target'] ?? null) === true;
            }
        }

        return new self(
            (int) $row->wc_id,
            (string) ($row->wc_type ?? ''),
            $decision,
            $fcPostId,
            (string) ($row->band ?? 'none'),
            $variantMap,
            $orphans,
            $allowShared,
        );
    }

    public function wcId(): int
    {
        return $this->wcId;
    }

    public function wcType(): string
    {
        return $this->wcType;
    }

    public function decision(): string
    {
        return $this->decision;
    }

    public function fcPostId(): ?int
    {
        return $this->fcPostId;
    }

    public function band(): string
    {
        return $this->band;
    }

    /** @return array<int, int> */
    public function variantMap(): array
    {
        return $this->variantMap;
    }

    /**
     * Woo variations this link has no FluentCart counterpart for.
     *
     * MappingPromoter creates one FC variant per entry, flagged
     * created_by_migration so rollback removes them again.
     *
     * @return list<array{id: int, sku: string, name: string, price: int|null, fulfillment_type: string, downloadable: string}>
     */
    public function orphans(): array
    {
        return $this->orphans;
    }

    /**
     * Whether the operator explicitly allowed other source products to claim
     * the same FluentCart variations this decision does.
     *
     * Off unless they said so. MappingSetValidator needs it on every decision
     * involved before it will let two sources converge — one product opting in
     * proves nothing about the other's contract.
     */
    public function allowSharedTarget(): bool
    {
        return $this->allowSharedTarget;
    }

    public function isLink(): bool
    {
        return $this->decision === self::LINK;
    }

    public function isSkip(): bool
    {
        return $this->decision === self::SKIP;
    }

    /**
     * The persisted and wire shape. Keys are contract — the repository, the
     * controller and the Vue app all read exactly these.
     *
     * @return array{wc_id: int, wc_type: string, decision: string, fc_post_id: int|null, band: string, variant_map: array<int, int>, orphans: list<array{id: int, sku: string, name: string, price: int|null, fulfillment_type: string, downloadable: string}>, allow_shared_target: bool}
     */
    public function toArray(): array
    {
        return [
            'wc_id'               => $this->wcId,
            'wc_type'             => $this->wcType,
            'decision'            => $this->decision,
            'fc_post_id'          => $this->fcPostId,
            'band'                => $this->band,
            'variant_map'         => $this->variantMap,
            'orphans'             => $this->orphans,
            'allow_shared_target' => $this->allowSharedTarget,
        ];
    }

    /**
     * What the repository writes into the `variant_map` column.
     *
     * An envelope rather than the bare map, because promotion needs the orphan
     * list too and adding a column for it would mean a second schema version
     * for the same feature.
     *
     * @return array{map: array<int, int>, orphans: list<array{id: int, sku: string, name: string, price: int|null, fulfillment_type: string, downloadable: string}>, allow_shared_target: bool}
     */
    public function variantEnvelope(): array
    {
        return [
            'map'     => $this->variantMap,
            'orphans' => $this->orphans,
            // In the envelope rather than a column of its own: the alternative
            // is a schema version for one boolean, and fromRow() already reads
            // two historical shapes of this same JSON.
            'allow_shared_target' => $this->allowSharedTarget,
        ];
    }

    /**
     * Force both sides of the map to integers.
     *
     * json_decode returns string keys for a JSON object, and a string key here
     * poisons every getFcId() built from it — the lookup would compare '11'
     * against 11 in a table whose wc_id column is a string.
     *
     * @param array<array-key, mixed> $map
     * @return array<int, int>
     */
    private static function normalizeMap(array $map): array
    {
        $out = [];

        foreach ($map as $wooId => $fcId) {
            $wooId = (int) $wooId;
            $fcId  = (int) $fcId;

            if ($wooId > 0 && $fcId > 0) {
                $out[$wooId] = $fcId;
            }
        }

        return $out;
    }

    /**
     * The three source-derived fields beyond id/sku/name, each defaulting to
     * "not known" rather than to a value.
     *
     * A decision saved before these fields existed is still in the staging
     * table, and a missing price is not the same fact as a price of zero: the
     * first means "ask the FluentCart product what it charges", the second
     * means "this Woo variation really was free". Absent is therefore null, not
     * 0, and the empty strings mean the same for the other two. The creator
     * resolves each; see MigrationOrchestratorFactory::createOrphanVariant().
     *
     * @param array<array-key, mixed> $orphans
     * @return list<array{id: int, sku: string, name: string, price: int|null, fulfillment_type: string, downloadable: string}>
     */
    private static function normalizeOrphans(array $orphans): array
    {
        $out = [];

        foreach ($orphans as $orphan) {
            if (!is_array($orphan)) {
                continue;
            }

            $id = (int) ($orphan['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $fulfillmentType = (string) ($orphan['fulfillment_type'] ?? '');
            $downloadable    = (string) ($orphan['downloadable'] ?? '');

            $out[] = [
                'id'   => $id,
                'sku'  => (string) ($orphan['sku'] ?? ''),
                'name' => (string) ($orphan['name'] ?? ''),
                // Already in FluentCart's x100 storage format when it arrives —
                // MappingController converts once, through MoneyHelper, where
                // the WooCommerce object is still in hand.
                'price' => isset($orphan['price']) ? max(0, (int) $orphan['price']) : null,
                'fulfillment_type' => in_array($fulfillmentType, self::FULFILLMENT_TYPES, true)
                    ? $fulfillmentType
                    : '',
                'downloadable' => in_array($downloadable, ['true', 'false'], true) ? $downloadable : '',
            ];
        }

        return $out;
    }
}
