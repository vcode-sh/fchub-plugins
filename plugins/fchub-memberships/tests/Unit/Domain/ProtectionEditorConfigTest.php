<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Domain\ProtectionEditorConfig;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProtectionEditorConfigTest extends PluginTestCase
{
    public function test_it_returns_direct_and_inherited_sources_with_editor_options(): void
    {
        $GLOBALS['_fchub_test_get_object_taxonomies']['post'] = ['category'];
        $GLOBALS['_fchub_test_post_terms'][55]['category'] = [(object) [
            'term_id' => 7,
            'name' => 'Premium',
            'taxonomy' => 'category',
        ]];

        $service = new ProtectionEditorConfig(
            new class extends ProtectionRuleRepository {
                public function findByResource(string $resourceType, string $resourceId): ?array
                {
                    if ($resourceType === 'post') {
                        return [
                            'id' => 1,
                            'plan_ids' => [5],
                            'restriction_message' => 'Join {plan_names}',
                            'show_teaser' => 'yes',
                            'meta' => ['teaser_mode' => 'words', 'teaser_word_count' => 25],
                        ];
                    }

                    return $resourceType === 'category' && $resourceId === '7'
                        ? ['id' => 2, 'plan_ids' => [8], 'meta' => ['inheritance_mode' => 'all_posts']]
                        : null;
                }
            },
            new class extends PlanRuleResolver {
                public function findPlansWithResource(string $provider, string $resourceType, string $resourceId): array
                {
                    return [8];
                }
            },
            new class extends PlanRepository {
                public function getActivePlans(): array
                {
                    return [
                        ['id' => 5, 'title' => 'Gold'],
                        ['id' => 8, 'title' => 'Pro'],
                    ];
                }
            }
        );

        $config = $service->getForPost(55, 'post');

        self::assertTrue($config['enabled']);
        self::assertTrue($config['effective']['protected']);
        self::assertSame('mixed', $config['effective']['mode']);
        self::assertSame([5], $config['plan_ids']);
        self::assertSame('words', $config['teaser_mode']);
        self::assertSame(['direct', 'plan_rule', 'taxonomy'], array_column($config['effective']['sources'], 'type'));
        self::assertSame('Gold', $config['plans'][0]['label']);
    }

    public function test_it_sanitises_and_persists_a_direct_rule(): void
    {
        $saved = null;
        $service = new ProtectionEditorConfig(
            new class($saved) extends ProtectionRuleRepository {
                private mixed $capture;

                public function __construct(& $capture)
                {
                    $this->capture = & $capture;
                }

                public function findByResource(string $resourceType, string $resourceId): ?array
                {
                    return null;
                }

                public function createOrUpdate(string $resourceType, string $resourceId, array $data): int
                {
                    $this->capture = [$resourceType, $resourceId, $data];
                    return 10;
                }
            },
            new PlanRuleResolver(),
            new PlanRepository()
        );

        self::assertTrue($service->saveForPost(55, 'post', [
            'enabled' => true,
            'plan_ids' => ['5', '5', '-2', 'nope'],
            'teaser_mode' => 'words',
            'teaser_word_count' => 999,
            'custom_teaser' => '<b>Preview</b>',
            'restriction_message' => '<b>Join</b>',
            'cta_text' => '<b>Buy</b>',
            'cta_url' => '/pricing',
        ]));

        self::assertSame('post', $saved[0]);
        self::assertSame('55', $saved[1]);
        self::assertSame([5], $saved[2]['plan_ids']);
        self::assertSame(500, $saved[2]['meta']['teaser_word_count']);
        self::assertSame('/pricing', $saved[2]['meta']['cta_url']);
        self::assertSame('Join', $saved[2]['restriction_message']);
    }
}
