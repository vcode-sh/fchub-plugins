<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration\Community;

use FChubMemberships\Integration\Community\FluentCommunitySpaceGroupResolver;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class FluentCommunitySpaceGroupResolverTest extends PluginTestCase
{
    public function test_search_returns_group_options_with_only_community_spaces(): void
    {
        $resolver = new FluentCommunitySpaceGroupResolver(
            static fn(string $query, int $limit): array => [[
                'id' => 1,
                'title' => 'Get Started',
                'spaces' => [
                    ['id' => 2, 'title' => 'Start Here', 'type' => 'community'],
                    ['id' => 4, 'title' => 'Course Space', 'type' => 'course'],
                    ['id' => 3, 'title' => 'Say Hello', 'type' => 'community'],
                ],
            ]]
        );

        self::assertSame([
            [
                'id' => '1',
                'label' => 'Get Started',
                'spaces' => [
                    ['id' => '2', 'label' => 'Start Here'],
                    ['id' => '3', 'label' => 'Say Hello'],
                ],
            ],
        ], $resolver->search('get', 50));
    }

    public function test_search_returns_empty_options_when_the_provider_is_unavailable(): void
    {
        $resolver = new FluentCommunitySpaceGroupResolver(
            static fn(string $query, int $limit): array => []
        );

        self::assertSame([], $resolver->search('', 50));
    }
}
