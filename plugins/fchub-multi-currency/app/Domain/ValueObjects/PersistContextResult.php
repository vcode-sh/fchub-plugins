<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\ValueObjects;

defined('ABSPATH') || exit;

/**
 * Outcome of persisting a visitor currency preference.
 *
 * A preference can be written to a guest cookie, to logged-in user meta, to both, or — when
 * cookie persistence is off and nobody is logged in — to nowhere at all. That last case used to
 * be reported as a success, which is how a guest could "switch" currency and silently get the
 * default back on the next request.
 */
final readonly class PersistContextResult
{
    public function __construct(
        public bool $cookieStored,
        public bool $userMetaStored,
    ) {
    }

    public static function nothing(): self
    {
        return new self(cookieStored: false, userMetaStored: false);
    }

    /**
     * True when at least one persistence channel accepted the preference.
     */
    public function persisted(): bool
    {
        return $this->cookieStored || $this->userMetaStored;
    }
}
