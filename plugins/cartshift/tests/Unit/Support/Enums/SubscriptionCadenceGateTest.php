<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support\Enums;

use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Two ways to read a WooCommerce billing cadence live in this codebase, and one
 * of them is wrong for subscriptions.
 *
 * `FcBillingInterval::tryFromWooCommerce()` implements section 7.2's exact
 * six-row table and returns null for everything else. `fromWooCommerce()`
 * collapses: `week/2` becomes weekly, `year/2` becomes yearly, `month/2` and
 * `month/12` become monthly, and an unrecognised period becomes monthly too.
 * On a catalogue row that is a cosmetic wrong answer the owner can edit. On a
 * subscription contract it is a customer billed twelve times a year on a plan
 * they bought once a year — the plan's P1 defect, verbatim.
 *
 * Everything about the lenient method is more inviting than the safe one: the
 * shorter name, a total return type with nothing to check, and an
 * `$interval = 1` default that makes "forgot the multiplier" compile. A
 * `@deprecated` tag is a note to whoever reads the file, and nobody reads the
 * file — so this test pins the call sites instead, in the style
 * ProductTypePredicateAgreementTest already established for
 * `ProductTypes::isVariable()`.
 *
 * Scanning the live `app/` tree rather than listing "the four known sites" is
 * the whole point: a static list goes stale the moment a fifth is added and
 * nobody remembers to update this file to match.
 */
final class SubscriptionCadenceGateTest extends PluginTestCase
{
    /**
     * Every file allowed to call the lenient method, and why.
     *
     * `VariationMapper` and `OrderMapper` write a FluentCart product
     * variation's `repeat_interval` — catalogue and order data, not a billing
     * instruction. A wrong `repeat_interval` on a catalogue row is a number the
     * owner can edit; a wrong `billing_interval` on a subscription row is a
     * customer charged twelve times a year on a plan they bought once a year.
     *
     * `SubscriptionMapper` used to be on this list, carried forward as the P1
     * defect itself. Task 8 removed it — the mapper now takes the target
     * interval off `SubscriptionContract`, which `SubscriptionRecordFactory`
     * fills from section 7.2's exact table and leaves null when there is no
     * equivalent, and `SubscriptionAssessor` blocks on that null with
     * `unsupported_billing_cadence`. There is no cadence conversion left in the
     * subscription write path at all.
     *
     * @var list<string>
     */
    private const array CATALOGUE_CALL_SITES = [
        'app/Domain/Mapping/OrderMapper.php',
        'app/Domain/Mapping/VariationMapper.php',
    ];

    public function testOnlyTheKnownCatalogueCallSitesUseTheLenientMethod(): void
    {
        $this->assertSame(
            self::CATALOGUE_CALL_SITES,
            self::filesCalling('fromWooCommerce'),
            'The set of files calling FcBillingInterval::fromWooCommerce() has changed. A new file must '
            . 'call tryFromWooCommerce() and block on null, or — if it genuinely writes catalogue data '
            . 'rather than a billing instruction — be added to CATALOGUE_CALL_SITES with a reason.',
        );
    }

    /**
     * And the file that carried the defect no longer touches the enum at all.
     *
     * Asserted separately from the list above so it keeps saying so even if
     * somebody later adds a legitimate catalogue call site and edits the list.
     */
    public function testTheSubscriptionMapperNoLongerConvertsACadenceAtAll(): void
    {
        $this->assertNotContains(
            'app/Domain/Mapping/SubscriptionMapper.php',
            self::filesCalling('fromWooCommerce'),
        );
        $this->assertNotContains(
            'app/Domain/Mapping/SubscriptionMapper.php',
            self::filesCalling('tryFromWooCommerce'),
            'The interval reaches the mapper already decided, on SubscriptionContract::$targetInterval.',
        );
    }

    /**
     * The subscription mapping layer this task owns asks the exact table and
     * nothing else. Asserted separately from the list above so it keeps saying
     * so even while the carry-forward entry is still there.
     */
    public function testTheMappingLayerNeverReachesForTheLenientMethod(): void
    {
        $lenient = self::filesCalling('fromWooCommerce');

        foreach ([
            'app/Domain/Mapping/SubscriptionVariantMatcher.php',
            'app/Domain/Mapping/MappingSetValidator.php',
            'app/Domain/Subscription/NormalizedSubscriptionContract.php',
            'app/Http/Controllers/MappingController.php',
        ] as $file) {
            $this->assertNotContains(
                $file,
                $lenient,
                $file . ' decides which variation a subscriber lands on. It must use tryFromWooCommerce().',
            );
        }
    }

    /**
     * Proof the scan is not vacuously passing: the safe method has call sites
     * too, and finding none of those would mean the pattern is broken rather
     * than the codebase clean.
     */
    public function testTheScanFindsTheSafeMethodsCallSitesToo(): void
    {
        $this->assertContains(
            'app/Domain/Subscription/NormalizedSubscriptionContract.php',
            self::filesCalling('tryFromWooCommerce'),
        );
    }

    /**
     * Files under `app/` calling `FcBillingInterval::<method>(`, relative to the
     * plugin root and sorted for a stable diff.
     *
     * Matched on the *qualified* call, which is doing two jobs. Three other
     * enums in this namespace — FcOrderStatus, FcPaymentStatus,
     * FcSubscriptionStatus — also have a `fromWooCommerce()`, and OrderMapper
     * and SubscriptionMapper each call one of those as well; a bare method-name
     * scan would report every status conversion in the plugin as a cadence
     * violation. And comment lines are skipped, so a docblock explaining why a
     * file does *not* use the method — MappingController has one — is not a hit.
     *
     * `tryFromWooCommerce` does not contain the substring `fromWooCommerce`
     * (its F is capitalised), so the two patterns cannot shadow each other.
     *
     * @return list<string>
     */
    private static function filesCalling(string $method): array
    {
        $root = rtrim(CARTSHIFT_PLUGIN_PATH, '/');

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS),
            ),
            '/\.php$/',
        );

        $hits = [];

        foreach ($files as $file) {
            $path     = (string) $file->getPathname();
            $contents = file_get_contents($path);

            if ($contents === false) {
                continue;
            }

            $pattern = '/FcBillingInterval::' . $method . '\(/';

            $relevant = false;

            foreach (explode("\n", $contents) as $line) {
                // Comment lines are prose about the method, not calls to it.
                if (preg_match('/^\s*(\*|\/\/)/', $line) === 1) {
                    continue;
                }

                if (preg_match($pattern, $line) === 1) {
                    $relevant = true;

                    break;
                }
            }

            if ($relevant) {
                $hits[] = ltrim(substr($path, strlen($root)), '/');
            }
        }

        sort($hits);

        return $hits;
    }

    // ──────────────────────────────────────────────
    // What the lenient arm actually does now
    // ──────────────────────────────────────────────

    /**
     * The lenient method delegates to the exact table before falling back, so
     * it inherited that table's trimming and lower-casing. `' Year '` now
     * returns Yearly where it previously fell through the `match` and became
     * Monthly.
     *
     * That is an improvement — a stray space in WooCommerce meta is not a
     * different billing cadence — but it is a behaviour change on the
     * catalogue path, so it is pinned rather than left implicit.
     */
    public function testTheLenientArmNowTrimsAndLowerCasesThePeriod(): void
    {
        $this->assertSame(FcBillingInterval::Yearly, FcBillingInterval::fromWooCommerce(' Year '));
        $this->assertSame(FcBillingInterval::Yearly, FcBillingInterval::fromWooCommerce('YEAR', 2));
        $this->assertSame(FcBillingInterval::Weekly, FcBillingInterval::fromWooCommerce("\tweek\n", 3));
        $this->assertSame(FcBillingInterval::Daily, FcBillingInterval::fromWooCommerce('Day', 9));
    }

    /**
     * And the collapse it is deprecated for is still exactly as it was, because
     * changing it is Task 8's job on the subscription path and nobody's on the
     * catalogue one.
     */
    public function testTheCollapseItselfIsUnchanged(): void
    {
        $this->assertSame(FcBillingInterval::Weekly, FcBillingInterval::fromWooCommerce('week', 2));
        $this->assertSame(FcBillingInterval::Yearly, FcBillingInterval::fromWooCommerce('year', 2));
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('month', 2));
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('month', 12));
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('unknown-period'));
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('month', 0));
    }
}
