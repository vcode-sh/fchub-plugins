<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\Payment\PayPalReferenceVerifier;
use CartShift\Domain\Subscription\Payment\PayPalSourceMetadataAdapter;
use CartShift\Domain\Subscription\Payment\ProviderReferenceVerifier;
use CartShift\Domain\Subscription\Payment\StripeReferenceVerifier;

/**
 * The verifiers cannot move money. Not "did not" — cannot.
 *
 * A test that merely asserts no charge happened proves only that this
 * particular input did not reach one. What matters is that no input can,
 * because assessment runs against live production credentials and a mistake
 * here bills real people. So the proof is structural and comes in three parts:
 *
 * The outbound seam is a closure taking one string and returning an array or
 * null. There is no HTTP method to set, no request body to attach, and no
 * second parameter through which either could be smuggled.
 *
 * The verifier classes hold no other outbound capability, and that is asserted
 * as an **allowlist** rather than a blacklist of forbidden names. A blacklist
 * is an enumeration, and an enumeration is exactly as complete as whoever wrote
 * it: a second constructor parameter, or a call to any global nobody thought to
 * forbid, would sail straight through one. So the test extracts every symbol
 * either class actually invokes and requires the whole set to be within a short
 * list of string and array helpers. Adding any capability — outbound or
 * otherwise — fails this test by construction rather than by coincidence.
 *
 * And every resource either class actually asks for is a retrieval path, with
 * no query string and no side-effecting verb hiding in it.
 */
final class ProviderReferenceVerifierTest extends PaymentStrategyTestCase
{
    /** @var list<class-string<ProviderReferenceVerifier>> */
    private const array VERIFIERS = [
        StripeReferenceVerifier::class,
        PayPalReferenceVerifier::class,
    ];

    /**
     * Every symbol a verifier is permitted to invoke.
     *
     * Pure string and array helpers, the classes' own private methods, and one
     * value object. Nothing that touches a network, a filesystem, a database,
     * the WordPress option store, or a hook. Anything a future edit adds has to
     * be argued for here first.
     *
     * @var list<string>
     */
    private const array ALLOWED_CALLS = [
        // The direct-access guard every file in this plugin carries.
        'defined',

        // PHP string and array helpers. All pure.
        'array_key_exists',
        'array_filter',
        'in_array',
        'str_starts_with',
        'strtolower',
        'strtoupper',
        'trim',

        // The value object each verifier returns.
        'ProviderVerification',

        // Their own private helpers.
        'get',
        'guardMerchantAndMode',
        'guardModeAndAccount',
        'methodResource',
        'tokenIsUsable',
        'verifyCustomer',
        'verifyMethod',
        'verifyRemoteSchedule',
        'verifyVault',

        // The source metadata contract lookup. Reads the record it was handed.
        'extract',
        'nothing',
    ];

    // ── structural ─────────────────────────────────────────

    public function testTheOutboundSeamHasNoMethodOrBodyParameter(): void
    {
        foreach (self::VERIFIERS as $verifier) {
            $parameters = (new \ReflectionClass($verifier))->getConstructor()?->getParameters() ?? [];

            $this->assertNotSame([], $parameters, $verifier . ' has no retrieval seam.');
            $this->assertSame('retrieve', $parameters[0]->getName());
            $this->assertSame('Closure', (string) $parameters[0]->getType());

            // And no second capability smuggled in beside it. Everything after
            // the seam is a scalar the verifier compares against — an expected
            // account, mode or merchant — or the source metadata contract.
            foreach (array_slice($parameters, 1) as $parameter) {
                $this->assertContains(
                    (string) $parameter->getType(),
                    ['string', PayPalSourceMetadataAdapter::class],
                    sprintf(
                        '%s::$%s is a second capability beside the read-only seam.',
                        $verifier,
                        $parameter->getName(),
                    ),
                );
            }
        }
    }

    public function testTheSeamIsCalledWithExactlyOneArgumentEverywhere(): void
    {
        foreach (self::VERIFIERS as $verifier) {
            $source = (string) file_get_contents((new \ReflectionClass($verifier))->getFileName());

            $this->assertSame(
                1,
                preg_match_all('/\(\$this->retrieve\)\(/', $source),
                $verifier . ' should reach its seam through exactly one call site.',
            );
            $this->assertMatchesRegularExpression(
                '/\(\$this->retrieve\)\(\$resource\);/',
                $source,
                $verifier . ' must pass a resource path and nothing else.',
            );
        }
    }

    /**
     * The allowlist. Every symbol either verifier invokes, and nothing else.
     */
    public function testNeitherVerifierInvokesAnythingOutsideTheAllowlist(): void
    {
        foreach (self::VERIFIERS as $verifier) {
            $called = $this->calledSymbols($verifier);

            $this->assertNotSame([], $called, $verifier . ' parsed to nothing; the analyser is broken.');

            $this->assertSame(
                [],
                array_values(array_diff($called, self::ALLOWED_CALLS)),
                sprintf('%s invokes symbols outside the read-only allowlist.', $verifier),
            );
        }
    }

    /**
     * No dynamic invocation beyond the single declared seam.
     *
     * `$something(...)` would let any callable through and defeat the
     * allowlist entirely, so the only permitted indirect call is
     * `($this->retrieve)(...)`, which the call-site test above already pins.
     */
    public function testNeitherVerifierInvokesAVariable(): void
    {
        foreach (self::VERIFIERS as $verifier) {
            $tokens = token_get_all((string) file_get_contents(
                (new \ReflectionClass($verifier))->getFileName(),
            ));

            foreach ($tokens as $index => $token) {
                if (!is_array($token) || $token[0] !== T_VARIABLE) {
                    continue;
                }

                $this->assertNotSame(
                    '(',
                    $this->nextSignificant($tokens, $index),
                    sprintf('%s invokes %s dynamically.', $verifier, $token[1]),
                );
            }
        }
    }

    public function testBothVerifiersDeclareGetAsTheOnlyPermittedVerb(): void
    {
        $this->assertSame('GET', StripeReferenceVerifier::HTTP_METHOD);
        $this->assertSame('GET', PayPalReferenceVerifier::HTTP_METHOD);
    }

    // ── behavioural ────────────────────────────────────────

    public function testEveryStripeResourceAskedForIsARetrievalPath(): void
    {
        $verifier = $this->stripeVerifier();

        $verifier->verify($this->record('stripePaymentMethod'), $this->environment());
        $verifier->verify($this->record('stripeLegacySource'), $this->environment([
            'legacySourceChargePathProven' => true,
        ]));

        $this->assertNotSame([], $this->reads);

        foreach ($this->reads as $resource) {
            $this->assertMatchesRegularExpression(
                '#^(customers|payment_methods|subscriptions)/[A-Za-z0-9_/]+$#',
                $resource,
            );
            $this->assertStringNotContainsString('?', $resource);
        }
    }

    /**
     * A verifier that could not reach the provider at all reports the gap. It
     * does not decide that the absence of an answer is an answer.
     */
    public function testAnUnreachableProviderProvesNothing(): void
    {
        $verifier = new StripeReferenceVerifier($this->recordingRetrieve([]));

        $verification = $verifier->verify($this->record('stripePaymentMethod'), $this->environment());

        $this->assertFalse($verification->verified());
        $this->assertContains('provider_customer_missing', $verification->reasonCodes);
        $this->assertNull($verification->methodId);
    }

    // ── the analyser ───────────────────────────────────────

    /**
     * Every symbol invoked in a class file: functions, methods, static calls
     * and constructions alike.
     *
     * A name immediately followed by `(` is a call, whatever syntax reached it,
     * so `trim(...)`, `$this->get(...)`, `Foo::bar(...)` and `new Foo(...)` all
     * land in the same set. Declarations are excluded — a `function get(` is
     * not a call — and so are the `use`/`namespace` statements, which never
     * reach a parenthesis.
     *
     * @param class-string $class
     * @return list<string>
     */
    private function calledSymbols(string $class): array
    {
        $tokens = token_get_all((string) file_get_contents((new \ReflectionClass($class))->getFileName()));
        $called = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if ($this->nextSignificant($tokens, $index) !== '(') {
                continue;
            }

            if ($this->previousSignificantToken($tokens, $index) === T_FUNCTION) {
                continue;
            }

            $called[$token[1]] = true;
        }

        $names = array_keys($called);
        sort($names);

        return $names;
    }

    /**
     * @param list<array{0: int, 1: string}|string> $tokens
     */
    private function nextSignificant(array $tokens, int $index): ?string
    {
        for ($cursor = $index + 1, $end = count($tokens); $cursor < $end; $cursor++) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string}|string> $tokens
     */
    private function previousSignificantToken(array $tokens, int $index): ?int
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (!is_array($token)) {
                return null;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0];
        }

        return null;
    }
}
