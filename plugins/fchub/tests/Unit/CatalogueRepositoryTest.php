<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use FChubHub\Catalogue\CatalogueRepository;
use FChubHub\Tests\Support\CatalogueFixtures;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class CatalogueRepositoryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var list<array{url: string, headers: array<string, string>}> */
    private array $requests = [];

    /** @var list<array{name: string, value: mixed}> */
    private array $optionWrites = [];

    /** @var list<array{name: string, value: mixed, ttl: int}> */
    private array $transientWrites = [];

    /** @var list<string> */
    private array $optionDeletes = [];

    private int $now = 1785000000; // 2026-07-25T17:20:00+00:00

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->transients = [];
        $this->requests = [];
        $this->optionWrites = [];
        $this->transientWrites = [];
        $this->optionDeletes = [];

        $this->resetTestGlobals();
    }

    protected function tearDown(): void
    {
        // The validator this class exercises reads two filters out of the
        // globals, and forSite() writes real options and transients. Leaving
        // any of it behind would hand the next class a suite that passes for
        // the wrong reason.
        $this->resetTestGlobals();

        parent::tearDown();
    }

    private function resetTestGlobals(): void
    {
        CatalogueRepository::resetSharedInstanceForTests();

        $GLOBALS['_fchub_hub_test_filters'] = [];
        $GLOBALS['_fchub_hub_test_options'] = [];
        $GLOBALS['_fchub_hub_test_option_autoload'] = [];
        $GLOBALS['_fchub_hub_test_transients'] = [];
        $GLOBALS['_fchub_hub_test_current_blog_id'] = 1;
        $GLOBALS['_fchub_hub_test_http_responses'] = [];
        $GLOBALS['_fchub_hub_test_http_requests'] = [];
    }

    /**
     * @return array{state: string, at: string}
     */
    private static function freshMarker(string $at = '2026-07-24T06:00:00+00:00'): array
    {
        return ['state' => 'fresh', 'at' => $at];
    }

    public function testAFreshTransientServesTheStoredCatalogueWithoutTouchingTheNetwork(): void
    {
        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();
        $this->options['fchub_catalogue_last_refresh'] = '2026-07-24T06:00:00+00:00';
        $this->transients['fchub_catalogue_fresh'] = self::freshMarker();

        $envelope = $this->repository(static fn (): ?array => self::fail('The fresh layer must not make a request.'))->get();

        self::assertSame('remote', $envelope['source']);
        self::assertSame('2026-07-24T06:00:00+00:00', $envelope['last_refresh']);
        self::assertSame(['fchub-p24', 'fchub-memberships'], array_keys($envelope['catalogue']['products']));
        self::assertSame([], $this->requests);
    }

    public function testAForcedRefreshIgnoresTheFreshnessTransient(): void
    {
        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();
        $this->transients['fchub_catalogue_fresh'] = self::freshMarker();

        $envelope = $this->repository($this->respondWith(200, CatalogueFixtures::withHubVersion('1.1.0')))->get(true);

        self::assertCount(1, $this->requests);
        self::assertSame('1.1.0', $envelope['catalogue']['hub']['version']);
    }

    public function testAValidRemoteResponseBecomesTheNewLastKnownGoodCopy(): void
    {
        $envelope = $this->repository($this->respondWith(200, CatalogueFixtures::raw(), 'W/"abc123"'))->get();

        self::assertSame('remote', $envelope['source']);
        self::assertSame('2026-07-25T17:20:00+00:00', $envelope['last_refresh']);

        self::assertSame(
            ['fchub_catalogue_last_good', 'fchub_catalogue_etag', 'fchub_catalogue_last_refresh'],
            array_column($this->optionWrites, 'name')
        );

        // Stored normalised, not raw: docs_path never reaches the database.
        $stored = $this->optionWrites[0]['value'];
        self::assertArrayNotHasKey('docs_path', $stored['products']['fchub-p24']);
        self::assertSame('W/"abc123"', $this->optionWrites[1]['value']);

        self::assertCount(1, $this->transientWrites);
        self::assertSame('fchub_catalogue_fresh', $this->transientWrites[0]['name']);
        self::assertSame(21600, $this->transientWrites[0]['ttl']);
    }

    public function testAnInvalidRemoteResponseNeverOverwritesTheLastKnownGoodCopy(): void
    {
        $poisoned = CatalogueFixtures::raw();
        $poisoned['products']['fchub-p24']['package_url'] = 'https://evil.example/fchub-p24.zip';

        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();
        $this->options['fchub_catalogue_last_refresh'] = '2026-07-24T06:00:00+00:00';

        $envelope = $this->repository($this->respondWith(200, $poisoned))->get();

        self::assertSame('last_good', $envelope['source']);
        self::assertSame('2026-07-24T06:00:00+00:00', $envelope['last_refresh']);
        self::assertSame(
            'https://github.com/vcode-sh/fchub-plugins/releases/download/fchub-p24/v1.0.3/fchub-p24-1.0.3.zip',
            $envelope['catalogue']['products']['fchub-p24']['package_url']
        );

        self::assertNotContains('fchub_catalogue_last_good', array_column($this->optionWrites, 'name'));

        // The only thing a rejected payload is allowed to change is how soon
        // we bother the endpoint again.
        self::assertSame(['fchub_catalogue_fresh'], array_column($this->transientWrites, 'name'));
        self::assertSame('backoff', $this->transientWrites[0]['value']['state']);
    }

    public function testUnparseableRemoteJsonIsTreatedAsAFailure(): void
    {
        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();

        $envelope = $this->repository(static fn (): array => [
            'code' => 200,
            'body' => '<html>Your host has opinions about JSON.</html>',
            'etag' => '',
        ])->get();

        self::assertSame('last_good', $envelope['source']);
        self::assertSame([], $this->optionWrites);
    }

    public function testAServerErrorFallsBackToTheLastKnownGoodCopy(): void
    {
        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();

        $envelope = $this->repository(static fn (): array => ['code' => 503, 'body' => '', 'etag' => ''])->get();

        self::assertSame('last_good', $envelope['source']);
        self::assertSame([], $this->optionWrites);
    }

    public function testATransportFailureFallsBackToTheLastKnownGoodCopy(): void
    {
        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();

        $envelope = $this->repository(static fn (): ?array => null)->get();

        self::assertSame('last_good', $envelope['source']);
        self::assertCount(2, $envelope['catalogue']['products']);
    }

    public function testWithoutAnyStoredCopyItFallsBackToTheBundledCatalogue(): void
    {
        $envelope = $this->repository(static fn (): ?array => null)->get();

        self::assertSame('bundled', $envelope['source']);
        self::assertNull($envelope['last_refresh']);
        self::assertCount(6, $envelope['catalogue']['products']);
        self::assertArrayHasKey('fchub-memberships', $envelope['catalogue']['products']);
    }

    public function testATamperedLastKnownGoodOptionIsIgnored(): void
    {
        $tampered = CatalogueFixtures::normalised();
        $tampered['products']['fchub-p24']['package_url'] = 'https://evil.example/fchub-p24.zip';

        $this->options['fchub_catalogue_last_good'] = $tampered;
        $this->transients['fchub_catalogue_fresh'] = self::freshMarker();

        $envelope = $this->repository(static fn (): ?array => null)->get();

        self::assertSame('bundled', $envelope['source']);
        self::assertSame(
            'https://github.com/vcode-sh/fchub-plugins/releases/download/fchub-p24/v1.0.3/fchub-p24-1.0.3.zip',
            $envelope['catalogue']['products']['fchub-p24']['package_url']
        );

        // Rejected once is rejected for good — the bad row goes, rather than
        // being re-decoded and re-validated on every read from now until the
        // heat death of the endpoint.
        self::assertSame(['fchub_catalogue_last_good'], $this->optionDeletes);
    }

    public function testANotModifiedResponseRefreshesFreshnessWithoutReplacingTheBody(): void
    {
        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();
        $this->options['fchub_catalogue_etag'] = 'W/"abc123"';
        $this->options['fchub_catalogue_last_refresh'] = '2026-07-24T06:00:00+00:00';

        $envelope = $this->repository(static fn (): array => ['code' => 304, 'body' => '', 'etag' => ''])->get();

        self::assertSame('remote', $envelope['source']);
        self::assertSame('2026-07-25T17:20:00+00:00', $envelope['last_refresh']);

        self::assertSame(['fchub_catalogue_last_refresh'], array_column($this->optionWrites, 'name'));
        self::assertSame(['fchub_catalogue_fresh'], array_column($this->transientWrites, 'name'));
        self::assertSame('fresh', $this->transientWrites[0]['value']['state']);
        self::assertSame(21600, $this->transientWrites[0]['ttl']);
    }

    public function testEveryFailurePathBacksOffForFifteenMinutes(): void
    {
        $this->repository(static fn (): ?array => null)->get();

        self::assertSame(['fchub_catalogue_fresh'], array_column($this->transientWrites, 'name'));
        self::assertSame('backoff', $this->transientWrites[0]['value']['state']);
        self::assertSame(900, $this->transientWrites[0]['ttl']);

        // A failed attempt refreshed nothing, so it must not claim otherwise.
        self::assertNotContains('fchub_catalogue_last_refresh', array_column($this->optionWrites, 'name'));
    }

    public function testABackoffWindowKeepsAnOutageFromCostingARequestPerPageLoad(): void
    {
        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();
        $this->transients['fchub_catalogue_fresh'] = ['state' => 'backoff', 'at' => '2026-07-25T17:20:00+00:00'];

        $envelope = $this->repository(static fn (): ?array => self::fail('A backoff window must not dial the endpoint.'))->get();

        // Honest about what is being served: the endpoint failed, so this is
        // the stored copy standing in, not a fresh answer.
        self::assertSame('last_good', $envelope['source']);
        self::assertSame([], $this->requests);
    }

    public function testABackoffWindowWithNothingStoredStillSkipsTheNetwork(): void
    {
        $this->transients['fchub_catalogue_fresh'] = ['state' => 'backoff', 'at' => '2026-07-25T17:20:00+00:00'];

        $envelope = $this->repository(static fn (): ?array => self::fail('A backoff window must not dial the endpoint.'))->get();

        self::assertSame('bundled', $envelope['source']);
        self::assertSame([], $this->requests);
    }

    public function testAnExplicitRefreshIgnoresTheBackoffWindow(): void
    {
        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();
        $this->transients['fchub_catalogue_fresh'] = ['state' => 'backoff', 'at' => '2026-07-25T17:20:00+00:00'];

        // "Try again" has to mean try again, or the refresh button is decorative.
        $envelope = $this->repository($this->respondWith(200, CatalogueFixtures::withHubVersion('1.1.0')))->get(true);

        self::assertCount(1, $this->requests);
        self::assertSame('remote', $envelope['source']);
        self::assertSame('1.1.0', $envelope['catalogue']['hub']['version']);
    }

    public function testAnUnrecognisedFreshnessMarkerIsTreatedAsFresh(): void
    {
        $this->options['fchub_catalogue_last_good'] = CatalogueFixtures::normalised();
        $this->transients['fchub_catalogue_fresh'] = '2026-07-24T06:00:00+00:00';

        $envelope = $this->repository(static fn (): ?array => self::fail('A fresh window must not dial the endpoint.'))->get();

        self::assertSame('remote', $envelope['source']);
    }

    public function testTheResolvedCatalogueIsReusedWithinOneRequest(): void
    {
        $repository = $this->repository($this->respondWith(200, CatalogueFixtures::raw()));

        $first = $repository->get();
        $second = $repository->get();

        self::assertSame($first, $second);
        self::assertCount(1, $this->requests, 'Two reads in one request must not cost two round trips.');
    }

    public function testAnExplicitRefreshInvalidatesTheMemoisedCatalogue(): void
    {
        $repository = $this->repository($this->respondWith(200, CatalogueFixtures::withHubVersion('1.1.0')));

        $repository->get();
        $repository->get(true);

        self::assertCount(2, $this->requests);
    }

    public function testANotModifiedResponseWithNothingStoredRecoversOnTheNextCall(): void
    {
        // The wedge: a stored ETag outliving the copy it described. The server
        // keeps answering 304, so a body never arrives, so last_good is never
        // repopulated — one conditional request per page load, for ever.
        $this->options['fchub_catalogue_etag'] = 'W/"abc123"';

        $first = $this->repository(static fn (): array => ['code' => 304, 'body' => '', 'etag' => ''])->get();

        self::assertSame('bundled', $first['source']);
        self::assertNotContains('fchub_catalogue_last_refresh', array_column($this->optionWrites, 'name'));
        self::assertSame(['fchub_catalogue_etag'], $this->optionDeletes);
        self::assertArrayNotHasKey('fchub_catalogue_etag', $this->options);

        // Second call: no ETag left, so the request goes out unconditionally
        // and comes back with a body.
        $this->requests = [];
        $second = $this->repository($this->respondWith(200, CatalogueFixtures::raw(), 'W/"new"'))->get();

        self::assertArrayNotHasKey('If-None-Match', $this->requests[0]['headers']);
        self::assertSame('remote', $second['source']);
        self::assertIsArray($this->options['fchub_catalogue_last_good']);
    }

    public function testANotModifiedResponseToAnUnconditionalRequestBacksOff(): void
    {
        // No ETag was sent, so a 304 is a broken server rather than a cache
        // hit. There is nothing to clear, so the only way not to spin is to
        // back off.
        $envelope = $this->repository(static fn (): array => ['code' => 304, 'body' => '', 'etag' => ''])->get();

        self::assertSame('bundled', $envelope['source']);
        self::assertSame([], $this->optionDeletes);
        self::assertSame('backoff', $this->transientWrites[0]['value']['state']);
    }

    public function testAConditionalRequestCarriesTheStoredEtagAndTheHubUserAgent(): void
    {
        $this->options['fchub_catalogue_etag'] = 'W/"abc123"';

        $this->repository($this->respondWith(200, CatalogueFixtures::raw()))->get();

        self::assertSame('https://fchub.co/api/v1/products', $this->requests[0]['url']);
        self::assertSame([
            'Accept' => 'application/json',
            'User-Agent' => 'FCHub/1.0.0',
            'If-None-Match' => 'W/"abc123"',
        ], $this->requests[0]['headers']);
    }

    public function testTheFirstRequestSendsNoConditionalHeader(): void
    {
        $this->repository($this->respondWith(200, CatalogueFixtures::raw()))->get();

        self::assertArrayNotHasKey('If-None-Match', $this->requests[0]['headers']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheCatalogueUrlConstantOverridesTheProductionEndpoint(): void
    {
        define('FCHUB_CATALOGUE_URL', 'http://catalogue/api/v1/products');

        $this->repository($this->respondWith(200, CatalogueFixtures::raw()))->get();

        self::assertSame('http://catalogue/api/v1/products', $this->requests[0]['url']);
    }

    public function testTheProductionDefaultsUseTheHubOwnedStorageKeysAndAnEightSecondTimeout(): void
    {
        $GLOBALS['_fchub_hub_test_options'][1]['fchub_catalogue_etag'] = 'W/"live"';
        $GLOBALS['_fchub_hub_test_http_responses'][] = [
            'code' => 200,
            'body' => (string) json_encode(CatalogueFixtures::raw()),
            'headers' => ['etag' => 'W/"fresh"'],
        ];

        $envelope = CatalogueRepository::forSite()->get();

        self::assertSame('remote', $envelope['source']);

        $request = $GLOBALS['_fchub_hub_test_http_requests'][0];
        self::assertSame('https://fchub.co/api/v1/products', $request['url']);
        self::assertSame(8, $request['args']['timeout']);
        self::assertSame('application/json', $request['args']['headers']['Accept']);
        self::assertSame('W/"live"', $request['args']['headers']['If-None-Match']);

        self::assertIsArray($GLOBALS['_fchub_hub_test_options'][1]['fchub_catalogue_last_good']);
        self::assertSame('W/"fresh"', $GLOBALS['_fchub_hub_test_options'][1]['fchub_catalogue_etag']);
        self::assertIsString($GLOBALS['_fchub_hub_test_options'][1]['fchub_catalogue_last_refresh']);
        self::assertSame('fresh', $GLOBALS['_fchub_hub_test_transients'][1]['fchub_catalogue_fresh']['state']);
    }

    public function testNothingTheHubStoresIsEverAutoloaded(): void
    {
        // The catalogue is a few kilobytes of serialised array. Autoloaded, it
        // is unserialised on every front-end request of a shop that never opens
        // wp-admin — which is precisely the cost FCHub claims not to have.
        $GLOBALS['_fchub_hub_test_http_responses'][] = [
            'code' => 200,
            'body' => (string) json_encode(CatalogueFixtures::raw()),
            'headers' => ['etag' => 'W/"fresh"'],
        ];

        CatalogueRepository::forSite()->get();

        $autoload = $GLOBALS['_fchub_hub_test_option_autoload'][1] ?? [];

        self::assertSame(
            [
                CatalogueRepository::OPTION_LAST_GOOD,
                CatalogueRepository::OPTION_ETAG,
                CatalogueRepository::OPTION_LAST_REFRESH,
            ],
            array_keys($autoload),
            'Every option the hub writes has to go through the same guarantee.'
        );

        foreach ($autoload as $option => $value) {
            self::assertFalse($value, $option . ' must be written with autoload disabled.');
        }
    }

    public function testTheProductionDefaultsBackOffThroughTheRealTransientApi(): void
    {
        $GLOBALS['_fchub_hub_test_http_responses'][] = ['wp_error' => 'http_request_failed'];

        CatalogueRepository::forSite()->get();

        self::assertSame('backoff', $GLOBALS['_fchub_hub_test_transients'][1]['fchub_catalogue_fresh']['state']);
    }

    public function testTheSharedInstanceIsReusedAcrossCallersInOneRequest(): void
    {
        $GLOBALS['_fchub_hub_test_http_responses'][] = [
            'code' => 200,
            'body' => (string) json_encode(CatalogueFixtures::raw()),
            'headers' => ['etag' => 'W/"shared"'],
        ];

        // Two unrelated callers — an update check and a REST read, say — each
        // asking the hub for the catalogue during the same request.
        $first = CatalogueRepository::forSiteShared();
        $second = CatalogueRepository::forSiteShared();

        self::assertSame($first, $second);

        $first->get();
        $second->get();

        self::assertCount(1, $GLOBALS['_fchub_hub_test_http_requests'], 'One request, one round trip.');
    }

    public function testForSiteStillHandsBackAnIndependentInstance(): void
    {
        self::assertNotSame(CatalogueRepository::forSite(), CatalogueRepository::forSiteShared());
    }

    public function testAWordPressTransportErrorLeavesEverythingAlone(): void
    {
        $GLOBALS['_fchub_hub_test_http_responses'][] = ['wp_error' => 'http_request_failed'];

        $envelope = CatalogueRepository::forSite()->get();

        self::assertSame('bundled', $envelope['source']);
        self::assertArrayNotHasKey('fchub_catalogue_last_good', $GLOBALS['_fchub_hub_test_options'][1] ?? []);
    }

    public function testACorruptBundledCatalogueFailsLoudlyRatherThanSilently(): void
    {
        $repository = new CatalogueRepository(
            fetch: static fn (): ?array => null,
            readOption: static fn (string $name, mixed $default = null): mixed => $default,
            writeOption: static function (): void {
            },
            deleteOption: static function (): void {
            },
            readTransient: static fn (): mixed => false,
            writeTransient: static function (): void {
            },
            clock: fn (): int => $this->now,
            bundledPath: FCHUB_HUB_PATH . 'resources/no-such-catalogue.json'
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('catalogue_bundled_unreadable');

        $repository->get();
    }

    /**
     * @param callable(string, array<string, string>): (array<string, mixed>|null) $fetch
     */
    private function repository(callable $fetch): CatalogueRepository
    {
        return new CatalogueRepository(
            fetch: function (string $url, array $headers) use ($fetch): ?array {
                $this->requests[] = ['url' => $url, 'headers' => $headers];

                return $fetch($url, $headers);
            },
            readOption: fn (string $name, mixed $default = null): mixed => $this->options[$name] ?? $default,
            writeOption: function (string $name, mixed $value): void {
                $this->optionWrites[] = ['name' => $name, 'value' => $value];
                $this->options[$name] = $value;
            },
            deleteOption: function (string $name): void {
                $this->optionDeletes[] = $name;
                unset($this->options[$name]);
            },
            readTransient: fn (string $name): mixed => $this->transients[$name] ?? false,
            writeTransient: function (string $name, mixed $value, int $ttl): void {
                $this->transientWrites[] = ['name' => $name, 'value' => $value, 'ttl' => $ttl];
                $this->transients[$name] = $value;
            },
            clock: fn (): int => $this->now
        );
    }

    /**
     * @param array<string, mixed> $catalogue
     * @return callable(): array<string, mixed>
     */
    private function respondWith(int $code, array $catalogue, string $etag = ''): callable
    {
        return static fn (): array => [
            'code' => $code,
            'body' => (string) json_encode($catalogue),
            'etag' => $etag,
        ];
    }
}
