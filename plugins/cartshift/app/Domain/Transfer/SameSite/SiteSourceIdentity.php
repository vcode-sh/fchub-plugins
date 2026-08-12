<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

/**
 * The name this shop answers to in a transfer, minted once and never moved.
 *
 * Every v2 verb takes a `--source-key`, and `SourceIdentity` refuses the literal
 * `local` outright. The subscription plan's line that same-site "keeps `local`"
 * was written before that refusal existed; the resolution is a real key rather
 * than a hole in the guard, because the guard is what stops a cross-site export
 * being ambiguous about whose rows it carries.
 *
 * RANDOM, NOT DERIVED. A key computed from the site URL stops being idempotent
 * the moment the shop is restored under another hostname — a staging clone, a
 * domain change — and every mapping row written under the old one is orphaned
 * without a single error. The plan names that trap explicitly, so the key is
 * random and stored, and the store is the only thing that makes it stable.
 */
final class SiteSourceIdentity
{
    private const string OPTION = 'cartshift_site_source_key';

    /** Namespaced so a key is recognisable in a support ticket at a glance. */
    private const string PREFIX = 'site-';

    /**
     * The stored key, or null when this site has never been named.
     *
     * A read, and only a read. The admin screen displays the key, and a read
     * that minted one would turn every status poll into a configuration write.
     */
    public function current(): ?string
    {
        $stored = get_option(self::OPTION, '');

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * The stored key, minting one on first use.
     *
     * A stored key the transfer guard rejects is refused rather than replaced.
     * Regenerating would move the namespace out from under every row already
     * mapped under the old one — data that then belongs to nobody, with nothing
     * on screen to say so. A refusal is recoverable; that is not.
     *
     * @throws \RuntimeException when the stored key is not a valid source key.
     */
    public function ensure(): string
    {
        $stored = $this->current();

        if ($stored !== null) {
            try {
                SourceIdentity::assertValidSourceKey($stored);
            } catch (\InvalidArgumentException $invalid) {
                throw new \RuntimeException(
                    'site_source_key_invalid: the stored transfer source key "' . $stored . '" is not one the '
                    . 'transfer contract accepts, so nothing will run against it. Restore the original value; '
                    . 'CartShift will not mint a replacement, because every row already migrated is filed under '
                    . 'the old one.',
                    0,
                    $invalid,
                );
            }

            return $stored;
        }

        $key = self::PREFIX . bin2hex(random_bytes(8));
        if (add_option(self::OPTION, $key, '', false)) {
            return $key;
        }

        $winner = $this->current();
        if ($winner === null) {
            throw new \RuntimeException('site_source_key_not_stored');
        }
        SourceIdentity::assertValidSourceKey($winner);

        return $winner;
    }

    /** Remove only the identity minted by a preparation attempt that failed. */
    public function forgetIfCurrent(string $sourceKey): void
    {
        if ($this->current() === $sourceKey) {
            delete_option(self::OPTION);
        }
    }
}
