<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\SameSite\PrivateWorkspace;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The directory CartShift writes a package into, chosen rather than typed.
 *
 * `wp cartshift transfer export` refuses a `--destination` that is not an
 * existing absolute non-symlink directory outside the web root, and it is right
 * to: a package holds every customer, order and address in the shop, and one
 * served over HTTP is a data breach with a URL. The guided route removes the
 * typing, not the rule — so the rule is what this file is about.
 */
final class PrivateWorkspaceTest extends PluginTestCase
{
    private string $scratch;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = realpath(sys_get_temp_dir()) . '/cartshift-workspace-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    public function testTheWorkspaceIsCreatedUnderTheFirstUsableCandidate(): void
    {
        $path = (new PrivateWorkspace('site-abc123', [$this->scratch]))->path();

        $this->assertDirectoryExists($path);
        $this->assertStringStartsWith($this->scratch . '/', $path);
        $this->assertStringContainsString('site-abc123', $path);
    }

    public function testTheWorkspaceIsPrivateOnDisk(): void
    {
        $path = (new PrivateWorkspace('site-abc123', [$this->scratch]))->path();

        $this->assertSame('0700', substr(sprintf('%o', fileperms($path)), -4));

        // Belt and braces for the host that ignores the mode: a directory index
        // and a deny rule, so a misconfigured docroot cannot list the package.
        $this->assertFileExists($path . '/index.php');
        $this->assertFileExists($path . '/.htaccess');
    }

    public function testResolvingTwiceReturnsTheSameDirectoryRatherThanASecondOne(): void
    {
        $workspace = new PrivateWorkspace('site-abc123', [$this->scratch]);

        $this->assertSame($workspace->path(), $workspace->path());
        $this->assertSame($workspace->path(), (new PrivateWorkspace('site-abc123', [$this->scratch]))->path());
    }

    public function testTwoSitesSharingOneCandidateDoNotShareAWorkspace(): void
    {
        $this->assertNotSame(
            (new PrivateWorkspace('site-abc123', [$this->scratch]))->path(),
            (new PrivateWorkspace('site-def456', [$this->scratch]))->path(),
        );
    }

    // ──────────────────────────────────────────────
    // The rule the CLI already enforces
    // ──────────────────────────────────────────────

    public function testACandidateInsideTheWebRootIsSkipped(): void
    {
        $insideWebRoot = rtrim(ABSPATH, '/') . '/wp-content/uploads';

        $path = (new PrivateWorkspace('site-abc123', [$insideWebRoot, $this->scratch]))->path();

        $this->assertStringStartsWith($this->scratch . '/', $path);
    }

    public function testTheWebRootItselfIsNotOutsideTheWebRoot(): void
    {
        $this->assertFalse(PrivateWorkspace::isOutsideWebRoot(rtrim(ABSPATH, '/')));
        $this->assertFalse(PrivateWorkspace::isOutsideWebRoot(rtrim(ABSPATH, '/') . '/wp-content'));

        // A sibling whose name merely starts with the web root's is not inside
        // it. `/tmp/wordpress-elsewhere` against a web root of `/tmp/wordpress`
        // is the prefix-match bug this asserts against.
        $this->assertTrue(PrivateWorkspace::isOutsideWebRoot(rtrim(ABSPATH, '/') . '-elsewhere'));
    }

    /**
     * The same three answers, with the web root actually on disk.
     *
     * This is not a duplicate of the test above: it is the branch where
     * `realpath()` succeeds. Symlinked docroots are ordinary — macOS puts
     * `/tmp` behind one and every atomic-deploy layout points `html` at
     * `releases/N` — so a check that resolved only one side answered "outside"
     * for the web root itself. It reached this suite as an order-dependent
     * failure: green alone, red once another test had created the directory.
     */
    public function testTheWebRootIsRecognisedWhenItResolvesThroughASymlink(): void
    {
        $webRoot = rtrim(ABSPATH, '/');

        mkdir($webRoot . '/wp-content/uploads', 0755, true);

        try {
            $this->assertNotSame(
                $webRoot,
                realpath($webRoot),
                'This host does not symlink the web root, so the branch under test is not being reached.',
            );

            $this->assertFalse(PrivateWorkspace::isOutsideWebRoot($webRoot));
            $this->assertFalse(PrivateWorkspace::isOutsideWebRoot($webRoot . '/wp-content/uploads'));
            $this->assertTrue(PrivateWorkspace::isOutsideWebRoot($this->scratch));
        } finally {
            $this->removeTree($webRoot);
        }
    }

    public function testASymlinkedCandidateIsSkipped(): void
    {
        $target = $this->scratch . '/real';
        $link = $this->scratch . '/link';

        mkdir($target, 0700);
        symlink($target, $link);

        $fallback = $this->scratch . '/fallback';
        mkdir($fallback, 0700);

        $path = (new PrivateWorkspace('site-abc123', [$link, $fallback]))->path();

        $this->assertStringStartsWith($fallback . '/', $path);
    }

    /**
     * REFUSE, DO NOT IMPROVISE. With nowhere private to write, the guided route
     * stops. Falling back into the uploads directory would put every customer
     * and order behind a URL, which is precisely the failure the rule exists to
     * prevent — and it would happen silently, on the hosts least able to notice.
     */
    public function testNoUsableCandidateRefusesRatherThanFallingIntoTheWebRoot(): void
    {
        // The candidate is made real first. A refusal that fired merely because
        // the directory did not exist would prove nothing about the web-root
        // rule this test is named after — it is a writable, existing, perfectly
        // ordinary uploads directory, and it is refused for where it is.
        $uploads = rtrim(ABSPATH, '/') . '/wp-content/uploads';

        mkdir($uploads, 0755, true);

        $this->assertDirectoryIsWritable($uploads);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('private_workspace_unavailable');

            (new PrivateWorkspace('site-abc123', [$uploads]))->path();
        } finally {
            $this->removeTree(rtrim(ABSPATH, '/'));
        }
    }

    /**
     * ONE NAME FOR ONE DIRECTORY.
     *
     * `LoadedTargetTransferPipeline` resolves its working directory through
     * `ConfiguredTransferEvidence::privateDirectory()` — a PHP constant or an
     * environment variable, deliberately nothing a web request can set. This
     * class briefly invented a second constant of its own, which is a guided run
     * exporting to one directory while `stage` reads another. So the configured
     * one is used verbatim rather than nested under.
     */
    public function testTheConfiguredTransferDirectoryIsUsedVerbatimRatherThanNestedUnder(): void
    {
        if (PrivateWorkspace::isTransferDirectoryConfigured()) {
            self::markTestSkipped('This runtime configures the transfer directory; the fallback is unreachable.');
        }

        self::assertSame(
            'CARTSHIFT_TRANSFER_PRIVATE_DIR',
            \CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence::PRIVATE_DIRECTORY,
            'The workspace and the pipeline must agree on the constant, by reading the same one.',
        );

        // Unconfigured, so the fallback answers and it is a nested subdirectory.
        $path = (new PrivateWorkspace('site-abc123', [$this->scratch]))->path();

        self::assertStringEndsWith('/cartshift-private/site-abc123', $path);
    }

    public function testASourceKeyTheTransferGuardRejectsIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PrivateWorkspace('local', [$this->scratch]);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            if (is_link($child)) {
                @unlink($child);
                continue;
            }

            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
