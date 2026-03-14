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

        $sendToKsef = FakturowniaSettings::isKsefAutoSend();

        // Fetch original invoice to build explicit zero-out correction positions
        $originalInvoice = $this->api->getInvoice((int) $originalInvoiceId);

        if (!isset($originalInvoice['error']) && !empty($originalInvoice['positions'])) {
            $positions = $this->buildZeroCorrectionPositions($originalInvoice);
            $result = $this->api->createCorrection((int) $originalInvoiceId, $reason, $positions, $sendToKsef);
        } else {
            // Fallback: position-less correction (Fakturownia auto-zeroes)
            $result = $this->api->createCorrection((int) $originalInvoiceId, $reason, [], $sendToKsef);
        }

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

        // Track KSeF status if submitted
        if ($sendToKsef) {
            $govStatus = Arr::get($result, 'gov_status');
            if ($govStatus) {
                $order->updateMeta('_fakturownia_correction_ksef_status', $govStatus);
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

    /**
     * Build correction positions that zero out all original invoice items
     */
    private function buildZeroCorrectionPositions(array $originalInvoice): array
    {
        $positions = [];

        foreach ($originalInvoice['positions'] as $position) {
            $positions[] = [
                'name'              => $position['name'] ?? __('Item', 'fchub-fakturownia'),
                'quantity'          => 0,
                'quantity_unit'     => $position['quantity_unit'] ?? 'szt',
                'total_price_gross' => 0,
                'tax'               => $position['tax'] ?? 23,
                'correction_before_attributes' => [
                    'quantity'          => $position['quantity'] ?? 1,
                    'total_price_gross' => $position['total_price_gross'] ?? 0,
                    'tax'               => $position['tax'] ?? 23,
                ],
            ];
        }

        return $positions;
    }
}
