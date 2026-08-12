<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

final class WpCliEvalFileContractTest extends TestCase
{
    public function testInstalledContractEntrypointCanBeEvaluatedByWpCli(): void
    {
        $entrypoint = dirname(__DIR__, 3) . '/tests/Integration/Contract/runtime-contract.php';
        $contents = file_get_contents($entrypoint);

        self::assertIsString($contents);
        self::assertStringNotContainsString(
            'declare(strict_types=1)',
            $contents,
            'WP-CLI eval-file injects bootstrap statements before evaluating the entrypoint.',
        );
    }
}
