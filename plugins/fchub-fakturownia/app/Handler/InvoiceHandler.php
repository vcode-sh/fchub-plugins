<?php

namespace FChubFakturownia\Handler;

defined('ABSPATH') || exit;

use FChubFakturownia\API\FakturowniaAPI;
use FChubFakturownia\Integration\FakturowniaSettings;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;

class InvoiceHandler
{
    private FakturowniaAPI $api;

    public function __construct(FakturowniaAPI $api)
    {
        $this->api = $api;
    }

    /**
     * Create invoice in Fakturownia for the given order
     */
    public function createInvoice(Order $order, string $note = ''): array
    {
        // Prevent duplicate invoices
        $existingId = $order->getMeta('_fakturownia_invoice_id');
        if ($existingId) {
            return ['error' => __('Invoice already exists for this order.', 'fchub-fakturownia')];
        }

        $invoiceData = $this->mapOrderToInvoice($order);

        if ($note) {
            $invoiceData['description'] = mb_substr($note, 0, 3500);
        }

        // Proformas are not eligible for KSeF (gov_status: 'not_applicable')
        $useKsef = FakturowniaSettings::isKsefAutoSend()
            && ($invoiceData['kind'] ?? '') !== 'proforma';

        if ($useKsef) {
            $result = $this->api->createInvoiceWithKSeF($invoiceData);
        } else {
            $result = $this->api->createInvoice($invoiceData);
        }

        if (isset($result['error'])) {
            $order->addLog(
                __('Fakturownia: Invoice creation failed', 'fchub-fakturownia'),
                $result['error'],
                'error',
                'Fakturownia'
            );
            return $result;
        }

        // Store invoice data in order meta
        $this->storeInvoiceMeta($order, $result);

        $order->addLog(
            __('Fakturownia: Invoice created', 'fchub-fakturownia'),
            sprintf(
                __('Invoice %s created in Fakturownia (ID: %d)', 'fchub-fakturownia'),
                Arr::get($result, 'number', ''),
                Arr::get($result, 'id', 0)
            ),
            'info',
            'Fakturownia'
        );

        return $result;
    }

    /**
     * Map FluentCart order data to Fakturownia invoice format
     */
    private function mapOrderToInvoice(Order $order): array
    {
        $billingAddress = $order->billing_address;
        $customer = $order->customer;

        $paidAt = $order->paid_at ?? $order->created_at ?? null;
        $sellDate = $paidAt ? wp_date('Y-m-d', strtotime($paidAt)) : wp_date('Y-m-d');

        $kind = FakturowniaSettings::getInvoiceKind();
        $isProforma = ($kind === 'proforma');

        $invoice = [
            'kind'         => $kind,
            'payment_type' => $this->resolvePaymentType($order),
            'lang'         => FakturowniaSettings::getInvoiceLang(),
            'status'       => $isProforma ? 'issued' : 'paid',
            'sell_date'    => $sellDate,
            'issue_date'   => wp_date('Y-m-d'),
            'oid'          => $order->invoice_no ?: 'FC-' . $order->id,
            'oid_unique'   => 'yes',
            'currency'     => $order->currency ?? 'PLN',
        ];

        if (!$isProforma) {
            $invoice['paid_date'] = $sellDate;
        }

        // Payment deadline — for proformas this is the payment due date;
        // for paid invoices it's informational (Fakturownia shows it on the document)
        $invoice['payment_to_kind'] = $isProforma ? 7 : 'other_date';
        if (!$isProforma) {
            $invoice['payment_to'] = $sellDate;
        }

        // Department (seller data source)
        $departmentId = FakturowniaSettings::getDepartmentId();
        if ($departmentId) {
            $invoice['department_id'] = (int) $departmentId;
        } else {
            // Fallback: use site name as seller when no department is configured
            $invoice['seller_name'] = get_bloginfo('name');
        }

        // Buyer data — detect B2B by NIP presence (checkbox state is never persisted
        // because it's injected outside Vue's reactive system and has no name attribute)
        $nip = $billingAddress ? Arr::get($billingAddress->meta ?? [], 'other_data.nip', '') : '';

        if (!empty($nip)) {
            // B2B invoice
            $invoice['buyer_company'] = true;
            $invoice['buyer_tax_no'] = $nip;
            $invoice['buyer_tax_no_kind'] = $this->detectTaxNoKind($nip, $billingAddress->country ?? '');
            $buyerName = $billingAddress->company_name ?: ($billingAddress->name ?? '-');
            $invoice['buyer_name'] = mb_substr($buyerName, 0, 255);
        } else {
            // B2C invoice - Fakturownia requires buyer_first_name + buyer_last_name
            $invoice['buyer_company'] = false;
            $firstName = $billingAddress?->first_name ?? ($customer?->first_name ?? '');
            $lastName = $billingAddress?->last_name ?? ($customer?->last_name ?? '');

            // If only a single name field is available, split it
            if (!$firstName && !$lastName && $billingAddress && $billingAddress->name) {
                $parts = explode(' ', trim($billingAddress->name), 2);
                $firstName = $parts[0];
                $lastName = $parts[1] ?? '-';
            }

            $invoice['buyer_first_name'] = $firstName ?: '-';
            $invoice['buyer_last_name'] = $lastName ?: '-';
        }

        // Buyer address
        if ($billingAddress) {
            if ($billingAddress->address_1) {
                $street = $billingAddress->address_1;
                if ($billingAddress->address_2) {
                    $street .= ' ' . $billingAddress->address_2;
                }
                $invoice['buyer_street'] = mb_substr($street, 0, 255);
            }
            if ($billingAddress->city) {
                $invoice['buyer_city'] = $billingAddress->city;
            }
            if ($billingAddress->postcode) {
                $invoice['buyer_post_code'] = $billingAddress->postcode;
            }
            if ($billingAddress->country) {
                $invoice['buyer_country'] = $billingAddress->country;
            }
        }

        // Buyer contact
        $buyerEmail = $customer?->email ?? '';
        if ($buyerEmail) {
            $invoice['buyer_email'] = mb_substr($buyerEmail, 0, 255);
        }
        $phone = $billingAddress ? Arr::get($billingAddress->meta ?? [], 'other_data.phone', '') : '';
        if ($phone) {
            $invoice['buyer_phone'] = mb_substr($phone, 0, 16); // KSeF limit
        }

        // Positions (line items)
        $invoice['positions'] = $this->mapOrderItems($order);

        // KSeF requires exempt_tax_kind when any position uses 'zw' tax rate
        if (FakturowniaSettings::isKsefAutoSend()) {
            $hasExempt = false;
            foreach ($invoice['positions'] as $pos) {
                if (($pos['tax'] ?? '') === 'zw') {
                    $hasExempt = true;
                    break;
                }
            }
            if ($hasExempt && empty($invoice['exempt_tax_kind'])) {
                $invoice['exempt_tax_kind'] = 'art. 43 ust. 1';
            }
        }

        return $invoice;
    }

    /**
     * Resolve payment type from order's payment method, falling back to global setting.
     * Maps FluentCart payment gateway slugs to Fakturownia payment_type values.
     */
    private function resolvePaymentType(Order $order): string
    {
        $method = $order->payment_method ?? '';

        $map = apply_filters('fchub_fakturownia/payment_type_map', [
            'przelewy24' => 'transfer',
            'stripe'     => 'card',
            'paypal'     => 'paypal',
            'bacs'       => 'transfer',
            'cod'        => 'cash',
        ]);

        return $map[$method] ?? FakturowniaSettings::getPaymentType();
    }

    /**
     * Map order items to Fakturownia positions
     */
    private function mapOrderItems(Order $order): array
    {
        $positions = [];
        $items = $order->order_items;

        if (!$items) {
            return $positions;
        }

        foreach ($items as $item) {
            $position = [
                'name'              => mb_substr($item->title ?: __('Product', 'fchub-fakturownia'), 0, 256),
                'quantity'          => (int) $item->quantity,
                'quantity_unit'     => 'szt',
                'total_price_gross' => $this->centsToDecimal($item->line_total + $item->tax_amount),
            ];

            // Calculate tax rate from amounts — use line_total (post-discount) as the
            // denominator since tax is calculated on the discounted amount
            $taxAmount = (float) $item->tax_amount;
            $lineTotal = (float) $item->line_total;

            if ($lineTotal > 0.0 && $taxAmount !== 0.0) {
                $taxRate = round(($taxAmount / $lineTotal) * 100);
                $position['tax'] = $this->normalizeVatRate($taxRate);
            } elseif ($taxAmount === 0.0) {
                $position['tax'] = $this->normalizeVatRate(0);
            } else {
                $position['tax'] = 23; // default — lineTotal is 0 with nonzero tax (shouldn't happen)
            }

            $positions[] = $position;
        }

        // Add shipping as separate position if present
        $shippingTotal = (float) ($order->shipping_total ?? 0);
        if ($shippingTotal > 0) {
            $shippingTax = (float) ($order->shipping_tax ?? 0);
            $shippingGross = $shippingTotal + $shippingTax;

            $positions[] = [
                'name'              => __('Shipping', 'fchub-fakturownia'),
                'quantity'          => 1,
                'quantity_unit'     => 'szt',
                'total_price_gross' => $this->centsToDecimal($shippingGross),
                'tax'               => ($shippingTax > 0 && $shippingTotal > 0)
                    ? $this->normalizeVatRate(round(($shippingTax / $shippingTotal) * 100))
                    : $this->normalizeVatRate(0),
            ];
        }

        return $positions;
    }

    /**
     * Convert cents (stored in FluentCart) to decimal
     */
    private function centsToDecimal(float $cents): float
    {
        return round($cents / 100, 2);
    }

    /**
     * Normalize tax rate to standard Polish VAT rates
     */
    private function normalizeVatRate(float $rate): int|string
    {
        if ($rate < 0) {
            return 'zw';
        }

        // Standard Polish VAT rates
        $standardRates = [0, 5, 8, 23];

        // Find closest standard rate
        $closest = 23;
        $minDiff = PHP_FLOAT_MAX;
        foreach ($standardRates as $standardRate) {
            $diff = abs($rate - $standardRate);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $standardRate;
            }
        }

        return $closest;
    }

    /**
     * Detect buyer_tax_no_kind based on NIP format and country.
     * KSeF requires this to be set correctly for non-PL buyers.
     */
    private function detectTaxNoKind(string $nip, string $country): string
    {
        $cleaned = strtoupper(trim($nip));

        // Check for EU VAT prefix (2-letter country code followed by digits/letters)
        if (preg_match('/^([A-Z]{2})\d/', $cleaned, $m)) {
            $prefix = $m[1];
            if ($prefix !== 'PL') {
                $euCountries = ['AT','BE','BG','CY','CZ','DE','DK','EE','EL','ES','FI','FR',
                    'HR','HU','IE','IT','LT','LU','LV','MT','NL','PT','RO','SE','SI','SK'];
                return in_array($prefix, $euCountries, true) ? 'nip_ue' : 'other';
            }
            // PL prefix — strip it, treat as Polish NIP
            return '';
        }

        // No EU prefix — use country to decide
        if ($country && $country !== 'PL') {
            $euCountries = ['AT','BE','BG','CY','CZ','DE','DK','EE','GR','ES','FI','FR',
                'HR','HU','IE','IT','LT','LU','LV','MT','NL','PT','RO','SE','SI','SK'];
            return in_array(strtoupper($country), $euCountries, true) ? 'nip_ue' : 'other';
        }

        // Default: Polish NIP
        return '';
    }

    /**
     * Store Fakturownia invoice data in order meta
     */
    private function storeInvoiceMeta(Order $order, array $invoiceData): void
    {
        $order->updateMeta('_fakturownia_invoice_id', Arr::get($invoiceData, 'id'));
        $order->updateMeta('_fakturownia_invoice_number', Arr::get($invoiceData, 'number', ''));

        $invoiceUrl = $this->api->getBaseUrl() . '/invoices/' . Arr::get($invoiceData, 'id');
        $order->updateMeta('_fakturownia_invoice_url', $invoiceUrl);

        if ($clientId = Arr::get($invoiceData, 'client_id')) {
            $order->updateMeta('_fakturownia_client_id', $clientId);
        }

        // KSeF status — normalize demo_ prefix from sandbox environments
        $govStatus = Arr::get($invoiceData, 'gov_status');
        if ($govStatus) {
            if (str_starts_with($govStatus, 'demo_')) {
                $govStatus = substr($govStatus, 5);
            }
            $order->updateMeta('_fakturownia_ksef_status', $govStatus);
        }

        $govId = Arr::get($invoiceData, 'gov_id');
        if ($govId) {
            $order->updateMeta('_fakturownia_ksef_id', $govId);
        }

        $govLink = Arr::get($invoiceData, 'gov_verification_link');
        if ($govLink) {
            $order->updateMeta('_fakturownia_ksef_link', $govLink);
        }
    }
}
