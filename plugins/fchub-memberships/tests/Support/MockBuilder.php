<?php

namespace FChubMemberships\Tests\Support;

class MockBuilder
{
    private string $type;
    private array $data = [];

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function protectionRule(): self
    {
        $builder = new self('protection_rule');
        $builder->data = [
            'id'                  => 1,
            'resource_type'       => 'post',
            'resource_id'         => '1',
            'plan_ids'            => [],
            'protection_mode'     => 'explicit',
            'restriction_message' => null,
            'redirect_url'        => null,
            'show_teaser'         => 'no',
            'meta'                => [],
            'created_at'          => '2024-01-01 00:00:00',
            'updated_at'          => '2024-01-01 00:00:00',
        ];
        return $builder;
    }

    public static function plan(): self
    {
        $builder = new self('plan');
        $builder->data = [
            'id'                => 1,
            'title'             => 'Test Plan',
            'slug'              => 'test-plan',
            'status'            => 'active',
            'level'             => 0,
            'duration_type'     => 'lifetime',
            'duration_days'     => null,
            'trial_days'        => 0,
            'grace_period_days' => 0,
            'includes_plan_ids' => [],
            'restriction_message' => '',
            'settings'          => [],
            'meta'              => [],
        ];
        return $builder;
    }

    public static function grant(): self
    {
        $builder = new self('grant');
        $builder->data = [
            'id'              => 1,
            'user_id'         => 1,
            'plan_id'         => 1,
            'provider'        => 'wordpress_core',
            'resource_type'   => 'post',
            'resource_id'     => '1',
            'source_type'     => 'manual',
            'source_id'       => 0,
            'feed_id'         => null,
            'grant_key'       => '',
            'status'          => 'active',
            'starts_at'       => null,
            'expires_at'      => null,
            'drip_available_at' => null,
            'trial_ends_at'   => null,
            'renewal_count'   => 0,
            'source_ids'      => [],
            'meta'            => [],
            'created_at'      => '2024-01-01 00:00:00',
            'updated_at'      => '2024-01-01 00:00:00',
        ];
        return $builder;
    }

    // Protection rule helpers
    public function forPost(int $postId): self
    {
        $this->data['resource_type'] = 'post';
        $this->data['resource_id'] = (string) $postId;
        return $this;
    }

    public function forPage(int $pageId): self
    {
        $this->data['resource_type'] = 'page';
        $this->data['resource_id'] = (string) $pageId;
        return $this;
    }

    public function forComment(string $resourceId): self
    {
        $this->data['resource_type'] = 'comment';
        $this->data['resource_id'] = $resourceId;
        return $this;
    }

    public function forMenuItem(int $itemId): self
    {
        $this->data['resource_type'] = 'menu_item';
        $this->data['resource_id'] = (string) $itemId;
        return $this;
    }

    public function forUrlPattern(string $pattern): self
    {
        $this->data['resource_type'] = 'url_pattern';
        $this->data['resource_id'] = $pattern;
        return $this;
    }

    public function forSpecialPage(string $pageType): self
    {
        $this->data['resource_type'] = 'special_page';
        $this->data['resource_id'] = $pageType;
        return $this;
    }

    public function forResource(string $type, string $id): self
    {
        $this->data['resource_type'] = $type;
        $this->data['resource_id'] = $id;
        return $this;
    }

    public function withPlans(array $planIds): self
    {
        $this->data['plan_ids'] = $planIds;
        return $this;
    }

    public function withMeta(array $meta): self
    {
        $this->data['meta'] = array_merge($this->data['meta'] ?? [], $meta);
        return $this;
    }

    public function withRestrictionMessage(string $message): self
    {
        $this->data['restriction_message'] = $message;
        return $this;
    }

    public function withRedirectUrl(string $url): self
    {
        $this->data['redirect_url'] = $url;
        return $this;
    }

    public function withShowTeaser(string $value = 'yes'): self
    {
        $this->data['show_teaser'] = $value;
        return $this;
    }

    // Plan helpers
    public function withId(int $id): self
    {
        $this->data['id'] = $id;
        return $this;
    }

    public function withName(string $name): self
    {
        $this->data['title'] = $name;
        $this->data['slug'] = strtolower(str_replace(' ', '-', $name));
        return $this;
    }

    public function withLevel(int $level): self
    {
        $this->data['level'] = $level;
        return $this;
    }

    // Grant helpers
    public function forUser(int $userId): self
    {
        $this->data['user_id'] = $userId;
        return $this;
    }

    public function forPlan(int $planId): self
    {
        $this->data['plan_id'] = $planId;
        return $this;
    }

    public function active(): self
    {
        $this->data['status'] = 'active';
        return $this;
    }

    public function paused(): self
    {
        $this->data['status'] = 'paused';
        return $this;
    }

    public function revoked(): self
    {
        $this->data['status'] = 'revoked';
        return $this;
    }

    public function expired(): self
    {
        $this->data['status'] = 'expired';
        return $this;
    }

    public function withDrip(string $availableAt): self
    {
        $this->data['drip_available_at'] = $availableAt;
        return $this;
    }

    public function withTrial(string $endsAt): self
    {
        $this->data['trial_ends_at'] = $endsAt;
        return $this;
    }

    public function withExpiresAt(string $expiresAt): self
    {
        $this->data['expires_at'] = $expiresAt;
        return $this;
    }

    public function withCreatedAt(string $createdAt): self
    {
        $this->data['created_at'] = $createdAt;
        return $this;
    }

    public function build(): array
    {
        return $this->data;
    }
}
