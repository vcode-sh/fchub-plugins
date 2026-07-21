<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Frontend;

use FChubMemberships\Domain\ContentProtection;
use FChubMemberships\Frontend\GutenbergBlocks;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GutenbergBlocksTest extends PluginTestCase
{
    public function test_it_registers_an_editable_protection_rest_field_for_supported_post_types(): void
    {
        $GLOBALS['_fchub_test_post_types'] = ['post' => 'post', 'page' => 'page'];

        GutenbergBlocks::registerProtectionFields();

        $field = $GLOBALS['_fchub_test_rest_fields']['post']['fchub_membership_protection'];
        self::assertIsCallable($field['get_callback']);
        self::assertIsCallable($field['update_callback']);
        self::assertSame('object', $field['schema']['type']);
        self::assertSame('edit', $field['schema']['context'][0]);
    }

    public function test_editor_assets_include_native_plugin_dependencies_and_dedicated_styles(): void
    {
        GutenbergBlocks::enqueueEditorAssets();

        $script = $GLOBALS['_fchub_test_enqueued_scripts'][0];
        self::assertContains('wp-plugins', $script[2]);
        self::assertContains('wp-data', $script[2]);
        self::assertContains('wp-editor', $script[2]);
        self::assertStringEndsWith('assets/css/editor.css', $GLOBALS['_fchub_test_enqueued_styles'][1][1]);
    }

    public function test_the_legacy_meta_box_is_marked_as_a_classic_editor_fallback(): void
    {
        $GLOBALS['_fchub_test_post_types'] = ['post' => 'post'];

        (new ContentProtection())->addMetaBox();

        self::assertTrue($GLOBALS['_fchub_test_meta_boxes'][0][5]['__back_compat_meta_box']);
    }
}
