<?php

namespace FChubFakturownia\Integration;

defined('ABSPATH') || exit;

use FChubFakturownia\API\FakturowniaAPI;
use FChubFakturownia\Handler\InvoiceHandler;
use FChubFakturownia\Handler\RefundHandler;
use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use FluentCart\Framework\Support\Arr;

class FakturowniaIntegration extends BaseIntegrationManager
{
    protected $runOnBackgroundForProduct = false;
    protected $runOnBackgroundForGlobal = false;

    public function __construct()
    {
        parent::__construct(
            'Fakturownia',
            'fakturownia',
            11
        );

        $this->description = __('Automatically create invoices in Fakturownia with KSeF 2.0 support when orders are paid.', 'fchub-fakturownia');
        $this->logo = FCHUB_FAKTUROWNIA_URL . 'assets/fakturownia.webp';
        $this->category = 'invoicing';
        $this->scopes = ['global'];
        $this->hasGlobalMenu = true;
        $this->disableGlobalSettings = false;
    }

    /**
     * Check if integration is properly configured
     */
    public function isConfigured(): bool
    {
        return FakturowniaSettings::isConfigured();
    }

    /**
     * Get API settings for the integration check
     */
    public function getApiSettings(): array
    {
        $settings = FakturowniaSettings::getSettings();
        $token = $settings['api_token'] ?? '';

        return [
            'status'    => !empty($settings['domain']) && !empty($token) && !empty($settings['status']),
            'api_key'   => $token ? '****' . substr($token, -4) : '',
        ];
    }

    /**
     * Default feed settings
     */
    public function getIntegrationDefaults($settings): array
    {
        return [
            'enabled'       => 'yes',
            'name'          => __('Fakturownia Invoice', 'fchub-fakturownia'),
            'event_trigger' => ['order_paid_done'],
            'note'          => '',
        ];
    }

    /**
     * Settings fields for the integration feed
     */
    public function getSettingsFields($settings, $args = []): array
    {
        $fields = [
            'name' => [
                'key'         => 'name',
                'label'       => __('Feed Title', 'fchub-fakturownia'),
                'required'    => true,
                'placeholder' => __('Name', 'fchub-fakturownia'),
                'component'   => 'text',
                'inline_tip'  => __('Name of this feed for identification purposes.', 'fchub-fakturownia'),
            ],
            'note' => [
                'key'        => 'note',
                'label'      => __('Invoice Note', 'fchub-fakturownia'),
                'inline_tip' => __('Optional note to add to the invoice description. Supports smart codes.', 'fchub-fakturownia'),
                'component'  => 'value_textarea',
            ],
        ];

        $fields = array_values($fields);
        $fields[] = $this->actionFields();

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => __('Fakturownia', 'fchub-fakturownia'),
        ];
    }

    /**
     * Process integration action - create invoice or handle refund
     */
    public function processAction($order, $eventData): void
    {
        $trigger = Arr::get($eventData, 'trigger', '');
        $isRevokeHook = Arr::get($eventData, 'is_revoke_hook') === 'yes';

        $api = $this->getApiClient();

        if ($isRevokeHook || $trigger === 'order_fully_refunded') {
            $this->handleRefund($order, $api);
            return;
        }

        // Partial refunds can't produce automatic correction invoices — warn the admin
        if ($trigger === 'order_partially_refunded') {
            $handler = new RefundHandler($api);
            $handler->handlePartialRefund($order);
            return;
        }

        $this->handleInvoiceCreation($order, $api, $eventData);
    }

    /**
     * Handle invoice creation for paid orders
     */
    private function handleInvoiceCreation($order, FakturowniaAPI $api, array $eventData): void
    {
        $handler = new InvoiceHandler($api);
        $note = Arr::get($eventData, 'feed.note', '');
        $result = $handler->createInvoice($order, $note);

        if (isset($result['error'])) {
            return; // Error already logged by handler
        }

        // Schedule KSeF status polling — skip proformas (not KSeF-eligible)
        $isProforma = FakturowniaSettings::getInvoiceKind() === 'proforma';
        if (!$isProforma && FakturowniaSettings::isKsefAutoSend() && isset($result['id'])) {
            wp_schedule_single_event(
                time() + 60,
                'fchub_fakturownia_check_ksef_status',
                [$order->id, $result['id']]
            );
        }
    }

    /**
     * Handle refund - create correction invoice
     */
    private function handleRefund($order, FakturowniaAPI $api): void
    {
        $handler = new RefundHandler($api);
        $result = $handler->createCorrectionInvoice($order);

        // Schedule KSeF status polling for the correction invoice
        if (!isset($result['error']) && FakturowniaSettings::isKsefAutoSend() && isset($result['id'])) {
            wp_schedule_single_event(
                time() + 60,
                'fchub_fakturownia_check_ksef_status',
                [$order->id, $result['id']]
            );
        }
    }

    /**
     * Create API client from settings
     */
    private function getApiClient(): FakturowniaAPI
    {
        return new FakturowniaAPI(
            FakturowniaSettings::getDomain(),
            FakturowniaSettings::getApiToken()
        );
    }
}
