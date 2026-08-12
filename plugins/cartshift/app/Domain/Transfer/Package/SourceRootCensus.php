<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

interface SourceRootCensus
{
    /** @return iterable<SourceIdentity> */
    public function identities(TransferSelection $selection): iterable;
}
