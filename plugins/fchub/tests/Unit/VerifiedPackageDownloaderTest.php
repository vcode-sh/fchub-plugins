<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use Closure;
use FChubHub\Operations\OperationError;
use FChubHub\Operations\VerifiedPackageDownloader;
use FChubHub\Tests\Support\CatalogueFixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

/**
 * Everything here runs against an injected downloader. No test in this class
 * may reach the network, which is why the real download_url() never appears.
 */
final class VerifiedPackageDownloaderTest extends TestCase
{
    private const PACKAGE_BODY = 'PK-not-really-a-zip-but-it-hashes-fine';

    /** @var list<string> Every URL the downloader was asked for, in order. */
    private array $requested = [];

    /** @var list<string> Temporary files the fixtures created, cleaned up whatever happens. */
    private array $temporary = [];

    /** @var array<string, mixed> */
    private array $product = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_hub_test_filters'] = [];
        $this->requested = [];
        $this->temporary = [];
        $this->product = CatalogueFixtures::normalised()['products']['fchub-memberships'];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $GLOBALS['_fchub_hub_test_filters'] = [];

        parent::tearDown();
    }

    public function testRefusesAPackageHostThatIsNotOnTheAllowListBeforeAnyRequest(): void
    {
        $this->product['package_url'] = 'https://evil.example.com/fchub-memberships-1.4.0.zip';

        $error = $this->failedDownload();

        self::assertSame('package_host_not_allowed', $error->code());
        self::assertSame([], $this->requested, 'An untrusted host must be refused before a single byte moves.');
    }

    public function testRefusesAChecksumHostThatIsNotOnTheAllowListBeforeAnyRequest(): void
    {
        // The package host is fine; the sidecar is being served from somewhere
        // else entirely, which is exactly the sort of split nobody should get
        // to pull off.
        $this->product['checksum_url'] = 'https://evil.example.com/fchub-memberships-1.4.0.zip.sha256';

        $error = $this->failedDownload();

        self::assertSame('package_host_not_allowed', $error->code());
        self::assertSame([], $this->requested);
    }

    public function testRefusesAnInsecurePackageUrlBeforeAnyRequest(): void
    {
        $this->product['package_url'] = 'http://github.com/vcode-sh/fchub-plugins/releases/download/x/y.zip';

        $error = $this->failedDownload();

        self::assertSame('package_host_not_allowed', $error->code());
        self::assertSame([], $this->requested);
    }

    public function testAllowsPlainHttpOnlyWhenTheHarnessFilterSaysSo(): void
    {
        // The Task 7 lifecycle harness serves its fixtures over HTTP from a
        // container. Production ships no such filter.
        add_filter('fchub/catalogue/allow_http', static fn (): bool => true);

        $this->product['package_url'] = 'http://github.com/vcode-sh/fchub-plugins/releases/download/x/y.zip';

        $package = $this->download($this->responses(self::PACKAGE_BODY, hash('sha256', self::PACKAGE_BODY)));

        self::assertFileExists($package);
    }

    public function testReturnsTheTemporaryPathWhenTheChecksumMatches(): void
    {
        $package = $this->download($this->responses(self::PACKAGE_BODY, hash('sha256', self::PACKAGE_BODY)));

        self::assertFileExists($package);
        self::assertSame(self::PACKAGE_BODY, (string) file_get_contents($package));
        self::assertSame(
            [$this->product['package_url'], $this->product['checksum_url']],
            $this->requested,
            'The package is fetched first, then its checksum.'
        );
    }

    public function testAcceptsTheTwoFieldSha256sumLayout(): void
    {
        $body = hash('sha256', self::PACKAGE_BODY) . "  *fchub-memberships-1.4.0.zip\n";

        $package = $this->download($this->responses(self::PACKAGE_BODY, $body));

        self::assertFileExists($package);
    }

    public function testAcceptsAnUppercaseChecksum(): void
    {
        $body = strtoupper(hash('sha256', self::PACKAGE_BODY));

        $package = $this->download($this->responses(self::PACKAGE_BODY, $body));

        self::assertFileExists($package);
    }

    public function testAMismatchedChecksumDeletesTheDownloadAndStops(): void
    {
        $downloaded = null;
        $responses = $this->responses(self::PACKAGE_BODY, str_repeat('a', 64), $downloaded);

        $error = $this->failedDownload($responses);

        self::assertSame('package_verification_failed', $error->code());
        self::assertIsString($downloaded);
        self::assertFileDoesNotExist($downloaded, 'A package that failed its check must not be left lying around.');
    }

    public function testAnUnreadableChecksumDeletesTheDownloadAndStops(): void
    {
        $downloaded = null;
        $responses = $this->responses(self::PACKAGE_BODY, "<!doctype html><title>404</title>", $downloaded);

        $error = $this->failedDownload($responses);

        self::assertSame('checksum_invalid', $error->code());
        self::assertSame('The release checksum could not be read.', $error->publicMessage());
        self::assertIsString($downloaded);
        self::assertFileDoesNotExist($downloaded);
    }

    public function testAMissingChecksumAllowsTheTrustedPackageAndRecordsItInternally(): void
    {
        // Releases published before the sidecar existed. The package still came
        // from a trusted HTTPS release host, so it goes through with a note.
        $downloader = new VerifiedPackageDownloader($this->injected([
            $this->product['package_url'] => fn (): string => $this->temporaryFile(self::PACKAGE_BODY),
            $this->product['checksum_url'] => static fn (): WP_Error => new WP_Error('http_404', 'Not Found'),
        ]));

        $package = $downloader->download($this->product);

        self::assertFileExists($package);
        self::assertSame('checksum_unavailable', $downloader->lastNote());
    }

    public function testASuccessfulVerificationLeavesNoNoteBehind(): void
    {
        $downloader = new VerifiedPackageDownloader(
            $this->injected($this->responses(self::PACKAGE_BODY, hash('sha256', self::PACKAGE_BODY)))
        );

        $downloader->download($this->product);

        self::assertNull($downloader->lastNote());
    }

    public function testAChecksumServerErrorStopsTheOperation(): void
    {
        // Only a 404 means "this release predates the sidecar". Anything else
        // means we simply do not know, and guessing is not verification.
        $downloaded = null;
        $responses = [
            $this->product['package_url'] => function () use (&$downloaded): string {
                return $downloaded = $this->temporaryFile(self::PACKAGE_BODY);
            },
            $this->product['checksum_url'] => static fn (): WP_Error => new WP_Error('http_503', 'Service Unavailable'),
        ];

        $error = $this->failedDownload($responses);

        self::assertSame('package_unavailable', $error->code());
        self::assertIsString($downloaded);
        self::assertFileDoesNotExist($downloaded);
    }

    public function testATransportFailureOnThePackageStopsTheOperation(): void
    {
        $error = $this->failedDownload([
            $this->product['package_url'] => static fn (): WP_Error => new WP_Error('http_request_failed', 'cURL error 28'),
        ]);

        self::assertSame('package_unavailable', $error->code());
        self::assertSame([$this->product['package_url']], $this->requested, 'A failed package fetch never asks for a checksum.');
    }

    public function testTheChecksumTemporaryFileIsAlwaysCleanedUp(): void
    {
        $checksumFile = null;
        $responses = [
            $this->product['package_url'] => fn (): string => $this->temporaryFile(self::PACKAGE_BODY),
            $this->product['checksum_url'] => function () use (&$checksumFile): string {
                return $checksumFile = $this->temporaryFile(hash('sha256', self::PACKAGE_BODY));
            },
        ];

        $this->download($responses);

        self::assertIsString($checksumFile);
        self::assertFileDoesNotExist($checksumFile, 'The checksum file is ours to delete, success or not.');
    }

    public function testTheChecksumTemporaryFileIsCleanedUpWhenItWillNotEvenParse(): void
    {
        $checksumFile = null;
        $responses = [
            $this->product['package_url'] => fn (): string => $this->temporaryFile(self::PACKAGE_BODY),
            $this->product['checksum_url'] => function () use (&$checksumFile): string {
                return $checksumFile = $this->temporaryFile('an apology from a CDN');
            },
        ];

        $this->failedDownload($responses);

        self::assertIsString($checksumFile);
        self::assertFileDoesNotExist($checksumFile, 'A failed parse leaves nothing behind either.');
    }

    public function testAnyFailureAtAllTakesThePackageWithIt(): void
    {
        // Not every failure arrives as an OperationError. A site whose error
        // handler promotes warnings to ErrorException — several monitoring
        // plugins do — would throw straight out of the checksum step, and the
        // archive must not survive that either.
        $downloaded = null;
        $responses = [
            $this->product['package_url'] => function () use (&$downloaded): string {
                return $downloaded = $this->temporaryFile(self::PACKAGE_BODY);
            },
            $this->product['checksum_url'] => static function (): string {
                throw new RuntimeException('the error handler had opinions');
            },
        ];

        try {
            $this->download($responses);
            self::fail('The exception was expected to propagate.');
        } catch (RuntimeException $error) {
            self::assertNotInstanceOf(OperationError::class, $error, 'This must not be dressed up as an operation error.');
            self::assertSame('the error handler had opinions', $error->getMessage());
        }

        self::assertIsString($downloaded);
        self::assertFileDoesNotExist($downloaded);
    }

    public function testARefusedUrlDoesNotInheritThePreviousDownloadsNote(): void
    {
        $downloader = new VerifiedPackageDownloader($this->injected([
            $this->product['package_url'] => fn (): string => $this->temporaryFile(self::PACKAGE_BODY),
            $this->product['checksum_url'] => static fn (): WP_Error => new WP_Error('http_404', 'Not Found'),
        ]));

        $downloader->download($this->product);
        self::assertSame('checksum_unavailable', $downloader->lastNote());

        $untrusted = $this->product;
        $untrusted['package_url'] = 'https://evil.example.com/anything.zip';

        try {
            $downloader->download($untrusted);
            self::fail('The untrusted host was expected to be refused.');
        } catch (OperationError $error) {
            self::assertSame('package_host_not_allowed', $error->code());
        }

        self::assertNull($downloader->lastNote(), 'A refusal reports nothing about the download before it.');
    }

    public function testPublicMessagesNeverCarryPathsOrRemoteBodies(): void
    {
        $downloaded = null;
        $responses = $this->responses(self::PACKAGE_BODY, 'total nonsense from a hijacked mirror', $downloaded);

        $error = $this->failedDownload($responses);

        self::assertStringNotContainsString(sys_get_temp_dir(), $error->publicMessage());
        self::assertStringNotContainsString('hijacked', $error->publicMessage());
        self::assertStringNotContainsString('/', $error->publicMessage());
    }

    /**
     * @param array<string, Closure> $responses
     */
    private function download(array $responses): string
    {
        return (new VerifiedPackageDownloader($this->injected($responses)))->download($this->product);
    }

    /**
     * @param array<string, Closure> $responses
     */
    private function failedDownload(array $responses = []): OperationError
    {
        try {
            $this->download($responses);
        } catch (OperationError $error) {
            return $error;
        }

        self::fail('The download was expected to be refused.');
    }

    /**
     * A package fixture and its sidecar, with the downloaded package path
     * handed back through $downloaded so cleanup can be asserted.
     *
     * @return array<string, Closure>
     */
    private function responses(string $packageBody, string $checksumBody, ?string &$downloaded = null): array
    {
        return [
            $this->product['package_url'] => function () use ($packageBody, &$downloaded): string {
                return $downloaded = $this->temporaryFile($packageBody);
            },
            $this->product['checksum_url'] => fn (): string => $this->temporaryFile($checksumBody),
        ];
    }

    /**
     * @param array<string, Closure> $responses
     */
    private function injected(array $responses): Closure
    {
        return function (string $url) use ($responses) {
            $this->requested[] = $url;

            if (!isset($responses[$url])) {
                self::fail('Nothing queued for ' . $url);
            }

            return ($responses[$url])();
        };
    }

    private function temporaryFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'fchub-test-');
        file_put_contents($path, $contents);

        $this->temporary[] = $path;

        return $path;
    }
}
