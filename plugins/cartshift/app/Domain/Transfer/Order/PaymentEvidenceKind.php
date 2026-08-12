<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

enum PaymentEvidenceKind: string
{
    case FreeNoCharge = 'free_no_charge';
    case ProviderReference = 'provider_reference';
    case ManualPaidWithoutProvider = 'manual_paid_without_provider';
    case PendingOrFailed = 'pending_or_failed';
    case ExtensionAdapter = 'extension_adapter';
}
