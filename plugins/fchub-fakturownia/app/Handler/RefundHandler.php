<?php

namespace FChubFakturownia\Handler;

defined('ABSPATH') || exit;

use FChubFakturownia\API\FakturowniaAPI;
use FChubFakturownia\Integration\FakturowniaSettings;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;

class RefundHandler
{
    private FakturowniaAPI $api;

    public function __construct(FakturowniaAPI $api)
    {
        $this->api = $api;
    }

    /**
     * Create correction invoice for a refunded order
     */
    public function createCorrectionInvoice(Order $order): array
    {
        $originalInvoiceId = $order->getMeta('_fakturownia_invoice_id');

        if (!$originalInvoiceId) {
            $order->addLog(
                __('Fakturownia: Cannot create correction', 'fchub-fakturownia'),
                __('No original invoice found for this order.', 'fchub-fakturownia'),
                'warning',
                'Fakturownia'
            );
            return ['error' => __('No original invoice found.', 'fchub-fakturownia')];
        }

        // Prevent duplicate corrections
        $existingCorrectionId = $order->getMeta('_fakturownia_correction_id');
        if ($existingCorrectionId) {
            return ['error' => __('Correction invoice already exists.', 'fchub-fakturownia')];
        }

        $reason = sprintf(
            __('Refund - Order #%s', 'fchub-fakturownia'),
            $order->invoice_no ?: $order->id
        );

        $result = $this->api->createCorrection((int) $originalInvoiceId, $reason);

        if (isset($result['error'])) {
            $order->addLog(
                __('Fakturownia: Correction invoice failed', 'fchub-fakturownia'),
                $result['error'],
                'error',
                'Fakturownia'
            );
            return $result;
        }

        // Store correction meta
        $order->updateMeta('_fakturownia_correction_id', Arr::get($result, 'id'));
        $order->updateMeta('_fakturownia_correction_number', Arr::get($result, 'number', ''));

        // Send correction to KSeF if enabled
        if (FakturowniaSettings::isKsefAutoSend()) {
            $correctionId = Arr::get($result, 'id');
            if ($correctionId) {
                $ksefResult = $this->api->sendToKSeF((int) $correctionId);
                if (!isset($ksefResult['error'])) {
                    $order->updateMeta('_fakturownia_correction_ksef_status', Arr::get($ksefResult, 'gov_status', ''));
                }
            }
        }

        $order->addLog(
            __('Fakturownia: Correction invoice created', 'fchub-fakturownia'),
            sprintf(
                __('Correction %s created for invoice %s', 'fchub-fakturownia'),
                Arr::get($result, 'number', ''),
                $order->getMeta('_fakturownia_invoice_number')
            ),
            'info',
            'Fakturownia'
        );

        return $result;
    }
}
