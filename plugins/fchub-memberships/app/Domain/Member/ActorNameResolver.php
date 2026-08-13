<?php

namespace FChubMemberships\Domain\Member;

defined('ABSPATH') || exit;

/**
 * Names the actor behind an audit record, once per user id.
 *
 * A member's trail repeats the same handful of actors, so the lookup is cached
 * for the life of the request rather than repeated per event.
 */
final class ActorNameResolver
{
    /** @var array<int, string> */
    private array $names = [];

    public function name(int $actorId): ?string
    {
        if ($actorId <= 0) {
            return null;
        }

        if (!isset($this->names[$actorId])) {
            $user = get_userdata($actorId);
            $this->names[$actorId] = $user
                ? (string) $user->display_name
                : sprintf(/* translators: %d: user id */ __('User #%d', 'fchub-memberships'), $actorId);
        }

        return $this->names[$actorId];
    }
}
