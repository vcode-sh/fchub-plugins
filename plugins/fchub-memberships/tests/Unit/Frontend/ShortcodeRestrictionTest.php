<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Frontend;

use FChubMemberships\Frontend\Shortcodes;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * `[fchub_restrict]` is the front door: whatever it returns is what a visitor
 * reads. The restricted body must never appear in the output for someone who
 * has not earned it.
 */
final class ShortcodeRestrictionTest extends PluginTestCase
{
    private const SECRET = 'The paid lesson body.';

    public function test_a_logged_out_visitor_is_never_served_the_restricted_body(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 0;

        $output = Shortcodes::renderRestrict([], self::SECRET);

        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertStringContainsString('fchub-membership-restricted', $output);
        self::assertStringContainsString('members only', $output);
    }

    public function test_a_logged_out_visitor_is_offered_a_login_link_when_asked(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 0;

        $withLink = Shortcodes::renderRestrict(['show_login' => 'yes'], self::SECRET);
        $withoutLink = Shortcodes::renderRestrict(['show_login' => 'no'], self::SECRET);

        self::assertStringContainsString('fchub-login-link', $withLink);
        self::assertStringNotContainsString('fchub-login-link', $withoutLink);
        self::assertStringNotContainsString(self::SECRET, $withoutLink);
    }

    public function test_a_custom_message_replaces_the_default_without_leaking_the_body(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 0;

        $output = Shortcodes::renderRestrict(['message' => 'Subscribers only, sorry.'], self::SECRET);

        self::assertStringContainsString('Subscribers only, sorry.', $output);
        self::assertStringNotContainsString(self::SECRET, $output);
    }
}
