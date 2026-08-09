<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\ClosureReport;
use CartShift\Domain\Subscription\Package\PackagePath;
use CartShift\Domain\Subscription\Package\SubscriptionPackageReader;
use CartShift\Tests\Unit\PluginTestCase;
use ReflectionClassConstant;

/**
 * Every code the package layer can emit must be one section 9.4 ratified.
 *
 * This file exists because the discipline failed once, quietly, in exactly the
 * way a reviewer cannot be expected to catch by reading. The `package_path_*`
 * family was argued — by me, and accepted — to sit outside section 9.4 because
 * it "fires before anything is read and blocks a command, not a cutover". That
 * was simply false: `SubscriptionPackageReader::validate()` maps every
 * `PackagePath::resolveForRead()` refusal straight into `failures[].code`, and
 * `ok` is what the audit turns into `outcome => blocked`. Nine codes were
 * steering cutover decisions from outside the table, and a tenth was minted on
 * top of them before anyone noticed.
 *
 * Prose could not have prevented that; the exemption was written down and
 * believed. So the registry is enforced instead.
 *
 * THE MECHANISM. `RATIFIED_CODES` holds string literals. The emittable set is
 * derived here by reflection over the constants that actually reach a failure.
 * Neither side can be updated by touching the other, so minting a code without
 * ratifying it turns this red, and ratifying a code nothing emits turns it red
 * too — a registry that accumulates dead entries stops being a description of
 * anything.
 */
final class PackageFailureCodeRegistryTest extends PluginTestCase
{
    /**
     * Constants whose values can land in a `failures[].code`.
     *
     * `SubscriptionPackageReader::REASON_*` are passed to `failure()` directly.
     * `PackagePath::REASON_*` arrive through `validate()`'s path-refusal map,
     * which turns each refusal string into a code without inspecting it — so
     * every constant that class can produce is emittable by construction, and
     * that is why the whole family is reflected rather than a chosen subset.
     *
     * @return array<string, string>
     */
    private function emittableCodes(): array
    {
        $codes = [];

        foreach ([SubscriptionPackageReader::class, PackagePath::class] as $class) {
            foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
                if (!$constant->isPublic() || !str_starts_with($constant->getName(), 'REASON_')) {
                    continue;
                }

                $codes[$class . '::' . $constant->getName()] = (string) $constant->getValue();
            }
        }

        return $codes;
    }

    public function testEveryEmittableCodeIsRatified(): void
    {
        $unratified = [];

        foreach ($this->emittableCodes() as $constant => $code) {
            if (!in_array($code, SubscriptionPackageReader::RATIFIED_CODES, true)) {
                $unratified[$constant] = $code;
            }
        }

        $this->assertSame(
            [],
            $unratified,
            'A code that controls a cutover must be in section 9.4. Ratify it, then add it to '
            . 'SubscriptionPackageReader::RATIFIED_CODES.',
        );
    }

    public function testTheRegistryHoldsNothingNoCodeCanEmit(): void
    {
        $emittable = array_values($this->emittableCodes());

        $orphans = array_values(array_filter(
            SubscriptionPackageReader::RATIFIED_CODES,
            static fn (string $code): bool => !in_array($code, $emittable, true),
        ));

        $this->assertSame(
            [],
            $orphans,
            'A registry that accumulates codes nothing emits stops describing anything.',
        );
    }

    public function testTheRegistryIsSortedAndUnique(): void
    {
        $sorted = SubscriptionPackageReader::RATIFIED_CODES;
        sort($sorted);

        $this->assertSame($sorted, SubscriptionPackageReader::RATIFIED_CODES);
        $this->assertSame(
            array_values(array_unique(SubscriptionPackageReader::RATIFIED_CODES)),
            SubscriptionPackageReader::RATIFIED_CODES,
        );
    }

    /**
     * The reflection above is only complete if no failure is built from a raw
     * string.
     *
     * A hard-coded `self::failure('package_something_new', …)` would emit a code
     * with no constant behind it, and every assertion in this file would sail
     * past it. So the source is read: the first argument of every
     * `failure(...)` call must be a constant reference or the `$code` variable
     * the path-refusal map passes through.
     */
    public function testNoFailureIsBuiltFromARawString(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(SubscriptionPackageReader::class))->getFileName(),
        );

        // `failure()` is private static, so `self::failure(` is the only way to
        // reach it. Asserted rather than assumed: a scan that quietly matched
        // nothing would pass this test for ever.
        $this->assertStringNotContainsString('->failure(', $source);

        preg_match_all('/self::failure\(\s*([^,\n]+),/', $source, $matches);

        $arguments = array_map(trim(...), $matches[1]);

        $this->assertGreaterThanOrEqual(
            8,
            count($arguments),
            'The scan found almost no failure() calls, so it is not scanning what it thinks it is.',
        );

        foreach ($arguments as $argument) {
            $this->assertMatchesRegularExpression(
                '/^(self::REASON_[A-Z_]+|\$code)$/',
                $argument,
                sprintf(
                    'failure() was handed %s. Give the code a constant, or the registry cannot see it.',
                    $argument,
                ),
            );
        }
    }

    /**
     * The two codes shared with section 9.4's dataset row are aliases, not
     * copies — one literal per code, or the two halves drift and a retry stops
     * matching its own blocker.
     */
    public function testTheSharedDatasetCodesAreAliasedRatherThanRestated(): void
    {
        foreach (
            [
                'REASON_CHECKSUM_MISMATCH' => ClosureReport::CODE_CHECKSUM_MISMATCH,
                'REASON_COUNT_MISMATCH'    => ClosureReport::CODE_COUNT_MISMATCH,
            ] as $name => $expected
        ) {
            $constant = new ReflectionClassConstant(SubscriptionPackageReader::class, $name);

            $this->assertSame($expected, $constant->getValue());
            $this->assertStringContainsString(
                'ClosureReport::',
                (string) $constant->getDocComment() . $this->constantDeclaration($name),
                sprintf('%s must alias ClosureReport rather than restate its string.', $name),
            );
        }
    }

    /**
     * Real refusals, driven end to end: whatever `validate()` actually puts in a
     * code must be ratified.
     *
     * Completeness comes from the reflection and source-scan tests above; this
     * one is the sanity check that they are describing the thing that really
     * runs.
     */
    public function testEveryRefusalDrivenEndToEndReportsARatifiedCode(): void
    {
        $workspace = realpath(sys_get_temp_dir()) . '/cartshift-registry-' . bin2hex(random_bytes(6));
        mkdir($workspace, 0700, true);

        $uploads = $workspace . '/wp-content/uploads';
        mkdir($uploads, 0700, true);
        file_put_contents($uploads . '/public.ndjson', "{}\n");

        $repo = $workspace . '/repo';
        mkdir($repo . '/.git', 0700, true);
        file_put_contents($repo . '/tracked.ndjson', "{}\n");

        $link = $workspace . '/link.ndjson';
        symlink($uploads . '/public.ndjson', $link);

        $empty = $workspace . '/empty.ndjson';
        file_put_contents($empty, '');

        $garbled = $workspace . '/garbled.ndjson';
        file_put_contents($garbled, "not json\n");

        $reader = new SubscriptionPackageReader();

        $paths = [
            '',                                  // missing
            'relative/path.ndjson',              // not absolute
            $workspace . '/nowhere/x.ndjson',    // directory unknown
            $uploads . '/public.ndjson',         // public directory
            $repo . '/tracked.ndjson',           // version control
            $link,                               // symlink
            $workspace,                          // not a file
            $empty,                              // empty
            $garbled,                            // header unreadable
        ];

        $seen = [];

        foreach ($paths as $path) {
            $result = $reader->validate($path);

            $this->assertFalse($result['ok'], sprintf('%s should not validate.', $path));

            foreach (array_column($result['failures'], 'code') as $code) {
                $seen[$code] = true;

                $this->assertContains(
                    $code,
                    SubscriptionPackageReader::RATIFIED_CODES,
                    sprintf('%s emitted an unratified code.', $path),
                );
            }
        }

        // Named individually so a refusal that silently stops firing is visible
        // rather than being absorbed into a passing loop.
        foreach (
            [
                PackagePath::REASON_MISSING,
                PackagePath::REASON_NOT_ABSOLUTE,
                PackagePath::REASON_DIRECTORY_UNKNOWN,
                PackagePath::REASON_PUBLIC_DIRECTORY,
                PackagePath::REASON_VERSION_CONTROL,
                PackagePath::REASON_SYMLINK,
                PackagePath::REASON_NOT_A_FILE,
                SubscriptionPackageReader::REASON_EMPTY,
                SubscriptionPackageReader::REASON_HEADER_UNREADABLE,
            ] as $expected
        ) {
            $this->assertArrayHasKey($expected, $seen);
        }

        array_map('unlink', glob($workspace . '/**/*.ndjson') ?: []);
        @unlink($link);
        @unlink($empty);
        @unlink($garbled);
        @unlink($uploads . '/public.ndjson');
        @unlink($repo . '/tracked.ndjson');
        @rmdir($repo . '/.git');
        @rmdir($repo);
        @rmdir($uploads);
        @rmdir($workspace . '/wp-content');
        @rmdir($workspace);
    }

    private function constantDeclaration(string $name): string
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(SubscriptionPackageReader::class))->getFileName(),
        );

        preg_match('/const\s+string\s+' . preg_quote($name, '/') . '\s*=\s*([^;]+);/', $source, $matches);

        return $matches[1] ?? '';
    }
}
