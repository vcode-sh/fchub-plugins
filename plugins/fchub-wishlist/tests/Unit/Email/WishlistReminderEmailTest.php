<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Email;

use FChubWishlist\Email\WishlistReminderEmail;
use FChubWishlist\Support\Constants;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistReminderEmailTest extends TestCase
{
    #[Test]
    public function testGetDefaultTemplateContainsExpectedSmartCodes(): void
    {
        $template = WishlistReminderEmail::getDefaultTemplate();

        $this->assertStringContainsString('{user_name}', $template);
        $this->assertStringContainsString('{item_list}', $template);
        $this->assertStringContainsString('{wishlist_url}', $template);
    }

    #[Test]
    public function testGetTemplateReturnsCustomConfiguredTemplate(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, [
            'email_templates' => [
                'wishlist_reminder' => '<p>Custom reminder template</p>',
            ],
        ]);

        $email = new WishlistReminderEmail();

        $this->assertSame('<p>Custom reminder template</p>', $email->getTemplate());
    }

    #[Test]
    public function testSendPendingRemindersNoopsWhenFeatureDisabled(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, [
            'email_reminder_enabled' => 'no',
        ]);

        $email = new WishlistReminderEmail();
        $email->sendPendingReminders();

        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }
}

