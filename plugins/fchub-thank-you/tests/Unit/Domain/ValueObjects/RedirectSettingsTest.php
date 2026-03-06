<?php

declare(strict_types=1);

namespace FchubThankYou\Tests\Unit\Domain\ValueObjects;

use FchubThankYou\Domain\Enums\RedirectType;
use FchubThankYou\Domain\ValueObjects\RedirectSettings;
use PHPUnit\Framework\TestCase;

final class RedirectSettingsTest extends TestCase
{
    public function testToArrayReturnsCorrectShape(): void
    {
        $settings = new RedirectSettings(
            enabled: true,
            type: RedirectType::Url,
            url: 'https://example.com/thank-you',
        );

        self::assertSame([
            'enabled'   => true,
            'type'      => 'url',
            'target_id' => null,
            'url'       => 'https://example.com/thank-you',
            'post_type' => '',
        ], $settings->toArray());
    }

    public function testToArrayWithDisabledState(): void
    {
        $settings = new RedirectSettings(enabled: false);

        self::assertSame([
            'enabled'   => false,
            'type'      => 'url',
            'target_id' => null,
            'url'       => '',
            'post_type' => '',
        ], $settings->toArray());
    }

    public function testToArrayWithPageType(): void
    {
        $settings = new RedirectSettings(
            enabled: true,
            type: RedirectType::Page,
            targetId: 42,
        );

        self::assertSame([
            'enabled'   => true,
            'type'      => 'page',
            'target_id' => 42,
            'url'       => '',
            'post_type' => '',
        ], $settings->toArray());
    }

    public function testToArrayWithCptType(): void
    {
        $settings = new RedirectSettings(
            enabled: true,
            type: RedirectType::Cpt,
            targetId: 99,
            postType: 'product',
        );

        self::assertSame([
            'enabled'   => true,
            'type'      => 'cpt',
            'target_id' => 99,
            'url'       => '',
            'post_type' => 'product',
        ], $settings->toArray());
    }

    public function testHasValidTargetForPageWithTargetId(): void
    {
        $settings = new RedirectSettings(enabled: true, type: RedirectType::Page, targetId: 10);
        self::assertTrue($settings->hasValidTarget());
    }

    public function testHasValidTargetForPageWithoutTargetId(): void
    {
        $settings = new RedirectSettings(enabled: true, type: RedirectType::Page);
        self::assertFalse($settings->hasValidTarget());
    }

    public function testHasValidTargetForPostWithTargetId(): void
    {
        $settings = new RedirectSettings(enabled: true, type: RedirectType::Post, targetId: 5);
        self::assertTrue($settings->hasValidTarget());
    }

    public function testHasValidTargetForPostWithoutTargetId(): void
    {
        $settings = new RedirectSettings(enabled: true, type: RedirectType::Post);
        self::assertFalse($settings->hasValidTarget());
    }

    public function testHasValidTargetForCptWithTargetId(): void
    {
        $settings = new RedirectSettings(enabled: true, type: RedirectType::Cpt, targetId: 7, postType: 'product');
        self::assertTrue($settings->hasValidTarget());
    }

    public function testHasValidTargetForCptWithoutTargetId(): void
    {
        $settings = new RedirectSettings(enabled: true, type: RedirectType::Cpt, postType: 'product');
        self::assertFalse($settings->hasValidTarget());
    }

    public function testHasValidTargetForUrlWithUrl(): void
    {
        $settings = new RedirectSettings(enabled: true, type: RedirectType::Url, url: 'https://example.com');
        self::assertTrue($settings->hasValidTarget());
    }

    public function testHasValidTargetForUrlWithEmptyUrl(): void
    {
        $settings = new RedirectSettings(enabled: true, type: RedirectType::Url);
        self::assertFalse($settings->hasValidTarget());
    }

    public function testDefaultsToUrlType(): void
    {
        $settings = new RedirectSettings(enabled: true);
        self::assertSame(RedirectType::Url, $settings->type);
    }
}
