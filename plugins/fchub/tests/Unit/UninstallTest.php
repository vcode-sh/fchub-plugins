<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use FChubHub\Catalogue\CatalogueRepository;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase
{
    private const HUB_OPTIONS = [
        'fchub_catalogue_last_good',
        'fchub_catalogue_etag',
        'fchub_catalogue_last_refresh',
    ];

    private const HUB_TRANSIENT = 'fchub_catalogue_fresh';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_hub_test_options'] = [];
        $GLOBALS['_fchub_hub_test_transients'] = [];
        $GLOBALS['_fchub_hub_test_is_multisite'] = false;
        $GLOBALS['_fchub_hub_test_sites'] = [1];
        $GLOBALS['_fchub_hub_test_current_blog_id'] = 1;
        $GLOBALS['_fchub_hub_test_blog_stack'] = [];
        $GLOBALS['_fchub_hub_test_switched_blogs'] = [];
    }

    private function seedSite(int $blogId): void
    {
        foreach (self::HUB_OPTIONS as $option) {
            $GLOBALS['_fchub_hub_test_options'][$blogId][$option] = 'seeded-value';
        }

        $GLOBALS['_fchub_hub_test_transients'][$blogId][self::HUB_TRANSIENT] = ['seeded' => true];

        // A foreign, non-hub option that must survive uninstall untouched.
        $GLOBALS['_fchub_hub_test_options'][$blogId]['fchub_membership_plans'] = 'keep-me';
    }

    private function runUninstall(): void
    {
        defined('WP_UNINSTALL_PLUGIN') || define('WP_UNINSTALL_PLUGIN', true);

        require FCHUB_HUB_PATH . 'uninstall.php';
    }

    public function testTheKeysUninstallDeletesAreTheKeysTheHubActuallyWrites(): void
    {
        // uninstall.php runs before any autoloader exists, so it genuinely
        // cannot reach these constants and the literals have to stay. What it
        // can have is this: rename a constant without touching uninstall.php
        // and the suite goes red here, rather than the row going on living in
        // every customer's database for ever.
        self::assertSame(
            [
                CatalogueRepository::OPTION_LAST_GOOD,
                CatalogueRepository::OPTION_ETAG,
                CatalogueRepository::OPTION_LAST_REFRESH,
            ],
            self::HUB_OPTIONS
        );

        self::assertSame(CatalogueRepository::TRANSIENT_FRESH, self::HUB_TRANSIENT);

        // And the literals in this class are the literals uninstall.php runs.
        $source = (string) file_get_contents(FCHUB_HUB_PATH . 'uninstall.php');

        foreach ([...self::HUB_OPTIONS, self::HUB_TRANSIENT] as $key) {
            self::assertStringContainsString("'" . $key . "'", $source, $key . ' must be deleted on uninstall.');
        }
    }

    public function testSingleSiteUninstallRemovesOnlyTheFourHubOwnedKeys(): void
    {
        $this->seedSite(1);

        $this->runUninstall();

        foreach (self::HUB_OPTIONS as $option) {
            self::assertFalse(get_option($option, false), "{$option} should have been deleted.");
        }

        self::assertFalse(get_transient(self::HUB_TRANSIENT));
        self::assertSame('keep-me', get_option('fchub_membership_plans'), 'A foreign option must be left untouched.');
    }

    public function testMultisiteUninstallCleansEverySiteInBoundedPagesAndRestoresTheOriginalBlog(): void
    {
        $GLOBALS['_fchub_hub_test_is_multisite'] = true;
        $GLOBALS['_fchub_hub_test_sites'] = [1, 2];
        $GLOBALS['_fchub_hub_test_current_blog_id'] = 1;

        $this->seedSite(1);
        $this->seedSite(2);

        $this->runUninstall();

        // Capture where uninstall.php left the current blog before the
        // verification loop below deliberately switches it per site to read
        // each site's options back out.
        $restoredBlogId = $GLOBALS['_fchub_hub_test_current_blog_id'];

        foreach ([1, 2] as $blogId) {
            $GLOBALS['_fchub_hub_test_current_blog_id'] = $blogId;

            foreach (self::HUB_OPTIONS as $option) {
                self::assertFalse(get_option($option, false), "Blog {$blogId} should have {$option} deleted.");
            }

            self::assertFalse(get_transient(self::HUB_TRANSIENT), "Blog {$blogId} should have its transient cleared.");
            self::assertSame('keep-me', get_option('fchub_membership_plans'), "Blog {$blogId} foreign option must survive.");
        }

        self::assertSame(
            1,
            $restoredBlogId,
            'Uninstall must restore the originating site as current once cleanup finishes.'
        );
        self::assertSame([1, 2], $GLOBALS['_fchub_hub_test_switched_blogs']);
    }
}
