<?php

/**
 * PHPStan declarations for runtime functions supplied by FluentCart and Action Scheduler.
 *
 * @param array<string, mixed> $extra
 */
function fluent_cart_error_log(string $title, mixed $content, array $extra = []): void
{
}

/**
 * @param array<int, mixed> $args
 */
function as_schedule_single_action(
    int $timestamp,
    string $hook,
    array $args = [],
    string $group = ''
): int {
    return 0;
}

/**
 * @param array<int, mixed> $args
 */
function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void
{
}
