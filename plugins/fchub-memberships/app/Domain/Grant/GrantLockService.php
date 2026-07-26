<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\Event\EventClaimResult;
use FChubMemberships\Storage\EventLockRepository;

defined('ABSPATH') || exit;

final class GrantLockService
{
    public function __construct(private EventLockRepository $locks)
    {
    }

    public function orderEventHash(
        int $orderId,
        string $scope,
        int $integrationId,
        string $trigger,
        string $mode
    ): string {
        $this->validateOrderEventInput($orderId, $integrationId, $trigger);
        if (!in_array($scope, ['product', 'global'], true)) {
            throw new \InvalidArgumentException('Event feed scope must be product or global.');
        }
        if (!in_array($mode, ['grant', 'revoke'], true)) {
            throw new \InvalidArgumentException('Event mode must be grant or revoke.');
        }

        return hash(
            'sha256',
            "order:{$orderId}|scope:{$scope}|feed:{$integrationId}|trigger:{$trigger}|mode:{$mode}"
        );
    }

    public function subscriptionRenewalEventHash(array $payload): string
    {
        $subscriptionId = $this->payloadObjectId($payload, 'subscription');
        $renewalOrderId = $this->payloadObjectId($payload, 'order');

        return hash(
            'sha256',
            "subscription:{$subscriptionId}|renewal_order:{$renewalOrderId}|trigger:subscription_renewed"
        );
    }

    public function claimOrderEvent(
        int $orderId,
        string $scope,
        int $integrationId,
        string $trigger,
        string $mode,
        string $ownerToken,
        int $leaseSeconds = 300
    ): EventClaimResult {
        $hash = $this->orderEventHash($orderId, $scope, $integrationId, $trigger, $mode);

        return $this->locks->claim(
            $hash,
            [
                'order_id' => $orderId,
                'feed_id' => $integrationId,
                'trigger' => $trigger,
            ],
            $ownerToken,
            $leaseSeconds
        );
    }

    public function claimSubscriptionRenewalEvent(
        array $payload,
        string $ownerToken,
        int $leaseSeconds = 300
    ): EventClaimResult {
        $subscriptionId = $this->payloadObjectId($payload, 'subscription');
        $renewalOrderId = $this->payloadObjectId($payload, 'order');
        $hash = $this->subscriptionRenewalEventHash($payload);

        return $this->locks->claim(
            $hash,
            [
                'order_id' => $renewalOrderId,
                'subscription_id' => $subscriptionId,
                'feed_id' => 0,
                'trigger' => 'subscription_renewed',
            ],
            $ownerToken,
            $leaseSeconds
        );
    }

    public function succeedEventLock(string $eventHash, string $ownerToken): bool
    {
        return $this->locks->succeed($eventHash, $ownerToken);
    }

    public function failEventLock(
        string $eventHash,
        string $ownerToken,
        string $error,
        bool $retryable = true
    ): bool {
        return $this->locks->fail($eventHash, $ownerToken, $error, $retryable);
    }

    private function payloadObjectId(array $payload, string $key): int
    {
        $object = $payload[$key] ?? null;
        if (!is_object($object)) {
            throw new \InvalidArgumentException(
                sprintf('Subscription renewal payload must expose %s as an object.', esc_html($key))
            );
        }

        $value = $object->id ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (!is_string($value) || $value === '' || !ctype_digit($value)) {
            throw new \InvalidArgumentException(
                sprintf('Subscription renewal %s must expose a positive integer ID.', esc_html($key))
            );
        }

        $normalised = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        if ($normalised === ''
            || strlen($normalised) > strlen($maximum)
            || (strlen($normalised) === strlen($maximum) && strcmp($normalised, $maximum) > 0)
        ) {
            throw new \InvalidArgumentException(
                sprintf('Subscription renewal %s must expose a positive integer ID.', esc_html($key))
            );
        }

        return (int) $normalised;
    }

    private function validateOrderEventInput(int $orderId, int $integrationId, string $trigger): void
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Order ID must be greater than zero.');
        }
        if ($integrationId <= 0) {
            throw new \InvalidArgumentException('Integration ID must be greater than zero.');
        }
        if ($trigger === '' || $this->characterLength($trigger) > 100) {
            throw new \InvalidArgumentException('Event trigger must contain between 1 and 100 characters.');
        }
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $length = preg_match_all('/./us', $value);

        return $length === false ? strlen($value) : $length;
    }
}
