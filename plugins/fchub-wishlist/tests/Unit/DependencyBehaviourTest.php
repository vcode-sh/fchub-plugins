<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DependencyBehaviourTest extends TestCase
{
    #[Test]
    public function bootstrapRegistersNoWishlistRuntimeHooksWithoutFluentCart(): void
    {
        $pluginFile = dirname(__DIR__, 2) . '/fchub-wishlist.php';
        $script = <<<'PHP'
<?php

define('ABSPATH', '/tmp/wordpress/');

$GLOBALS['registered_actions'] = [];

function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/wp-content/plugins/fchub-wishlist/';
}

function register_activation_hook(string $file, callable $callback): void
{
}

function register_deactivation_hook(string $file, callable $callback): void
{
}

function add_action(
    string $hook,
    callable $callback,
    int $priority = 10,
    int $acceptedArgs = 1
): bool {
    $GLOBALS['registered_actions'][] = [
        'hook' => $hook,
        'callback' => $callback,
        'priority' => $priority,
        'accepted_args' => $acceptedArgs,
    ];

    return true;
}

require __PLUGIN_FILE__;

foreach ($GLOBALS['registered_actions'] as $action) {
    if ($action['hook'] === 'init') {
        ($action['callback'])();
    }
}

echo json_encode(array_column($GLOBALS['registered_actions'], 'hook'), JSON_THROW_ON_ERROR);
PHP;
        $script = str_replace('__PLUGIN_FILE__', var_export($pluginFile, true), $script);
        $scriptFile = tempnam(sys_get_temp_dir(), 'fchub-wishlist-dependency-');

        self::assertIsString($scriptFile);
        file_put_contents($scriptFile, $script);

        $process = proc_open(
            [PHP_BINARY, $scriptFile],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        unlink($scriptFile);

        self::assertSame('', $stderr, 'The bootstrap must not emit dependency-related warnings or fatals.');
        self::assertSame(0, $exitCode, 'The bootstrap must not fatal when FluentCart is unavailable.');
        self::assertIsString($stdout);

        $registeredHooks = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $wishlistRuntimeHooks = [
            'admin_menu',
            'fchub_wishlist_cleanup_guests',
            'fchub_wishlist_cleanup_orphans',
            'fchub_wishlist_reminder',
            'fchub_wishlist_send_email',
        ];

        self::assertSame([], array_values(array_intersect($wishlistRuntimeHooks, $registeredHooks)));
    }
}
