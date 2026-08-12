<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

defined('ABSPATH') || exit;

final class TargetAlreadyClaimed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('target_already_claimed');
    }
}
