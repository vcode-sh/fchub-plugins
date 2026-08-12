<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

interface TransferRecordSource
{
    /** @return iterable<RecordEnvelope> */
    public function records(TransferSelection $selection): iterable;
}
