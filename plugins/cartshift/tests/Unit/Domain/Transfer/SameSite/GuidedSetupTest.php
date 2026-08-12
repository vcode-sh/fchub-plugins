<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;
use CartShift\Domain\Transfer\SameSite\GuidedSetup;
use CartShift\Domain\Transfer\SameSite\PrivateWorkspace;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The one-time `wp-config.php` edit, and the reason it cannot be avoided.
 *
 * `LoadedTargetTransferPipeline` resolves its working directory and its lease
 * holder through `ConfiguredTransferEvidence`, which reads a PHP constant or an
 * environment variable and nothing else. That is deliberate: evidence a web
 * request can set is not evidence. A guided run therefore cannot configure its
 * own way past `prepare` — it can only say, precisely, what is missing.
 *
 * The suggestions are round-tripped through the real validators rather than
 * pattern-matched, because a suggestion the pipeline would reject is worse than
 * no suggestion: it looks like help and costs an afternoon.
 */
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

    public function testAnUnconfiguredRuntimeIsNotReadyAndSaysWhichTwoThingsAreMissing(): void
    {
        $setup = $this->guidedSetup();

        self::assertFalse($setup->isComplete());
        self::assertSame(
            [ConfiguredTransferEvidence::PRIVATE_DIRECTORY, ConfiguredTransferEvidence::OPERATOR_ID],
            array_column($setup->missing(), 'constant'),
        );
    }

    /**
     * A suggestion the pipeline would refuse is not help.
     *
     * `PrivateTransferFile::directory()` demands an existing, absolute,
     * non-symlink directory with no group or other permissions, outside the web
     * root. Rather than restate those five rules here and let the copies drift,
     * the suggested value is fed to the real validator through the environment
     * variable it actually reads.
     */
    public function testTheSuggestedDirectoryIsOneTheRealValidatorAccepts(): void
    {
        $suggested = $this->guidedSetup()->suggestionFor(ConfiguredTransferEvidence::PRIVATE_DIRECTORY);

        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY . '=' . $suggested);

        self::assertSame($suggested, ConfiguredTransferEvidence::privateDirectory());
        self::assertTrue(PrivateWorkspace::isOutsideWebRoot($suggested));
    }

    public function testTheSuggestedOperatorIsOneTheRealValidatorAccepts(): void
    {
        $suggested = $this->guidedSetup()->suggestionFor(ConfiguredTransferEvidence::OPERATOR_ID);

        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=' . $suggested);

        self::assertSame(self::OPERATOR, ConfiguredTransferEvidence::operatorId());
    }

    public function testTheSnippetIsPasteableAndCarriesBothDefines(): void
    {
        $snippet = $this->guidedSetup()->wpConfigSnippet();

        foreach ([ConfiguredTransferEvidence::PRIVATE_DIRECTORY, ConfiguredTransferEvidence::OPERATOR_ID] as $constant) {
            self::assertStringContainsString("define('" . $constant . "',", $snippet);
        }

        self::assertStringNotContainsString('<', $snippet, 'A placeholder reached a snippet meant to be pasted.');
    }

    public function testAConfiguredRuntimeIsReadyAndAsksForNothing(): void
    {
        $setup = $this->guidedSetup();

        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY . '=' . $setup->suggestionFor(ConfiguredTransferEvidence::PRIVATE_DIRECTORY));
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=' . self::OPERATOR);

        $ready = $this->guidedSetup();

        self::assertTrue($ready->isComplete());
        self::assertSame([], $ready->missing());
        self::assertSame('', $ready->wpConfigSnippet());
    }

    public function testHalfConfiguredIsNotConfigured(): void
    {
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=' . self::OPERATOR);

        $setup = $this->guidedSetup();

        self::assertFalse($setup->isComplete());
        self::assertSame(
            [ConfiguredTransferEvidence::PRIVATE_DIRECTORY],
            array_column($setup->missing(), 'constant'),
        );
    }

    /**
     * THE PART SETUP CANNOT UNLOCK, STATED RATHER THAN DISCOVERED.
     *
     * `assertCutoverApproval()` needs a sha256 constant matching a manifest file
     * on disk whose operator matches — per run, not once. A browser cannot
     * define a PHP constant, which is the property that makes the approval mean
     * anything. So the screen says so up front instead of letting somebody
     * complete a rehearsal and then find the last button missing.
     */
    public function testCutoverIsReportedUnavailableFromTheBrowserEvenWhenSetupIsComplete(): void
    {
        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY . '=' . $this->guidedSetup()->suggestionFor(ConfiguredTransferEvidence::PRIVATE_DIRECTORY));
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=' . self::OPERATOR);

        $cutover = $this->guidedSetup()->cutover();

        self::assertTrue($this->guidedSetup()->isComplete());
        self::assertFalse($cutover['available']);
        self::assertSame('cutover_approval_is_per_run_evidence', $cutover['reason']);
        self::assertStringContainsString('rehearsal', strtolower($cutover['message']));
    }

    private function guidedSetup(): GuidedSetup
    {
        return new GuidedSetup(self::SOURCE_KEY, self::OPERATOR);
    }
}
