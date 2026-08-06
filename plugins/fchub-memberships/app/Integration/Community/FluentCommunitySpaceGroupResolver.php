<?php

declare(strict_types=1);

namespace FChubMemberships\Integration\Community;

defined('ABSPATH') || exit;

final class FluentCommunitySpaceGroupResolver
{
    /** @var \Closure(string, int): iterable<mixed> */
    private \Closure $groupsResolver;

    public function __construct(?callable $groupsResolver = null)
    {
        $this->groupsResolver = \Closure::fromCallable(
            $groupsResolver ?? $this->loadGroups(...)
        );
    }

    /**
     * @return list<array{id:string, label:string, spaces:list<array{id:string, label:string}>}>
     */
    public function search(string $query = '', int $limit = 50): array
    {
        try {
            $groups = ($this->groupsResolver)($query, max(1, min(100, $limit)));
        } catch (\Throwable) {
            return [];
        }

        if (!is_iterable($groups)) {
            return [];
        }

        $options = [];
        foreach ($groups as $group) {
            $groupId = $this->value($group, 'id');
            $title = trim((string) $this->value($group, 'title'));
            if (!ctype_digit((string) $groupId) || (int) $groupId <= 0 || $title === '') {
                continue;
            }

            $spaces = [];
            $rawSpaces = $this->value($group, 'spaces');
            if (is_iterable($rawSpaces)) {
                foreach ($rawSpaces as $space) {
                    if ((string) $this->value($space, 'type') !== 'community') {
                        continue;
                    }

                    $spaceId = $this->value($space, 'id');
                    $spaceTitle = trim((string) $this->value($space, 'title'));
                    if (!ctype_digit((string) $spaceId) || (int) $spaceId <= 0 || $spaceTitle === '') {
                        continue;
                    }

                    $spaces[(string) (int) $spaceId] = [
                        'id' => (string) (int) $spaceId,
                        'label' => $spaceTitle,
                    ];
                }
            }

            $options[] = [
                'id' => (string) (int) $groupId,
                'label' => $title,
                'spaces' => array_values($spaces),
            ];
        }

        return $options;
    }

    /** @return iterable<mixed> */
    private function loadGroups(string $query, int $limit): iterable
    {
        $modelClass = 'FluentCommunity\\App\\Models\\SpaceGroup';
        if (!class_exists($modelClass)) {
            return [];
        }

        $builder = $modelClass::query()
            ->with([
                'spaces' => static function ($query): void {
                    $query
                        ->where('type', 'community')
                        ->orderBy('serial', 'ASC')
                        ->orderBy('id', 'ASC');
                },
            ])
            ->orderBy('serial', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit($limit);

        if ($query !== '') {
            $builder->where('title', 'LIKE', '%' . $query . '%');
        }

        return $builder->get();
    }

    private function value(mixed $value, string $key): mixed
    {
        if (is_array($value)) {
            return $value[$key] ?? null;
        }

        if (is_object($value)) {
            return $value->{$key} ?? null;
        }

        return null;
    }
}
