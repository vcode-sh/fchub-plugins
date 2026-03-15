<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\ContentProtection;
use FChubMemberships\Support\Constants;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ContentProtectionPausedMessageTest extends PluginTestCase
{
    public function test_filter_content_uses_paused_membership_context_message(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 9;
        $post = new \WP_Post();
        $post->ID = 55;
        $post->post_type = 'post';
        $post->post_excerpt = '';

        $GLOBALS['_fchub_test_current_post'] = $post;
        $GLOBALS['_fchub_test_posts'][55] = $GLOBALS['_fchub_test_current_post'];
        $GLOBALS['_fchub_test_post_types'] = ['post'];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'restriction_message_membership_paused' => 'Your access is paused right now.',
        ];

        $protection = new ContentProtection();
        $evaluator = new class extends AccessEvaluator {
            public function isProtected(string $provider, string $resourceType, string $resourceId): bool
            {
                return true;
            }

            public function evaluate(int $userId, string $provider, string $resourceType, string $resourceId): array
            {
                return [
                    'allowed' => false,
                    'reason' => Constants::REASON_MEMBERSHIP_PAUSED,
                    'drip_locked' => false,
                    'drip_available_at' => null,
                    'grant' => ['id' => 1],
                ];
            }

            public function canAccess(int $userId, string $provider, string $resourceType, string $resourceId): bool
            {
                return false;
            }
        };

        $reflection = new \ReflectionProperty($protection, 'evaluator');
        $reflection->setValue($protection, $evaluator);

        $output = $protection->filterContent('Original content');

        $this->assertStringContainsString('Your access is paused right now.', $output);
        $this->assertNotEmpty($GLOBALS['_fchub_test_enqueued_styles']);
    }
}
