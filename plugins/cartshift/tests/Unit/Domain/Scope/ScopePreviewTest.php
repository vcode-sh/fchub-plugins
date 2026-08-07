<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Scope;

use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopePreview;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The wiring, not the counting.
 *
 * ScopeConsequences' own tests prove it filters by entity type when asked.
 * They prove nothing about whether anybody asks. The entity list could be
 * dropped on the way through here and the whole suite would stay green, which
 * is the precise shape of the three defects this branch has already shipped
 * and had to take back: a correct mechanism nobody was calling.
 *
 * No migrators are passed. `counts` is then empty by construction, which keeps
 * these tests about the one thing they are for — the payload the receipt reads
 * describing the run the owner is about to start.
 */
final class ScopePreviewTest extends PluginTestCase
{
    /** @return list<string> */
    private function codes(array $payload): array
    {
        return array_map(
            static fn (array $row): string => $row['code'],
            $payload['consequences'],
        );
    }

    public function testConsequencesAreNarrowedToTheEntitiesBeingMigrated(): void
    {
        $payload = (new ScopePreview([], new ScopeResolver(MigrationScope::everything())))->build(['coupon']);

        $this->assertSame(['coupon_disabled_missing_restrictions'], $this->codes($payload));
    }

    /**
     * The other side of the same wire: a product-only run reports the product
     * consequence and nothing an order would have lost.
     */
    public function testAProductOnlyRunGetsTheProductConsequenceAndNoOthers(): void
    {
        $payload = (new ScopePreview([], new ScopeResolver(MigrationScope::everything())))->build(['product']);

        $this->assertSame(['unsupported_product_type'], $this->codes($payload));
    }

    /**
     * Dependencies are resolved by the caller (useMigration's
     * autoIncludeDependencies), so a real "just orders" request arrives here
     * carrying products and customers — and must be answered for all of them.
     */
    public function testAResolvedOrderRunReportsProductAndOrderConsequencesAlike(): void
    {
        $payload = (new ScopePreview([], new ScopeResolver(MigrationScope::everything())))
            ->build(['product', 'customer', 'order']);

        $this->assertSame(
            ['unsupported_product_type', 'product_link_missing', 'customer_rebuilt_from_order'],
            $this->codes($payload),
        );
    }

    public function testTheRestOfTheEnvelopeIsUnchanged(): void
    {
        $payload = (new ScopePreview([], new ScopeResolver(MigrationScope::everything())))->build(['coupon']);

        $this->assertSame([], $payload['counts'], 'No migrators, no counts — nothing invents one.');
        $this->assertSame('everything', $payload['scope']['mode']);
        $this->assertSame(['products' => 0, 'customers' => 0], $payload['closure']);
        $this->assertFalse($payload['too_large']);
    }
}
