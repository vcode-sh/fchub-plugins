<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

use FluentCart\App\Models\ProductVariation;

/**
 * Hands out SKUs `fct_product_variations` will actually accept.
 *
 * Lifted out of ProductMigrator, which had the only copy, because a second
 * writer appeared: MigrationOrchestratorFactory::createOrphanVariant() adds a
 * variant to a product the owner built by hand, and a variant is a variant —
 * the same UNIQUE index refuses both. Two lookalike implementations of "make
 * this SKU unique" agree until the day one of them is fixed.
 *
 * Two rules, and the second is the one the original missed.
 *
 *  - **Uniqueness.** The column carries two UNIQUE indexes, so a duplicate
 *    INSERT raises `Duplicate entry`, which the framework rethrows as a
 *    QueryException (WPDBConnection::statement()). Probe first, suffix on a
 *    hit, and remember every SKU handed out this run so two records that
 *    collide with each other — not with FluentCart — are caught too.
 *  - **Length.** `sku` is varchar(30) and WordPress strips STRICT_TRANS_TABLES
 *    from every connection (wp-includes/class-wpdb.php, `$incompatible_modes`),
 *    so MariaDB truncates an over-length value instead of refusing it. Probing
 *    a 40-character SKU therefore asks about a value the table will never hold:
 *    two Woo SKUs sharing their first 30 characters both pass the probe and the
 *    second INSERT throws anyway. Clamp first, then probe, so the question and
 *    the stored value are the same string. WooCommerce's own lookup column is
 *    varchar(100), so this is a real gap and not a theoretical one.
 *
 * Suffixing clamps the *stem*, never the suffix: `-wc1234` is what makes the
 * value unique, and truncating that end would hand back a duplicate with extra
 * steps.
 */
final class SkuAllocator
{
    /**
     * `fct_product_variations.sku` is varchar(30). Verified against the live
     * schema, not inferred from the model.
     */
    public const int MAX_LENGTH = 30;

    /** Upper bound on suffix attempts when de-duplicating a SKU. */
    private const int SUFFIX_LIMIT = 50;

    /** @var array<string, true> SKUs already known to be taken in FluentCart or handed out here. */
    private array $known = [];

    /**
     * Normalised from the constructor's $onCollision.
     *
     * A property cannot be declared `callable` in PHP — only `Closure` is a
     * real type — so the broad `callable` accepted publicly is converted once
     * here. Same reasoning as MappingPromoter's two injected predicates.
     */
    private readonly ?\Closure $onCollision;

    /**
     * @param callable(string, string, int): void|null $onCollision Told the
     *        requested SKU, the one handed back, and the WooCommerce ID that
     *        earned the suffix. Optional: a caller with no log to write to
     *        (the orphan path reports through MappingPromoter instead) passes
     *        nothing rather than inventing a sink.
     */
    public function __construct(?callable $onCollision = null)
    {
        $this->onCollision = $onCollision === null ? null : \Closure::fromCallable($onCollision);
    }

    /**
     * A SKU no row in `fct_product_variations` is using, at most 30 characters.
     */
    public function allocate(string $sku, int $wcId): string
    {
        $sku = self::clamp($sku, '');

        if (!$this->exists($sku)) {
            $this->known[$sku] = true;

            return $sku;
        }

        $candidate = self::clamp($sku, '-wc' . $wcId);

        for ($attempt = 2; $attempt <= self::SUFFIX_LIMIT && $this->exists($candidate); $attempt++) {
            $candidate = self::clamp($sku, '-wc' . $wcId . '-' . $attempt);
        }

        $this->known[$candidate] = true;

        if ($this->onCollision !== null) {
            ($this->onCollision)($sku, $candidate, $wcId);
        }

        return $candidate;
    }

    /**
     * Is this SKU already taken, either in FluentCart or by this run?
     */
    private function exists(string $sku): bool
    {
        if (isset($this->known[$sku])) {
            return true;
        }

        $existing = ProductVariation::query()->where('sku', $sku)->first();

        if (!$existing) {
            return false;
        }

        $this->known[$sku] = true;

        return true;
    }

    /**
     * Stem plus suffix, trimmed to what the column holds.
     *
     * Characters, not bytes: varchar(30) counts characters, and mb_substr on a
     * multibyte SKU is the difference between 30 characters and a mangled tail.
     */
    private static function clamp(string $sku, string $suffix): string
    {
        $room = self::MAX_LENGTH - mb_strlen($suffix);

        if ($room <= 0) {
            return mb_substr($suffix, 0, self::MAX_LENGTH);
        }

        return mb_substr($sku, 0, $room) . $suffix;
    }
}
