<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * The real answer: whatever PHP has loaded right now.
 *
 * No autoloading is triggered on purpose — class_exists()'s second argument is
 * left at its default true only where a missing class genuinely means "not
 * installed", which is every case here, because both WooCommerce Subscriptions
 * and FluentCart register their autoloaders at boot. A class that cannot be
 * autoloaded is a class this runtime does not have.
 */
final class LoadedRuntimeSymbols implements RuntimeSymbols
{
    public function functionExists(string $function): bool
    {
        return function_exists($function);
    }

    public function classExists(string $class): bool
    {
        return class_exists($class);
    }

    public function methodExists(string $class, string $method): bool
    {
        return method_exists($class, $method);
    }

    public function constantValue(string $constant): ?string
    {
        if (!defined($constant)) {
            return null;
        }

        $value = constant($constant);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return list<string>
     */
    public function declaredFillable(string $class): array
    {
        // A bare instantiation and one declaration read. FluentCart's ORM base
        // touches no database until a query is issued, so this stays inside the
        // gate's zero-write promise.
        return array_values(array_map(strval(...), (new $class())->getFillable()));
    }
}
