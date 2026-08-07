<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Scope;

use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeConsequences;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Tests\Unit\PluginTestCase;

final class ScopeConsequencesTest extends PluginTestCase
{
    public function testEverythingProducesNoScopeDrivenConsequences(): void
    {
        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        foreach ($consequences as $consequence) {
            $this->assertSame(
                0,
                $consequence['count'],
                sprintf('%s must be zero when nothing is left behind.', $consequence['code']),
            );
        }
    }

    public function testEveryConsequenceCarriesTheFullDescriptorTheUiRendersFrom(): void
    {
        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        $this->assertNotSame([], $consequences);

        foreach ($consequences as $consequence) {
            $this->assertArrayHasKey('code', $consequence);
            $this->assertArrayHasKey('label', $consequence);
            $this->assertArrayHasKey('hint', $consequence);
            $this->assertArrayHasKey('severity', $consequence);
            $this->assertArrayHasKey('category', $consequence);
            $this->assertArrayHasKey('count', $consequence);
            $this->assertArrayHasKey('remedy', $consequence);
        }
    }

    public function testARemedyNamesTheProductsThatWouldCloseTheGap(): void
    {
        // A consequence with a one-click fix must carry the ids that fix needs.
        // A remedy the UI cannot apply is worse than no remedy: it promises.
        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        foreach ($consequences as $consequence) {
            if ($consequence['remedy'] === null) {
                continue;
            }

            $this->assertSame('add_products', $consequence['remedy']['action']);
            $this->assertIsArray($consequence['remedy']['product_ids']);
        }
    }
}
