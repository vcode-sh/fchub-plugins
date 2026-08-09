<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * One source identity — a registered user, or a guest keyed by email.
 *
 * `sourceUserId` is null for a guest and is never copied into the destination
 * regardless: a numeric WordPress user ID means nothing in another WordPress
 * install, and 349 of the 564 Lapka subscriptions say zero. What survives the
 * crossing is `sourceRef`, which for a guest is a SHA-256 of the normalised
 * email, so 349 different people stay 349 different people.
 */
final readonly class CustomerRecord
{
    public const string KIND = 'customer';

    /**
     * @param array<string, mixed> $billingIdentity
     */
    public function __construct(
        public string $sourceKey,
        public string $sourceRef,
        public int|null $sourceUserId,
        public string $email,
        public array $billingIdentity,
        public string $fingerprint,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function withFingerprint(string $fingerprint): self
    {
        return new self(
            $this->sourceKey,
            $this->sourceRef,
            $this->sourceUserId,
            $this->email,
            $this->billingIdentity,
            $fingerprint,
        );
    }

    /**
     * The canonical, non-secret field set the fingerprint is taken over.
     *
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return [
            'billing_identity' => $this->billingIdentity,
            'email'            => $this->email,
            'kind'             => self::KIND,
            'source_key'       => $this->sourceKey,
            'source_ref'       => $this->sourceRef,
            'source_user_id'   => $this->sourceUserId,
        ];
    }

    /**
     * The package payload: everything the decoder needs, fingerprint included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fingerprintPayload() + ['fingerprint' => $this->fingerprint];
    }
}
