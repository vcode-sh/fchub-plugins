<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

/**
 * How CartShift recognises an order it has already written — in one place,
 * because two places is how the two order paths drift apart.
 *
 * There are two writers of `fct_orders` in this plugin. `OrderMigrator` handles
 * the ordinary run, reading live `WC_Order` objects; `SubscriptionOrderImporter`
 * handles a subscription's history, reading `OrderRecord` payloads that may have
 * been exported from another WordPress entirely. Both have to answer the same
 * two questions the same way, and the consequence of disagreeing is not subtle:
 * one of them creates a duplicate of an order the other already imported, and
 * the customer's payment history is counted twice.
 *
 * Both questions used to be answered by a hand copy in each class, with a
 * docblock in one of them asserting in prose that they must not disagree. This
 * is that assertion, in a form the compiler enforces.
 *
 * The `WC-{id}` invoice number is not CartShift's invention. FluentCart's own
 * WooCommerce importer writes it, which is why the probe finds orders CartShift
 * never touched — and `fct_orders.invoice_no` is VARCHAR(192) and indexed
 * (`database/Migrations/OrdersMigrator.php:19, 51`), so the lookup is one index
 * hit per order.
 */
final class OrderIdentity
{
    /**
     * The invoice number both writers stamp, and the probe looks for.
     */
    public static function invoiceNo(int $sourceOrderId): string
    {
        return 'WC-' . $sourceOrderId;
    }

    /**
     * An order already in `fct_orders` for this source ID, whoever imported it.
     *
     * Answers for FluentCart's own WooCommerce importer as well as for a
     * previous CartShift run, which is the point: adopting somebody else's row
     * is what stops a second import creating a twin.
     */
    /**
     * @deprecated Invoice numbers are reconciliation signals, never identity.
     * @internal Final legacy-route removal deletes this compatibility probe.
     */
    public static function findImportedOrderId(int $sourceOrderId): ?int
    {
        global $wpdb;

        $fcId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_orders WHERE invoice_no = %s LIMIT 1",
            self::invoiceNo($sourceOrderId),
        ));

        return $fcId !== null && (int) $fcId > 0 ? (int) $fcId : null;
    }

    /**
     * The ID-map key one order item is filed under.
     *
     * Compound, because a source order has many items and the map is keyed by a
     * single string.
     */
    public static function itemKey(int $sourceOrderId, int $index): string
    {
        return $sourceOrderId . '_' . $index;
    }

    public static function addressKey(int $sourceOrderId, string $type): string
    {
        return $sourceOrderId . '_' . $type;
    }

    /**
     * The ID-map key one order transaction is filed under.
     *
     * `{id}_charge` for the first, which is the key `OrderMigrator` has always
     * written — so an order imported through the ordinary path and later
     * revisited by the subscription history path finds its own transaction
     * rather than creating a second one, and the history linker finds it rather
     * than silently leaving a paid cycle uncounted.
     */
    public static function transactionKey(int $sourceOrderId, int $index = 0): string
    {
        return $index === 0
            ? $sourceOrderId . '_charge'
            : $sourceOrderId . '_charge_' . $index;
    }

    public static function refundTransactionKey(int $sourceOrderId, int $refundId): string
    {
        return $sourceOrderId . '_refund_' . $refundId;
    }
}
