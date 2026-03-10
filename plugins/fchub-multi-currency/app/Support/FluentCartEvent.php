<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

/**
 * Normalises FluentCart hook payloads.
 *
 * FluentCart fires order hooks (e.g. order_paid_done) with an associative array
 * containing 'order', 'customer', and 'transaction' keys — not a raw Order object.
 * This helper extracts the order safely from either shape.
 */
final class FluentCartEvent
{
    /**
     * Extract the Order object from a FluentCart event payload.
     *
     * Accepts both the real event array (['order' => $order, ...]) and
     * a raw order object for backward compatibility.
     */
    public static function extractOrder(mixed $eventData): ?object
    {
        if (is_object($eventData)) {
            return $eventData;
        }

        if (is_array($eventData) && isset($eventData['order']) && is_object($eventData['order'])) {
            return $eventData['order'];
        }

        return null;
    }
}
