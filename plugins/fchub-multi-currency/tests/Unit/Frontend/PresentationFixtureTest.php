<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Frontend;

use FChubMultiCurrency\Tests\Support\PresentationFixture;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PresentationFixtureTest extends TestCase
{
    /**
     * Guards the other half of the contract.
     *
     * `tests/js/presentation-render.test.mjs` proves the browser reproduces this
     * fixture; this proves the fixture is still what PHP produces. Without both,
     * a change to a translated template would leave JavaScript quietly rendering
     * the old sentence and the suite would stay green.
     *
     * Regenerate with: php tests/js/generate-presentation-fixture.php
     */
    #[Test]
    public function testCheckedInFixtureStillMatchesThePhpRenderers(): void
    {
        $path = dirname(__DIR__, 2) . "/js/presentation-fixture.json";
        $checkedIn = json_decode((string) file_get_contents($path), true);

        $this->assertSame(
            $checkedIn,
            PresentationFixture::build(),
            'The PHP renderers changed. Regenerate presentation-fixture.json so the browser follows.',
        );
    }
}
