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
    public function createInvoice(Order $order): array
    {
        // Prevent duplicate invoices
        $existingId = $order->getMeta('_fakturownia_invoice_id');
        if ($existingId) {
            return ['error' => __('Invoice already exists for this order.', 'fchub-fakturownia')];
        }

        $invoiceData = $this->mapOrderToInvoice($order);

        if (FakturowniaSettings::isKsefAutoSend()) {
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
        $sellDate = $paidAt ? date('Y-m-d', strtotime($paidAt)) : date('Y-m-d');

        $invoice = [
            'kind'         => FakturowniaSettings::getInvoiceKind(),
            'payment_type' => $this->resolvePaymentType($order),
            'lang'         => FakturowniaSettings::getInvoiceLang(),
            'status'       => 'paid',
            'sell_date'    => $sellDate,
            'issue_date'   => date('Y-m-d'),
            'paid_date'    => $sellDate,
            'oid'          => $order->invoice_no ?: 'FC-' . $order->id,
            'oid_unique'   => 'yes',
        ];

        // Department (seller data source)
        $departmentId = FakturowniaSettings::getDepartmentId();
        if ($departmentId) {
            $invoice['department_id'] = (int) $departmentId;
        } else {
            // Fallback: use site name as seller when no department is configured
            $invoice['seller_name'] = get_bloginfo('name');
        }

        // Buyer data
        $wantsCompanyInvoice = Arr::get($billingAddress->meta ?? [], 'other_data.wants_company_invoice', false);
        $nip = Arr::get($billingAddress->meta ?? [], 'other_data.nip', '');

        if ($wantsCompanyInvoice && $nip) {
            // B2B invoice
            $invoice['buyer_company'] = true;
            $invoice['buyer_tax_no'] = $nip;
            $invoice['buyer_name'] = $billingAddress->company_name ?: $billingAddress->name;
        } else {
            // B2C invoice - Fakturownia requires buyer_first_name + buyer_last_name
            $invoice['buyer_company'] = false;
            $firstName = $billingAddress->first_name ?? ($customer->first_name ?? '');
            $lastName = $billingAddress->last_name ?? ($customer->last_name ?? '');

            // If only a single name field is available, split it
            if (!$firstName && !$lastName && $billingAddress->name) {
                $parts = explode(' ', trim($billingAddress->name), 2);
                $firstName = $parts[0];
                $lastName = $parts[1] ?? $parts[0];
            }

            $invoice['buyer_first_name'] = $firstName ?: '-';
            $invoice['buyer_last_name'] = $lastName ?: '-';
        }

        // Buyer address
        if ($billingAddress) {
            if ($billingAddress->address_1) {
                $invoice['buyer_street'] = $billingAddress->address_1;
                if ($billingAddress->address_2) {
                    $invoice['buyer_street'] .= ' ' . $billingAddress->address_2;
                }
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
        if ($customer && $customer->email) {
            $invoice['buyer_email'] = $customer->email;
        }
        $phone = Arr::get($billingAddress->meta ?? [], 'other_data.phone', '');
        if ($phone) {
            $invoice['buyer_phone'] = substr($phone, 0, 16); // KSeF limit
        }

        // Positions (line items)
        $invoice['positions'] = $this->mapOrderItems($order);

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
                'name'              => substr($item->title ?: $item->post_title ?: __('Product', 'fchub-fakturownia'), 0, 256),
                'quantity'          => (int) $item->quantity,
                'quantity_unit'     => 'szt',
                'total_price_gross' => $this->centsToDecimal($item->line_total + $item->tax_amount),
            ];

            // Calculate tax rate from amounts
            $taxAmount = (float) $item->tax_amount;
            $subtotal = (float) $item->subtotal;

            if ($subtotal > 0 && $taxAmount > 0) {
                $taxRate = round(($taxAmount / $subtotal) * 100);
                // Map to standard Polish VAT rates
                $position['tax'] = $this->normalizeVatRate($taxRate);
            } elseif ($taxAmount == 0) {
                $position['tax'] = 'zw'; // exempt
            } else {
                $position['tax'] = 23; // default
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
                    : 'zw',
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
        if ($rate <= 0) {
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

        // KSeF status
        $govStatus = Arr::get($invoiceData, 'gov_status');
        if ($govStatus) {
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
