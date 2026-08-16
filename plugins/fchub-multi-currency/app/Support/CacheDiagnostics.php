<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

/**
 * Temporary diagnostic for issue #72's non-base-currency latency
 * investigation — see Profiler for the full rationale. Answers, from the
 * live object cache instance rather than by assuming: which object-cache
 * implementation is actually active for this request, and whether a given
 * cache group has ended up on a non-persistent/ignored list (which would
 * make wp_cache_set()/wp_cache_get() silently behave as request-local only,
 * even with a persistent drop-in like Redis active site-wide).
 *
 * There is no WordPress core API to ask "is group X non-persistent" — only
 * wp_cache_add_non_persistent_groups() to declare one, which several
 * different drop-ins implement with their own internal property names. This
 * reflects over whatever object is in $GLOBALS['wp_object_cache'] and scans
 * every array-typed property for the group name, rather than assuming a
 * specific drop-in's internal shape.
 *
 * Not meant to ship long-term. Remove alongside Profiler once the real
 * bottleneck is identified and fixed.
 */
final class CacheDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public static function inspectGroup(string $group): array
    {
        $objectCache = $GLOBALS['wp_object_cache'] ?? null;

        $result = [
            'group'                    => $group,
            'wp_using_ext_object_cache' => function_exists('wp_using_ext_object_cache') ? wp_using_ext_object_cache() : null,
            'object_cache_class'       => is_object($objectCache) ? get_class($objectCache) : null,
            'object_cache_present'     => $objectCache !== null,
        ];

        if (!is_object($objectCache)) {
            $result['note'] = 'No $GLOBALS[\'wp_object_cache\'] instance found — cannot inspect group lists.';

            return $result;
        }

        // Scan every declared property (including private/protected, on this
        // instance and its parents) for an array that contains our group
        // name, rather than assuming a specific drop-in's property naming.
        $matches = [];
        $inspectedArrayProps = [];

        try {
            $reflection = new \ReflectionObject($objectCache);
            while ($reflection !== null) {
                foreach ($reflection->getProperties() as $property) {
                    $property->setAccessible(true);

                    if (!$property->isInitialized($objectCache)) {
                        continue;
                    }

                    $value = $property->getValue($objectCache);

                    if (!is_array($value)) {
                        continue;
                    }

                    $name = $property->getName();
                    $inspectedArrayProps[] = $name;

                    // Groups can be stored as a list (in_array) or as keys
                    // (isset) depending on the drop-in — check both shapes.
                    if (in_array($group, $value, true) || array_key_exists($group, $value)) {
                        $matches[] = $name;
                    }
                }

                $reflection = $reflection->getParentClass() ?: null;
            }
        } catch (\Throwable $e) {
            $result['reflection_error'] = $e->getMessage();
        }

        $result['matched_properties'] = $matches;
        $result['group_appears_non_persistent_or_ignored'] = $matches !== [];
        $result['inspected_array_properties'] = array_values(array_unique($inspectedArrayProps));

        return $result;
    }
}
