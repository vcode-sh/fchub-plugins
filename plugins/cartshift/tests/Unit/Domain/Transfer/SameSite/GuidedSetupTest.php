<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;
use CartShift\Domain\Transfer\SameSite\GuidedSetup;
use CartShift\Domain\Transfer\SameSite\GuidedSetupConfiguration;
use CartShift\Domain\Transfer\SameSite\GuidedSetupLock;
use CartShift\Domain\Transfer\SameSite\PrivateWorkspace;
use CartShift\Tests\Unit\PluginTestCase;

/** The guided route owns its private workspace setup; members do not edit PHP. */
final class GuidedSetupTest extends PluginTestCase
{
    private const string SOURCE_KEY = 'site-0123456789abcdef';
    private const string OPERATOR = 'wp-user:1';

    #[\Override]
    protected function tearDown(): void
    {
        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY);
        putenv(ConfiguredTransferEvidence::OPERATOR_ID);

        parent::tearDown();
    }

    public function testAnUnconfiguredRuntimeBecomesReadyThroughOneAutomaticSetup(): void
    {
        $setup = $this->guidedSetup();

        self::assertFalse($setup->isComplete());
        $setup->ensure();

        self::assertTrue($setup->isComplete());
        self::assertSame(self::OPERATOR, ConfiguredTransferEvidence::operatorId());
        self::assertTrue(PrivateWorkspace::isOutsideWebRoot(ConfiguredTransferEvidence::privateDirectory()));
        self::assertDirectoryExists(ConfiguredTransferEvidence::privateDirectory());
    }

    public function testAutomaticSetupIsIdempotent(): void
    {
        $setup = $this->guidedSetup();
        $setup->ensure();
        $first = get_option(GuidedSetupConfiguration::OPTION);

        $setup->ensure();

        self::assertSame($first, get_option(GuidedSetupConfiguration::OPTION));
    }

    public function testSavedSetupBootsTheNextRequestThroughTheExistingEvidenceAdapter(): void
    {
        $this->guidedSetup()->ensure();
        $directory = ConfiguredTransferEvidence::privateDirectory();

        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY);
        putenv(ConfiguredTransferEvidence::OPERATOR_ID);
        (new GuidedSetupConfiguration())->boot();

        self::assertSame($directory, ConfiguredTransferEvidence::privateDirectory());
        self::assertSame(self::OPERATOR, ConfiguredTransferEvidence::operatorId());
    }

    public function testAnEmptyEnvironmentValueDoesNotDisableSavedGuidedSetup(): void
    {
        $this->guidedSetup()->ensure();
        $directory = ConfiguredTransferEvidence::privateDirectory();
        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY . '=');
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=');

        (new GuidedSetupConfiguration())->boot();

        self::assertSame($directory, ConfiguredTransferEvidence::privateDirectory());
        self::assertSame(self::OPERATOR, ConfiguredTransferEvidence::operatorId());
    }

    public function testConcurrentFirstSetupUsesTheOneStoredWinner(): void
    {
        $winnerDirectory = (new PrivateWorkspace(self::SOURCE_KEY))->path();
        $winner = ['private_directory' => $winnerDirectory, 'operator_id' => 'wp-user:2'];
        $GLOBALS['_cartshift_test_add_option_callback'] = static function (string $option) use ($winner): bool {
            $GLOBALS['_cartshift_test_options'][$option] = $winner;

            return false;
        };

        try {
            $this->guidedSetup()->ensure();
        } finally {
            unset($GLOBALS['_cartshift_test_add_option_callback']);
        }

        self::assertSame('wp-user:2', ConfiguredTransferEvidence::operatorId());
        self::assertSame($winnerDirectory, ConfiguredTransferEvidence::privateDirectory());
    }

    public function testExplicitServerConfigurationStillWins(): void
    {
        $directory = (new PrivateWorkspace(self::SOURCE_KEY))->path();
        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY . '=' . $directory);
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=server-owner');

        $this->guidedSetup()->ensure();

        self::assertSame($directory, ConfiguredTransferEvidence::privateDirectory());
        self::assertSame('server-owner', ConfiguredTransferEvidence::operatorId());
        self::assertArrayNotHasKey(GuidedSetupConfiguration::OPTION, $GLOBALS['_cartshift_test_options']);
    }

    public function testMalformedSavedSetupFailsClosed(): void
    {
        $GLOBALS['_cartshift_test_options'][GuidedSetupConfiguration::OPTION] = [
            'private_directory' => '/inside/the/site',
            'operator_id' => 'operator with spaces',
        ];

        self::assertFalse($this->guidedSetup()->isComplete());

        $this->expectException(\RuntimeException::class);
        $this->guidedSetup()->ensure();
    }

    public function testAutomaticSetupRepairsAStoredDirectoryThatNoLongerExists(): void
    {
        $GLOBALS['_cartshift_test_options'][GuidedSetupConfiguration::OPTION] = [
            'private_directory' => '/a/removed/hosting/path',
            'operator_id' => self::OPERATOR,
        ];

        $this->guidedSetup()->ensure();

        $stored = get_option(GuidedSetupConfiguration::OPTION);
        self::assertNotSame('/a/removed/hosting/path', $stored['private_directory']);
        self::assertDirectoryExists($stored['private_directory']);
        self::assertTrue($this->guidedSetup()->isComplete());
    }

    public function testASetupLockIsReleasedWhenItsRequestEnds(): void
    {
        $first = GuidedSetupLock::acquire('interrupted-request-test');

        try {
            GuidedSetupLock::acquire('interrupted-request-test');
            self::fail('A concurrent request acquired the same setup lock.');
        } catch (\RuntimeException $busy) {
            self::assertStringContainsString('busy', $busy->getMessage());
        }

        unset($first);
        $recovered = GuidedSetupLock::acquire('interrupted-request-test');

        self::assertInstanceOf(GuidedSetupLock::class, $recovered);
    }

    public function testASetupLockRefusesASymlinkInTheSharedTemporaryArea(): void
    {
        $name = 'symlink-test-' . bin2hex(random_bytes(6));
        $first = GuidedSetupLock::acquire($name);
        unset($first);

        $directory = realpath(sys_get_temp_dir()) . '/cartshift-locks-' . hash('sha256', ABSPATH);
        $lockPath = $directory . '/' . hash('sha256', $name) . '.lock';
        $target = tempnam(sys_get_temp_dir(), 'cartshift-lock-target-');
        self::assertIsString($target);
        unlink($lockPath);
        symlink($target, $lockPath);
        $modeBefore = fileperms($target) & 0777;

        try {
            GuidedSetupLock::acquire($name);
            self::fail('A symlink was accepted as a CartShift setup lock.');
        } catch (\RuntimeException $unsafe) {
            self::assertStringContainsString('unsafe', $unsafe->getMessage());
        } finally {
            clearstatcache(true, $target);
            $modeAfter = fileperms($target) & 0777;
            unlink($lockPath);
            unlink($target);
        }

        self::assertSame($modeBefore, $modeAfter);
    }

    public function testASetupLockNeverChmodsAPreExistingDirectorySymlink(): void
    {
        $name = 'directory-symlink-test-' . bin2hex(random_bytes(6));
        $first = GuidedSetupLock::acquire($name);
        unset($first);

        $directory = realpath(sys_get_temp_dir()) . '/cartshift-locks-' . hash('sha256', ABSPATH);
        $original = $directory . '-test-original';
        $target = $directory . '-test-target';
        rename($directory, $original);
        mkdir($target, 0755);
        symlink($target, $directory);
        $modeBefore = fileperms($target) & 0777;

        try {
            GuidedSetupLock::acquire($name);
            self::fail('A directory symlink was accepted for CartShift setup locks.');
        } catch (\RuntimeException $unsafe) {
            self::assertStringContainsString('unsafe', $unsafe->getMessage());
        } finally {
            clearstatcache(true, $target);
            $modeAfter = fileperms($target) & 0777;
            unlink($directory);
            rename($original, $directory);
            rmdir($target);
        }

        self::assertSame($modeBefore, $modeAfter);
    }

    public function testTheGuidedMoveOwnsActivationAfterTheOwnerReview(): void
    {
        $this->guidedSetup()->ensure();

        $cutover = $this->guidedSetup()->cutover();

        self::assertTrue($this->guidedSetup()->isComplete());
        self::assertTrue($cutover['available']);
        self::assertSame('guided_same_site_move', $cutover['reason']);
        self::assertStringContainsString('verify', strtolower($cutover['message']));
        self::assertStringContainsString('activate', strtolower($cutover['message']));
        self::assertStringNotContainsString('rehearsal', strtolower($cutover['message']));
    }

    private function guidedSetup(): GuidedSetup
    {
        return new GuidedSetup(self::SOURCE_KEY, self::OPERATOR);
    }
}
