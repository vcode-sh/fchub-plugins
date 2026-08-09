<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * `allow_shared_target` is the operator saying "yes, I meant these two source
 * products to land on the same FluentCart variation". It rides in the existing
 * `variant_map` envelope rather than in a new column, because the alternative
 * is a schema version for one boolean.
 *
 * Which makes the read path the interesting half: every decision already in the
 * staging table was written before this key existed.
 */
final class ProductMapDecisionTest extends PluginTestCase
{
    /** @param array<string, mixed> $overrides */
    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'wc_id'       => 42,
            'wc_type'     => 'subscription',
            'decision'    => 'link',
            'fc_post_id'  => 88,
            'band'        => 'none',
            'variant_map' => null,
        ], $overrides);
    }

    public function testSharingIsOffByDefault(): void
    {
        $decision = ProductMapDecision::link(42, 'subscription', 88, 'none', [42 => 4101]);

        $this->assertFalse($decision->allowSharedTarget());
        $this->assertFalse($decision->toArray()['allow_shared_target']);
    }

    public function testSharingRoundTripsThroughTheEnvelope(): void
    {
        $decision = ProductMapDecision::link(42, 'subscription', 88, 'none', [42 => 4101], [], true);

        $envelope = $decision->variantEnvelope();

        $this->assertTrue($envelope['allow_shared_target']);

        $reread = ProductMapDecision::fromRow($this->row(['variant_map' => (string) json_encode($envelope)]));

        $this->assertTrue($reread->allowSharedTarget());
        $this->assertSame([42 => 4101], $reread->variantMap());
    }

    /**
     * The compatibility case that matters. A decision saved by CartShift 1.4.x
     * has `{"map":…,"orphans":…}` and no `allow_shared_target` at all; reading
     * it must produce `false`, not a warning, an exception, or a `null` that
     * later reads as "probably fine".
     */
    public function testADecisionSavedBeforeThisKeyExistedReadsAsNotShared(): void
    {
        $legacy = (string) json_encode(['map' => [42 => 4101], 'orphans' => []]);

        $decision = ProductMapDecision::fromRow($this->row(['variant_map' => $legacy]));

        $this->assertFalse($decision->allowSharedTarget());
        $this->assertSame([42 => 4101], $decision->variantMap());
    }

    /**
     * And the older shape still: the bare map, from before the orphan envelope.
     */
    public function testTheBareLegacyMapStillReadsAsNotShared(): void
    {
        $decision = ProductMapDecision::fromRow($this->row([
            'variant_map' => (string) json_encode([42 => 4101]),
        ]));

        $this->assertFalse($decision->allowSharedTarget());
        $this->assertSame([42 => 4101], $decision->variantMap());
    }

    /**
     * The column is client-influenced by way of the browser, so anything that
     * is not literally true is false.
     */
    public function testAnythingOtherThanTrueIsNotSharing(): void
    {
        foreach (['yes', 1, 'true', null, [], 'on'] as $rubbish) {
            $decision = ProductMapDecision::fromRow($this->row([
                'variant_map' => (string) json_encode([
                    'map'                 => [42 => 4101],
                    'orphans'             => [],
                    'allow_shared_target' => $rubbish,
                ]),
            ]));

            $this->assertFalse(
                $decision->allowSharedTarget(),
                sprintf('%s must not be read as an operator opting into a shared target.', var_export($rubbish, true)),
            );
        }
    }

    public function testCreateAndSkipNeverShare(): void
    {
        $this->assertFalse(ProductMapDecision::create(42, 'subscription', 'none')->allowSharedTarget());
        $this->assertFalse(ProductMapDecision::skip(42, 'subscription', 'none')->allowSharedTarget());
    }

    public function testTheWireShapeCarriesTheFlag(): void
    {
        $this->assertSame([
            'wc_id'               => 42,
            'wc_type'             => 'subscription',
            'decision'            => 'link',
            'fc_post_id'          => 88,
            'band'                => 'none',
            'variant_map'         => [42 => 4101],
            'orphans'             => [],
            'allow_shared_target' => true,
        ], ProductMapDecision::link(42, 'subscription', 88, 'none', [42 => 4101], [], true)->toArray());
    }
}
