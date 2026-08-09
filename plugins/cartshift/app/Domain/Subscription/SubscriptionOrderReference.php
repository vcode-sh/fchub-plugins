<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * One source order and the relationship it holds to a subscription.
 *
 * The type is carried rather than inferred because
 * `WC_Subscription::get_related_orders()` flattens its grouped result and
 * throws the type away. The plan therefore requires four separate typed calls,
 * and this class is where the answer to each of them lands with its label still
 * attached.
 */
final readonly class SubscriptionOrderReference
{
    public const string PARENT = 'parent';
    public const string RENEWAL = 'renewal';
    public const string SWITCH = 'switch';
    public const string RESUBSCRIBE = 'resubscribe';

    /** @var list<string> The only relationships this implementation knows. */
    public const array RELATIONSHIPS = [
        self::PARENT,
        self::RENEWAL,
        self::SWITCH,
        self::RESUBSCRIBE,
    ];

    public function __construct(
        public int $sourceOrderId,
        public string $relationship,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'relationship'    => $this->relationship,
            'source_order_id' => $this->sourceOrderId,
        ];
    }
}
