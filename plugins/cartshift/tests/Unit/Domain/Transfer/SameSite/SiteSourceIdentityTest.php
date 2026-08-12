<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\SameSite\SiteSourceIdentity;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The name a same-site shop answers to in a transfer.
 *
 * Every v2 verb needs a source key, `SourceIdentity` refuses the literal
 * `local`, and the subscription plan's own line — "repositories accept a source
 * key with a default of `local`, so same-site behaviour does not change" —
 * predates that refusal. The resolution is a real generated key rather than a
 * hole in the guard, so the first thing asserted here is that the guard still
 * judges what this class produces.
 */
final class SiteSourceIdentityTest extends PluginTestCase
{
    public function testTheGeneratedKeyIsOneTheTransferGuardAccepts(): void
    {
        $key = (new SiteSourceIdentity())->ensure();

        // The real validator, not a copy of its pattern. A key this class
        // invents that `SourceIdentity` would reject is a key every verb
        // downstream refuses.
        SourceIdentity::assertValidSourceKey($key);

        $this->assertNotSame(Constants::DEFAULT_SOURCE_KEY, $key);
        $this->addToAssertionCount(1);
    }

    public function testTheKeyIsGeneratedOnceAndNeverMovesAfterwards(): void
    {
        $identity = new SiteSourceIdentity();

        $first = $identity->ensure();

        // A key that changed between calls would orphan every row already
        // written under the old namespace, silently and unrecoverably.
        $this->assertSame($first, $identity->ensure());
        $this->assertSame($first, (new SiteSourceIdentity())->ensure());
    }

    public function testConcurrentFirstUseReturnsTheOneKeyThatWonAtomicStorage(): void
    {
        $winner = 'site-aaaaaaaaaaaaaaaa';
        $GLOBALS['_cartshift_test_add_option_callback'] = static function (string $option) use ($winner): bool {
            $GLOBALS['_cartshift_test_options'][$option] = $winner;

            return false;
        };

        try {
            self::assertSame($winner, (new SiteSourceIdentity())->ensure());
            self::assertSame($winner, $GLOBALS['_cartshift_test_options']['cartshift_site_source_key']);
        } finally {
            unset($GLOBALS['_cartshift_test_add_option_callback']);
        }
    }

    /**
     * The trap the subscription plan names in as many words: a key derived from
     * the site URL stops being idempotent the moment the same shop is restored
     * under another hostname — a staging clone, a domain change, a rename.
     *
     * Two sites with identical URLs getting identical keys is what derivation
     * looks like, so that is what is asserted against.
     */
    public function testTheKeyIsNotDerivedFromTheSiteUrl(): void
    {
        $GLOBALS['_cartshift_test_options']['home'] = 'https://shop.example';
        $GLOBALS['_cartshift_test_options']['siteurl'] = 'https://shop.example';

        $first = (new SiteSourceIdentity())->ensure();

        unset($GLOBALS['_cartshift_test_options']['cartshift_site_source_key']);

        $second = (new SiteSourceIdentity())->ensure();

        $this->assertNotSame(
            $first,
            $second,
            'Two keys minted under one hostname must differ, or the key is a function of the URL.',
        );

        foreach (['shop', 'example', 'shopexample'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $first);
        }
    }

    public function testARestoredSiteUnderANewHostnameKeepsItsKey(): void
    {
        $GLOBALS['_cartshift_test_options']['home'] = 'https://shop.example';

        $original = (new SiteSourceIdentity())->ensure();

        $GLOBALS['_cartshift_test_options']['home'] = 'https://staging.somewhere-else.test';

        $this->assertSame($original, (new SiteSourceIdentity())->ensure());
    }

    public function testAKeyIsNotMintedMerelyByAsking(): void
    {
        // The screen reads this to display the key. A read that created one
        // would make every status poll a configuration write.
        $this->assertNull((new SiteSourceIdentity())->current());
        $this->assertArrayNotHasKey('cartshift_site_source_key', $GLOBALS['_cartshift_test_options']);
    }

    /**
     * FAIL CLOSED. A stored key the guard rejects — hand-edited to `local`, say,
     * or truncated — must stop the transfer, not be quietly replaced.
     *
     * Regenerating would move the namespace out from under every row already
     * mapped under the old one. A refusal is recoverable; a silent renaming of
     * whose data this is, is not.
     */
    public function testAStoredKeyTheGuardRejectsRefusesRatherThanRegenerating(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_site_source_key'] = Constants::DEFAULT_SOURCE_KEY;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('site_source_key_invalid');

        (new SiteSourceIdentity())->ensure();
    }
}
