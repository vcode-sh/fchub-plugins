<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The plugin header is enforced by WordPress, not by us, so nothing else in this
 * suite can reach it. That is exactly why it went wrong: `Requires Plugins` read
 * `woocommerce, fluent-cart`, WordPress reads that as an AND, and a cross-runtime
 * migration has neither site holding both — the source runs WooCommerce and
 * WooCommerce Subscriptions, the destination runs FluentCart. CartShift refused to
 * activate on the source, and the destination only kept running because an older
 * build had been activated before the header mattered.
 *
 * It cost a live rehearsal to find. This pins it so it costs a test run next time.
 */
#[CoversNothing]
final class PluginHeaderTest extends TestCase
{
    private static function header(): string
    {
        $file = dirname(__DIR__, 3) . '/cartshift.php';

        self::assertFileExists($file, 'The plugin entry file must exist to have a header at all.');

        $header = file_get_contents($file, false, null, 0, 8192);

        self::assertIsString($header, 'The plugin header must be readable.');

        return $header;
    }

    public function testThePluginDoesNotDemandBothCommerceEnginesAtOnce(): void
    {
        $header = self::header();

        preg_match('/^\s*\*\s*Requires Plugins:\s*(.+)$/mi', $header, $matches);

        $requires = isset($matches[1]) ? strtolower(trim($matches[1])) : '';

        $demandsWoo = str_contains($requires, 'woocommerce');
        $demandsFluentCart = str_contains($requires, 'fluent-cart');

        $this->assertFalse(
            $demandsWoo && $demandsFluentCart,
            'Requires Plugins may not demand WooCommerce and FluentCart together. WordPress '
            . 'enforces that header as an AND, and in a cross-runtime migration neither site '
            . 'holds both — so the header would refuse activation on the source (WooCommerce, '
            . 'no FluentCart) and on the destination (FluentCart, no WooCommerce). The runtime '
            . 'answer belongs to RuntimeCompatibilityProbe and PreflightCheck, which can say '
            . 'which runtime this is rather than refusing both. Found: "' . $requires . '".',
        );
    }

    public function testTheReplacementGatesAreStillPresent(): void
    {
        $app = dirname(__DIR__, 3) . '/app';

        $this->assertFileExists(
            $app . '/Domain/Subscription/RuntimeCompatibilityProbe.php',
            'Dropping the header is only safe because the probe reports the runtime instead. '
            . 'If it is gone, the plugin has no gate at all.',
        );

        $this->assertFileExists(
            $app . '/Validator/PreflightCheck.php',
            'Preflight is what refuses an operation the booted runtime cannot support. '
            . 'Without it, dropping the header leaves nothing to stop a bad run.',
        );
    }

    public function testTheHeaderStillDeclaresWhatWordPressGenuinelyNeeds(): void
    {
        $header = self::header();

        foreach (['Plugin Name', 'Version', 'Requires at least', 'Requires PHP', 'Text Domain'] as $field) {
            $this->assertMatchesRegularExpression(
                '/^\s*\*\s*' . preg_quote($field, '/') . ':/mi',
                $header,
                sprintf('The header lost "%s". Removing the plugin dependency was not licence to thin it out.', $field),
            );
        }
    }

    public function testPluginHeaderConstantAndTestRuntimeUseTheSameVersion(): void
    {
        $plugin = self::header();
        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/stubs/test-bootstrap.php');

        self::assertIsString($bootstrap, 'The PHPUnit bootstrap must be readable.');

        preg_match('/^\s*\*\s*Version:\s*([^\s]+)\s*$/mi', $plugin, $headerMatch);
        preg_match("/define\\('CARTSHIFT_VERSION',\\s*'([^']+)'\\)/", $plugin, $constantMatch);
        preg_match("/define\\('CARTSHIFT_VERSION',\\s*'([^']+)'\\)/", $bootstrap, $testMatch);

        self::assertArrayHasKey(1, $headerMatch, 'The plugin header version must be parseable.');
        self::assertArrayHasKey(1, $constantMatch, 'CARTSHIFT_VERSION must be parseable.');
        self::assertArrayHasKey(1, $testMatch, 'The test runtime version must be parseable.');
        self::assertSame($headerMatch[1], $constantMatch[1]);
        self::assertSame($headerMatch[1], $testMatch[1]);
    }
}
