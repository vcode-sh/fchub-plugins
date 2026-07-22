<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\AdminMenu;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminMenuAssetManifestTest extends TestCase
{
    public function test_collects_entry_and_imported_chunk_styles_without_duplicates(): void
    {
        $manifest = [
            'resources/admin/main.js' => [
                'css' => ['assets/admin.css'],
                'imports' => ['_shared.js', '_dialog.js'],
            ],
            '_shared.js' => [
                'css' => ['assets/shared.css'],
                'imports' => ['_dialog.js'],
            ],
            '_dialog.js' => [
                'css' => ['assets/overlay.css', 'assets/shared.css'],
            ],
        ];

        $collector = new ReflectionMethod(AdminMenu::class, 'collectCssAssets');

        self::assertSame(
            ['assets/admin.css', 'assets/shared.css', 'assets/overlay.css'],
            $collector->invoke(null, $manifest, 'resources/admin/main.js')
        );
    }
}
