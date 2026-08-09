<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\MoneyHelper;

final class CouponMapper
{
    /**
     * WC discount type => FC coupon type.
     *
     * FluentCart only understands 'fixed' and 'percent' (app/Services/Coupon/DiscountService.php:252, :389).
     * For 'fixed' the stored amount is compared directly against a cart subtotal in integer
     * minor units, so fixed amounts go through MoneyHelper::toCents(). For 'percent' the stored
     * amount is a plain percentage number (DiscountService.php:396).
     *
     * WooCommerce Subscriptions names its coupon types by convention: the `_percent` suffix means
     * percentage, its absence means a fixed amount. WC Subscriptions is not installed in this
     * environment, so this mapping is derived from that naming convention alone — it has not been
     * verified against WC Subscriptions source.
     */
    private const COUPON_TYPE_MAP = [
        // WooCommerce core.
        'percent'             => 'percent',
        'fixed_cart'          => 'fixed',
        'fixed_product'       => 'fixed',
        // WooCommerce Subscriptions.
        'recurring_percent'   => 'percent',
        'recurring_fee'       => 'fixed',
        'sign_up_fee_percent' => 'percent',
        'sign_up_fee'         => 'fixed',
    ];

    /**
     * Restriction lists whose complete loss makes the coupon apply to MORE than
     * it did in WooCommerce.
     *
     * FluentCart reads an empty restriction list as "no restriction", not as
     * "nothing qualifies" (DiscountService::filterApplicableItems(), around
     * :306-:345 in 1.6.0: every guard is `if ($list && ...)`). So:
     *
     * - `included_products` / `included_categories` emptied — the "only these 3
     *   clearance items" guard vanishes and the coupon covers the whole shop.
     * - `excluded_categories` emptied — the excluded category's products can
     *   still be in FluentCart even when the term is not: migrateCategories()
     *   skips 'uncategorized' outright and logs TermCreationFailed for any term
     *   wp_insert_term() refuses, while the products in it migrate perfectly
     *   happily. The exclusion is lost on goods that are on sale. That widens.
     *
     * `excluded_products` is deliberately absent. A product ID that resolves to
     * nothing is a product that is not in FluentCart at all, so there is no cart
     * item for the lost exclusion to have protected.
     *
     * Public because it is the authority, not a private detail: ScopeConsequences
     * reads it to predict, before anything migrates, which coupons this run would
     * disable. It used to keep its own list, which disagreed with this one in both
     * directions. Adding a key here changes what the preview says as well as what
     * the migration does, which is the point.
     */
    public const array WIDENING_ON_TOTAL_LOSS = [
        'included_products',
        'included_categories',
        'excluded_categories',
    ];

    /**
     * Warnings collected during the last map() call, each with its code.
     *
     * @var list<array{message: string, code: MigrationErrorCode}>
     */
    private array $warnings = [];

    /**
     * What each restriction list looked like before and after the ID map, for
     * the last map() call. Keyed by FluentCart condition key.
     *
     * @var array<string, array{original: list<int>, mapped: list<int>}>
     */
    private array $restrictionAudit = [];

    /**
     * WC product ID => its WC variation IDs, for the life of this mapper.
     *
     * variationWcIdsForProduct() calls wc_get_product() once per restriction ID
     * per coupon, and one migrator instance maps every coupon in a batch. Stores
     * that restrict a lot of coupons to the same handful of products were paying
     * for the same object-cache round trip over and over. Bounded by the number
     * of distinct restricted products in the batch, which is small.
     *
     * @var array<int, list<int>>
     */
    private array $variationIdsByProduct = [];

    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly string $currency,
    ) {}

    /**
     * Map a WC_Coupon to FluentCart coupon data.
     * FIX M10: Use FC's real conditions schema keys.
     */
    public function map(\WC_Coupon $coupon): array
    {
        $this->warnings         = [];
        $this->restrictionAudit = [];

        $wcType = $coupon->get_discount_type();
        $fcType = self::COUPON_TYPE_MAP[$wcType] ?? null;

        if ($fcType === null) {
            // Unrecognised third-party discount type. Guessing costs money either way, so pick
            // the inert option: a fixed discount of 0 is a no-op, whereas guessing 'percent' on
            // an amount meant as currency turns "50 off" into "50% off" and gives the shop away.
            // The coupon is still migrated (code, conditions, usage counts survive) so the store
            // owner only has to re-enter the amount; the warning tells them which ones.
            $this->warnings[] = [
                'message' => sprintf(
                    'Coupon "%s" uses unrecognised discount type "%s" — migrated as a fixed 0 discount. '
                    . 'Set the amount manually in FluentCart.',
                    $coupon->get_code(),
                    $wcType,
                ),
                'code' => MigrationErrorCode::UnknownCouponType,
            ];

            $fcType = 'fixed';
            $amount = 0;
        } elseif ($fcType === 'fixed') {
            $amount = MoneyHelper::toCents($coupon->get_amount(), $this->currency);
        } else {
            $amount = floatval($coupon->get_amount());
        }

        $conditions = $this->mapConditions($coupon);

        $status     = 'active';
        $expiryDate = $coupon->get_date_expires();
        if ($expiryDate && $expiryDate->getTimestamp() < time()) {
            $status = 'expired';
        }

        $status = $this->guardAgainstWidening($coupon, $status);
        $this->warnAboutNarrowing($coupon);

        $mapped = [
            'title'            => $coupon->get_code(),
            'code'             => strtoupper($coupon->get_code()),
            'status'           => $status,
            'type'             => $fcType,
            'amount'           => $amount,
            'conditions'       => !empty($conditions) ? $conditions : null,
            'stackable'        => $coupon->get_individual_use() ? 'no' : 'yes',
            'priority'         => 10,
            'use_count'        => (int) $coupon->get_usage_count(),
            'notes'            => $this->buildNotes($coupon->get_description() ?: ''),
            'show_on_checkout' => 'no',
            // UTC. FluentCart validates these with `strtotime($startDate) > time()`
            // (DiscountService.php:586, :590); WordPress pins PHP's default timezone to UTC, so a
            // naive date string is read as UTC. WC_DateTime::date() renders site-local, which would
            // shift every coupon window by the site's UTC offset.
            'start_date'       => self::toUtcString($coupon->get_date_created()),
            'end_date'         => self::toUtcString($expiryDate),
        ];

        /** @see 'cartshift/mapper/coupon' */
        return apply_filters('cartshift/mapper/coupon', $mapped, $coupon);
    }

    /**
     * Warnings collected during the last map() call.
     *
     * Plain sentences, because that is what every existing caller expects.
     * getCodedWarnings() is the one to reach for when the code matters too.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return array_map(
            static fn (array $warning): string => $warning['message'],
            $this->warnings,
        );
    }

    /**
     * The same warnings, each paired with the reason code it stands for.
     *
     * @return list<array{message: string, code: MigrationErrorCode}>
     */
    public function getCodedWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Render a WC_DateTime as a UTC 'Y-m-d H:i:s' string.
     *
     * WC_DateTime::date() formats against getOffsetTimestamp() (site-local); getTimestamp()
     * is the plain UTC epoch, so gmdate() over it is the UTC rendering.
     */
    private static function toUtcString(?object $date): ?string
    {
        if (!$date || !method_exists($date, 'getTimestamp')) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $date->getTimestamp());
    }

    /**
     * Build the FC conditions array using the correct schema keys.
     * FIX M10: min_purchase_amount (not minimum_amount), max_discount_amount (not maximum_amount),
     * included/excluded products/categories mapped via IdMapRepository.
     */
    private function mapConditions(\WC_Coupon $coupon): array
    {
        $conditions = [];

        // Min purchase amount (FC key: min_purchase_amount).
        $minAmount = $coupon->get_minimum_amount();
        if ($minAmount) {
            $conditions['min_purchase_amount'] = MoneyHelper::toCents($minAmount, $this->currency);
        }

        // Max discount amount (FC key: max_discount_amount).
        $maxAmount = $coupon->get_maximum_amount();
        if ($maxAmount) {
            $conditions['max_discount_amount'] = MoneyHelper::toCents($maxAmount, $this->currency);
        }

        $usageLimit = $coupon->get_usage_limit();
        if ($usageLimit) {
            $conditions['max_uses'] = (int) $usageLimit;
        }

        $usageLimitPerUser = $coupon->get_usage_limit_per_user();
        if ($usageLimitPerUser) {
            $conditions['max_per_customer'] = (int) $usageLimitPerUser;
        }

        if ($coupon->get_exclude_sale_items()) {
            $conditions['exclude_sale_items'] = true;
        }

        if ($coupon->get_free_shipping()) {
            $conditions['free_shipping'] = true;
        }

        // Product and category restrictions, translated through the ID map. Each
        // list is recorded before and after so map() can tell "narrower than WC"
        // (fine) from "no restriction at all" (a discount the shop never agreed
        // to give). Key order is the order FluentCart's own schema lists them in.
        //
        // Product restrictions resolve through variations, not products — see
        // mapProductIdsToVariationIds() for why.
        $restrictions = [
            'included_products'   => ['product', $coupon->get_product_ids()],
            'excluded_products'   => ['product', $coupon->get_excluded_product_ids()],
            'included_categories' => ['category', $coupon->get_product_categories()],
            'excluded_categories' => ['category', $coupon->get_excluded_product_categories()],
        ];

        foreach ($restrictions as $key => [$kind, $wcIds]) {
            $wcIds = array_values(array_map(intval(...), (array) $wcIds));

            if ($wcIds === []) {
                continue;
            }

            $fcIds = $kind === 'product'
                ? $this->mapProductIdsToVariationIds($wcIds)
                : $this->mapIds(Constants::ENTITY_CATEGORY, $wcIds);

            $this->restrictionAudit[$key] = [
                'original' => $wcIds,
                'mapped'   => $fcIds,
            ];

            $conditions[$key] = $fcIds;
        }

        // Email restrictions: comma-separated string in FC.
        $emailRestrictions = $coupon->get_email_restrictions();
        if (!empty($emailRestrictions)) {
            $conditions['email_restrictions'] = implode(',', $emailRestrictions);
        }

        return $conditions;
    }

    /**
     * Map an array of WooCommerce IDs to FluentCart IDs via the ID map.
     * IDs with no mapping are dropped; what that costs is decided in map().
     *
     * @param int[] $wcIds
     *
     * @return list<int>
     */
    private function mapIds(string $entityType, array $wcIds): array
    {
        $fcIds = [];

        foreach ($wcIds as $wcId) {
            $fcId = $this->idMap->getFcId($entityType, (string) $wcId);
            if ($fcId !== null) {
                $fcIds[] = $fcId;
            }
        }

        return $fcIds;
    }

    /**
     * Resolve WC product IDs to the FluentCart variation IDs DiscountService
     * actually compares against.
     *
     * FluentCart's coupon filter checks `included_products` / `excluded_products`
     * against `$item['object_id']` (DiscountService::filterApplicableItems(),
     * 1.6.0 :308, :316), and a cart line item's `object_id` is the FluentCart
     * *variation* ID, not the product post ID — CartHelper::buildCartItem()
     * sets it to `$variation->id` (fluent-cart/app/Helpers/CartHelper.php:54,
     * :123), and checkout carries that value straight through
     * (CheckoutProcessor.php:673, :927). Resolving a restriction through
     * `Constants::ENTITY_PRODUCT` — the product post ID — never matches, so
     * every product restriction silently stopped working the moment a coupon
     * crossed over.
     *
     * A WC product ID has to expand to every one of its FluentCart variation
     * IDs to keep matching what the shop actually restricted.
     *
     * @param int[] $wcProductIds
     *
     * @return list<int>
     */
    private function mapProductIdsToVariationIds(array $wcProductIds): array
    {
        $fcIds = [];

        foreach ($wcProductIds as $wcProductId) {
            foreach ($this->variationWcIdsForProduct($wcProductId) as $variationWcId) {
                $fcId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $variationWcId);

                // `> 0`, not merely `!== null`. A zero would be written straight
                // into `included_products`, and DiscountService compares that
                // list against each cart item's `object_id` — which is 0 on
                // every fee line CartShift writes. A coupon restricted to three
                // clearance items would start discounting surcharges.
                if ($fcId !== null && $fcId > 0) {
                    $fcIds[] = $fcId;
                }
            }
        }

        return array_values(array_unique($fcIds));
    }

    /**
     * Every key one WC product's FluentCart variations could have been stored
     * under.
     *
     * A union, not a choice, and that is the fix rather than an accident.
     * ProductMigrator stores a variable product's variations under each
     * variation's own WC ID and a simple product's single variation under the
     * *product's* WC ID — so which key applies depends on how CartShift decided
     * to treat the product, and re-deriving that decision from WooCommerce here
     * is how the two came to disagree.
     *
     * They used to disagree for exactly one type. `variable-subscription` has
     * children, so this used to return them and only them; but every site that
     * decided variable-or-not compared the bare literal `'variable'` (ProductMapper,
     * ProductMigrator, MappingController — see ProductTypes::isVariable(), added
     * to end the disagreement), so such a product was migrated — and promoted —
     * as a *simple* one, keyed by the product ID. The children resolved to
     * nothing. For `included_products` that was noisy but safe: guardAgainstWidening()
     * disables the coupon. For `excluded_products` nothing caught it at all,
     * because WIDENING_ON_TOTAL_LOSS deliberately omits that key on the
     * reasoning that an unresolvable product is not in FluentCart to be
     * discounted — true for a product that failed to migrate, and false for one
     * that migrated perfectly well under a key this method did not ask about.
     * The coupon then discounted a product the shop explicitly excluded.
     *
     * Asking for both keys unconditionally, below, is what kept this class
     * correct through that whole episode without anyone noticing: it never
     * needed to know which literal a product's type matched.
     *
     * Asking for both keys costs one extra lookup per product and cannot widen
     * anything: a key nothing was stored under simply resolves to nothing.
     *
     * @return list<int>
     */
    private function variationWcIdsForProduct(int $wcProductId): array
    {
        if (isset($this->variationIdsByProduct[$wcProductId])) {
            return $this->variationIdsByProduct[$wcProductId];
        }

        $product = wc_get_product($wcProductId);
        $ids     = [$wcProductId];

        if ($product instanceof \WC_Product) {
            foreach ($product->get_children() as $childId) {
                $ids[] = (int) $childId;
            }
        }

        return $this->variationIdsByProduct[$wcProductId] = array_values(array_unique(
            array_filter($ids, static fn (int $id): bool => $id > 0),
        ));
    }

    /**
     * Take the coupon out of circulation if migration has turned it into a
     * shop-wide discount.
     *
     * "20% off these three clearance items" whose three IDs all fail to resolve
     * is "20% off everything" in FluentCart, and it is live the moment the row
     * is written. Nothing about a status of 'disabled' is recoverable from the
     * outside, which is the point: a human decides what the coupon should have
     * covered, using the IDs preserved in the notes, and re-enables it.
     *
     * 'disabled' rather than 'expired': DiscountService ends its status check
     * with `if ($status !== 'active')` (1.6.0, :573-:580) so any non-active
     * value is equally unredeemable, and Coupon::scopeActive() keeps it out of
     * active listings — but only 'disabled' states the actual reason.
     */
    private function guardAgainstWidening(\WC_Coupon $coupon, string $status): string
    {
        $widened = $this->widenedRestrictions();

        if ($widened === []) {
            return $status;
        }

        $lost = 0;
        foreach ($widened as $count) {
            $lost += $count;
        }

        $this->warnings[] = [
            'message' => sprintf(
                'Coupon "%s" lost every ID in %s (%d WooCommerce %s unmapped), which in FluentCart '
                . 'means no restriction at all — the coupon would have applied shop-wide. Migrated as '
                . '"disabled"; the original WooCommerce IDs are in the coupon notes.',
                $coupon->get_code(),
                implode(' and ', array_keys($widened)),
                $lost,
                $lost === 1 ? 'ID' : 'IDs',
            ),
            'code' => MigrationErrorCode::CouponDisabledMissingRestrictions,
        ];

        return 'disabled';
    }

    /**
     * Note the restrictions that came through thinner than they went in.
     *
     * A coupon that kept some of its IDs is narrower than it was in WooCommerce,
     * and so is one whose excluded products are gone: the excluded product is
     * not in FluentCart to be discounted. Wrong, worth saying out loud, but it
     * gives nothing away — so the coupon stays exactly as active as it was.
     */
    private function warnAboutNarrowing(\WC_Coupon $coupon): void
    {
        $widened = $this->widenedRestrictions();
        $thinned = [];

        foreach ($this->restrictionAudit as $key => $audit) {
            if (isset($widened[$key])) {
                continue;
            }

            $missing = count($audit['original']) - count($audit['mapped']);
            if ($missing > 0) {
                $thinned[$key] = $missing;
            }
        }

        if ($thinned === []) {
            return;
        }

        $parts = [];
        foreach ($thinned as $key => $missing) {
            $parts[] = sprintf('%s (%d of %d)', $key, $missing, count($this->restrictionAudit[$key]['original']));
        }

        $this->warnings[] = [
            'message' => sprintf(
                'Coupon "%s" could not map every restriction ID: %s. The coupon is still active but '
                . 'covers less than it did in WooCommerce. The original WooCommerce IDs are in the coupon notes.',
                $coupon->get_code(),
                implode(', ', $parts),
            ),
            'code' => MigrationErrorCode::CouponRestrictionsNarrowed,
        ];
    }

    /**
     * Restriction lists that had entries and resolved to none, counted by key.
     *
     * @return array<string, int>
     */
    private function widenedRestrictions(): array
    {
        $widened = [];

        foreach (self::WIDENING_ON_TOTAL_LOSS as $key) {
            $audit = $this->restrictionAudit[$key] ?? null;

            if ($audit !== null && $audit['original'] !== [] && $audit['mapped'] === []) {
                $widened[$key] = count($audit['original']);
            }
        }

        return $widened;
    }

    /**
     * The coupon description, plus an audit trail of the restriction IDs when
     * any of them were lost.
     *
     * Whoever has to repair the coupon needs to know what it was restricted to,
     * and after migration the WooCommerce IDs are the only record of that. They
     * go in `notes` (fct_coupons.notes is LONGTEXT NOT NULL) because that is the
     * one field on the coupon a human reads. The description keeps its place at
     * the top; nothing already there is overwritten.
     */
    private function buildNotes(string $description): string
    {
        $anyLost = false;
        foreach ($this->restrictionAudit as $audit) {
            if (count($audit['mapped']) < count($audit['original'])) {
                $anyLost = true;
                break;
            }
        }

        if (!$anyLost) {
            return $description;
        }

        $lines   = [];
        $lines[] = '[CartShift] Some WooCommerce coupon restrictions could not be mapped to FluentCart.';

        if ($this->widenedRestrictions() !== []) {
            $lines[] = 'This coupon was migrated as "disabled": with those restrictions empty, FluentCart '
                . 'would have applied it to the entire shop. Restore the restrictions, then set it back to active.';
        }

        foreach ($this->restrictionAudit as $key => $audit) {
            $lines[] = sprintf(
                '%s: WooCommerce IDs [%s] -> FluentCart IDs [%s]',
                $key,
                implode(', ', $audit['original']),
                $audit['mapped'] === [] ? 'none' : implode(', ', $audit['mapped']),
            );
        }

        $audit = implode("\n", $lines);

        return $description === '' ? $audit : $description . "\n\n" . $audit;
    }
}
