<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Domain\Mapping\MappingSetValidator;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Subscription\RuntimeCompatibilityReport;
use CartShift\Domain\Subscription\SourceTopology;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The one serialisation everything CartShift fingerprints goes through.
 *
 * Two properties are load-bearing and both were broken in hand-rolled copies:
 * a fingerprint must never collapse to a constant, and two different payloads
 * must never share one. `--approve-system-settings=<sha256>` binds an operator's
 * approval to a hash; a hash that can silently become SHA-256 of the empty
 * string is an approval token that matches by construction.
 */
final class CanonicalJsonTest extends PluginTestCase
{
    /** The digest of the empty string — what the old `(string) json_encode()` cast produced. */
    private const string EMPTY_DIGEST = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * Every `assertNotSame(EMPTY_DIGEST, …)` below is worthless if the constant
     * is not actually the digest it claims to be, so it is pinned here rather
     * than trusted.
     */
    public function testTheEmptyDigestConstantIsWhatItSaysItIs(): void
    {
        $this->assertSame(hash('sha256', ''), self::EMPTY_DIGEST);
    }

    // ──────────────────────────────────────────────
    // Deterministic
    // ──────────────────────────────────────────────

    public function testAssociativeKeysAreSortedAllTheWayDown(): void
    {
        $this->assertSame(
            ['a' => ['x' => 1, 'y' => 2], 'b' => 3],
            CanonicalJson::sortDeep(['b' => 3, 'a' => ['y' => 2, 'x' => 1]]),
        );
    }

    /**
     * Lists keep their order. A census group order and a related-order sequence
     * are both meaningful and are sorted where they are built; reordering them
     * here would silently change what the caller meant.
     */
    public function testListsKeepTheirOrder(): void
    {
        $this->assertSame(
            ['items' => ['c', 'a', 'b']],
            CanonicalJson::sortDeep(['items' => ['c', 'a', 'b']]),
        );
    }

    public function testKeyOrderDoesNotChangeTheFingerprint(): void
    {
        $this->assertSame(
            CanonicalJson::fingerprint(['b' => 2, 'a' => 1]),
            CanonicalJson::fingerprint(['a' => 1, 'b' => 2]),
        );
    }

    public function testListOrderDoesChangeTheFingerprint(): void
    {
        $this->assertNotSame(
            CanonicalJson::fingerprint(['x' => ['a', 'b']]),
            CanonicalJson::fingerprint(['x' => ['b', 'a']]),
        );
    }

    // ──────────────────────────────────────────────
    // Well-formed input is byte-identical to what the copies produced
    // ──────────────────────────────────────────────

    /**
     * The extraction must not move a single existing fingerprint. Both call
     * sites previously ran exactly this expression, so this pins the new helper
     * against the old one rather than against a value copied out of a run.
     */
    public function testWellFormedInputEncodesExactlyAsTheReplacedExpressionDid(): void
    {
        $payload = [
            'subscription_census'   => ['by_status' => ['active' => 3, 'canceled' => 1], 'groups' => ['b', 'a']],
            'subscription_settings' => ['mode' => 'store_managed', 'system_charge' => true, 'price' => 29.0],
            'unicode'               => 'Klubu Przyjaciół Psów / półroczne',
            'slashes'               => 'https://example.invalid/a/b',
        ];

        $legacy = (string) json_encode(
            CanonicalJson::sortDeep($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        $this->assertSame($legacy, CanonicalJson::encode($payload));
        $this->assertSame(hash('sha256', $legacy), CanonicalJson::fingerprint($payload));
    }

    public function testTheFlagsAreThePartOfTheContractTheyLookLike(): void
    {
        // Unescaped slashes and unicode, and a zero fraction that survives.
        $encoded = CanonicalJson::encode(['u' => 'żółć', 'p' => 'a/b', 'n' => 29.0]);

        $this->assertStringContainsString('żółć', $encoded);
        $this->assertStringContainsString('a/b', $encoded);
        $this->assertStringContainsString('29.0', $encoded);
    }

    // ──────────────────────────────────────────────
    // Total — never a digest of the empty string
    // ──────────────────────────────────────────────

    /**
     * The defect. `json_encode()` returns false on malformed UTF-8, and the
     * `(string)` cast turned that into `''`.
     */
    public function testMalformedTextNeverFingerprintsAsTheEmptyString(): void
    {
        $fingerprint = CanonicalJson::fingerprint(['name' => "Kubu\xB3 Przyjaci\xF3\xB3"]);

        $this->assertNotSame(self::EMPTY_DIGEST, $fingerprint);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
    }

    public function testMalformedTextEncodesRatherThanReturningNothing(): void
    {
        $this->assertNotSame('', CanonicalJson::encode(['name' => "\xB3\xF3"]));
    }

    /**
     * INF, NAN, recursion and depth are programmer errors rather than source
     * data. Loud, not a constant.
     */
    public function testAnUnencodableNumberThrowsRatherThanHashingToAConstant(): void
    {
        $this->expectException(\JsonException::class);

        CanonicalJson::encode(['n' => INF]);
    }

    // ──────────────────────────────────────────────
    // Injective — different rubbish keeps different hashes
    // ──────────────────────────────────────────────

    public function testTwoDifferentMalformedValuesDoNotCollide(): void
    {
        $this->assertNotSame(
            CanonicalJson::fingerprint(['name' => "\xB3\xB3"]),
            CanonicalJson::fingerprint(['name' => "\xF3\xF3"]),
        );
    }

    public function testAMalformedKeyIsSubstitutedToo(): void
    {
        $a = CanonicalJson::fingerprint(["\xB3" => 'x']);
        $b = CanonicalJson::fingerprint(["\xF3" => 'x']);

        $this->assertNotSame($a, $b);
        $this->assertNotSame(self::EMPTY_DIGEST, $a);
    }

    /**
     * An object is a container too.
     *
     * The escaper recursed into arrays and handed objects straight back, so a
     * mangled byte sequence one level inside a `stdClass` walked past the marker
     * substitution and took `json_encode()` down with it — which is a
     * `JsonException`, and `SubscriptionSelection::fingerprint()` is called
     * inside `stage()` with nothing catching one.
     */
    public function testMalformedTextInsideAnObjectIsSubstitutedRatherThanThrown(): void
    {
        $encoded = CanonicalJson::encode(['row' => (object) ['name' => "Ma\xB3gorzata"]]);

        $this->assertStringContainsString('sha256:', $encoded);
        $this->assertStringNotContainsString("\xB3", $encoded);

        $this->assertNotSame(
            CanonicalJson::fingerprint(['row' => (object) ['name' => "\xB3\xB3"]]),
            CanonicalJson::fingerprint(['row' => (object) ['name' => "\xF3\xF3"]]),
        );
    }

    /**
     * And the object's shape survives it: an empty object is `{}`, never `[]`,
     * and its properties come out in a fixed order so the fingerprint does not
     * depend on which one was assigned first.
     */
    public function testAnObjectKeepsItsShapeAndGainsADeterministicOrder(): void
    {
        $this->assertSame('{"empty":{}}', CanonicalJson::encode(['empty' => new \stdClass()]));

        $this->assertSame(
            CanonicalJson::fingerprint(['row' => (object) ['b' => 2, 'a' => 1]]),
            CanonicalJson::fingerprint(['row' => (object) ['a' => 1, 'b' => 2]]),
        );
    }

    /**
     * There are two copies of this canonicaliser — `CanonicalJson` and
     * `SubscriptionRecordFactory` — and they must answer identically or the
     * package written by one and verified by the other disagrees.
     */
    public function testTheSubscriptionCopyCanonicalisesObjectsTheSameWay(): void
    {
        $payload = ['row' => (object) ['b' => 2, 'name' => "Ma\xB3gorzata", 'a' => 1], 'empty' => new \stdClass()];

        $this->assertSame(
            CanonicalJson::encode($payload),
            \CartShift\Domain\Subscription\SubscriptionRecordFactory::canonicalJson($payload),
        );
    }

    public function testTheMarkerHidesTheBytesItKeepsApart(): void
    {
        // A malformed value may be a mangled payment reference, and the plan's
        // Global Constraints keep those out of fingerprint inputs and reports.
        $encoded = CanonicalJson::encode(['token' => "pm_\xB3secret"]);

        $this->assertStringContainsString('sha256:', $encoded);
        $this->assertStringNotContainsString('secret', $encoded);
    }

    // ──────────────────────────────────────────────
    // The two call sites, at their own level
    // ──────────────────────────────────────────────

    /**
     * The concrete hole this extraction closes. `RuntimeCompatibilityReport`
     * carried its own `(string) json_encode()`, so two target runtimes whose
     * census contained differently mangled text both fingerprinted as SHA-256
     * of the empty string — and that hash is what an operator binds an approval
     * to with `--approve-system-settings`.
     */
    public function testTwoDifferentlyMangledReportsDoNotShareAFingerprint(): void
    {
        $first  = $this->report(['label' => "\xB3\xB3"]);
        $second = $this->report(['label' => "\xF3\xF3"]);

        $this->assertNotSame($first->fingerprint(), $second->fingerprint());
        $this->assertNotSame(self::EMPTY_DIGEST, $first->fingerprint());
        $this->assertNotSame(self::EMPTY_DIGEST, $second->fingerprint());
    }

    public function testAWellFormedReportFingerprintIsUnchangedByTheExtraction(): void
    {
        $report = $this->report(['by_status' => ['active' => 3]]);

        $legacy = hash('sha256', (string) json_encode(
            CanonicalJson::sortDeep([
                'fluent_cart_booted'    => true,
                'role'                  => 'target',
                'subscription_census'   => ['by_status' => ['active' => 3]],
                'subscription_settings' => ['mode' => 'store_managed'],
            ]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));

        $this->assertSame($legacy, $report->fingerprint());
    }

    public function testAMappingSetFingerprintIsAlsoNeverTheEmptyDigest(): void
    {
        $validation = (new MappingSetValidator())->validate([
            ProductMapDecision::link(42, "simple\xB3", 88, 'none', [42 => 501]),
        ]);

        $this->assertNotSame(self::EMPTY_DIGEST, $validation->fingerprint());
    }

    /**
     * @param array<string, mixed> $census
     */
    private function report(array $census): RuntimeCompatibilityReport
    {
        return new RuntimeCompatibilityReport(
            'target',
            SourceTopology::SameRuntime,
            [],
            [],
            [],
            [],
            ['booted' => true],
            ['mode' => 'store_managed'],
            $census,
            [],
        );
    }
}
