<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final class IdentityConflict extends \RuntimeException
{
    public static function forIdentity(SourceIdentity $identity): self
    {
        // Deliberately omit the source key and ID. Customer and order identities
        // belong in the private journal, never in exception streams or public CLI output.
        return new self('Source identity is already claimed by an incompatible mapping.');
    }
}
