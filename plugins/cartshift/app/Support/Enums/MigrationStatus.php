<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') or die;

enum MigrationStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
