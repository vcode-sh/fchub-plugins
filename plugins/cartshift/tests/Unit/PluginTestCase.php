<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit;

use PHPUnit\Framework\TestCase;

abstract class PluginTestCase extends TestCase
{
    /**
     * Globals the stubs read without an isset() guard, so they must exist as empty
     * arrays rather than merely being absent.
     *
     * @var string[]
     */
    private const array SEEDED_GLOBALS = [
        '_cartshift_test_options',
        '_cartshift_test_actions',
        '_cartshift_test_filters',
        '_cartshift_test_queries',
        '_cartshift_test_deleted_posts',
        '_cartshift_test_deleted_terms',
        '_cartshift_test_post_meta',
        '_cartshift_test_as_scheduled',
        '_cartshift_test_as_unscheduled',
        '_cartshift_test_wc_products',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTestGlobals();
    }

    protected function tearDown(): void
    {
        $this->resetTestGlobals();

        parent::tearDown();
    }

    /**
     * Clear every `_cartshift_test_*` global, then re-seed the collection-shaped ones.
     *
     * The stubs are driven entirely by these globals, and the suite has ~39 of them
     * against 10 that used to be reset. The unreset remainder leaked across test
     * CLASSES: `_cartshift_test_get_results_callback` in particular meant whichever
     * class ran previously decided what the database appeared to contain. That is why
     * tests passed under `--filter` and failed in a full run — the classic symptom, and
     * one that wasted real time this session being misdiagnosed as a stub bug.
     *
     * Clearing by prefix rather than by list is deliberate: a global added later is
     * isolated automatically, instead of silently joining the leaky 29.
     */
    private function resetTestGlobals(): void
    {
        // The transaction depth is connection state, not a `$GLOBALS` entry, and
        // a test that threw between `begin()` and `commit()` would otherwise
        // leave the next one thinking it was already inside a transaction.
        \CartShift\Support\DatabaseTransaction::reset();

        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, '_cartshift_test_')) {
                unset($GLOBALS[$key]);
            }
        }

        foreach (self::SEEDED_GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
    }
}
