<?php

/**
 * Regenerates tests/js/presentation-fixture.json from the PHP renderers.
 *
 * The fixture is the contract between CurrencyContextPresentation and the browser
 * renderer in currency-context.js. A PHPUnit test asserts PHP still produces it and
 * a Node test asserts JavaScript reproduces it, so neither side can drift alone.
 *
 * Usage: php tests/js/generate-presentation-fixture.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use FChubMultiCurrency\Tests\Support\PresentationFixture;

require __DIR__ . '/../Support/PresentationFixture.php';

$fixture = PresentationFixture::build();
$path = __DIR__ . '/presentation-fixture.json';
file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

printf("Wrote %s (%d bytes)\n", $path, (int) filesize($path));