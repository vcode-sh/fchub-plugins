<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class CreateFluentCartCouponAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fchub_create_fluentcart_coupon';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'title'       => __('Create FluentCart Coupon', 'fchub-memberships'),
            'description' => __('Generate a unique single-use FluentCart coupon for the contact', 'fchub-memberships'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'coupon_type'    => 'percentage',
                'amount'         => '',
                'expiry_days'    => '7',
                'prefix'         => 'RENEW',
                'max_uses'       => '1',
                'is_recurring'   => 'no',
                'included_products'  => [],
                'excluded_products'  => [],
                'min_purchase_amount' => '',
            ],
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Create FluentCart Coupon', 'fchub-memberships'),
            'sub_title' => __('Generate a unique coupon code for the contact', 'fchub-memberships'),
            'fields'    => [
                'coupon_type'    => [
                    'type'    => 'radio',
                    'label'   => __('Discount Type', 'fchub-memberships'),
                    'options' => [
                        ['id' => 'percentage', 'title' => __('Percentage', 'fchub-memberships')],
                        ['id' => 'fixed', 'title' => __('Fixed Amount', 'fchub-memberships')],
                    ],
                ],
                'amount'         => [
                    'type'        => 'input-number',
                    'label'       => __('Discount Amount', 'fchub-memberships'),
                    'placeholder' => __('e.g. 20', 'fchub-memberships'),
                    'is_required' => true,
                    'inline_help' => __('Percentage (0-100) or fixed amount depending on type selected above.', 'fchub-memberships'),
                ],
                'expiry_days'    => [
                    'type'        => 'input-number',
                    'label'       => __('Coupon Valid For (Days)', 'fchub-memberships'),
                    'placeholder' => __('7', 'fchub-memberships'),
                    'inline_help' => __('Number of days the coupon will be valid. Leave blank for no expiry.', 'fchub-memberships'),
                ],
                'prefix'         => [
                    'type'        => 'input-text',
                    'label'       => __('Code Prefix', 'fchub-memberships'),
                    'placeholder' => __('RENEW', 'fchub-memberships'),
                    'inline_help' => __('Prefix for the generated coupon code (e.g. RENEW-XXXX).', 'fchub-memberships'),
                ],
                'max_uses'       => [
                    'type'        => 'input-number',
                    'label'       => __('Max Uses', 'fchub-memberships'),
                    'placeholder' => __('1', 'fchub-memberships'),
                    'inline_help' => __('Maximum number of times this coupon can be used. Default: 1 (single-use).', 'fchub-memberships'),
                ],
                'is_recurring'   => [
                    'type'    => 'radio',
                    'label'   => __('Apply to Recurring Payments', 'fchub-memberships'),
                    'options' => [
                        ['id' => 'no', 'title' => __('First payment only', 'fchub-memberships')],
                        ['id' => 'yes', 'title' => __('All recurring payments', 'fchub-memberships')],
                    ],
                ],
                'min_purchase_amount' => [
                    'type'        => 'input-number',
                    'label'       => __('Minimum Purchase Amount', 'fchub-memberships'),
                    'placeholder' => __('Leave blank for no minimum', 'fchub-memberships'),
                    'inline_help' => __('Optional minimum order amount required to use this coupon.', 'fchub-memberships'),
                ],
            ],
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        if (!class_exists('\FluentCart\App\Models\Coupon')) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $settings = $sequence->settings;
        $amount = Arr::get($settings, 'amount');

        if (!$amount || $amount <= 0) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $couponType = Arr::get($settings, 'coupon_type', 'percentage');
        $prefix = Arr::get($settings, 'prefix', 'RENEW');
        $expiryDays = Arr::get($settings, 'expiry_days', '');
        $maxUses = max(1, (int) Arr::get($settings, 'max_uses', 1));
        $isRecurring = Arr::get($settings, 'is_recurring', 'no');
        $minPurchase = Arr::get($settings, 'min_purchase_amount', '');

        // Generate unique code
        $code = $this->generateUniqueCode($prefix);

        // Build conditions
        $conditions = [
            'max_uses'        => $maxUses,
            'max_per_customer' => 1,
            'is_recurring'    => $isRecurring,
        ];

        if ($minPurchase !== '' && $minPurchase > 0) {
            $conditions['min_purchase_amount'] = (float) $minPurchase;
        }

        // Restrict to this contact's email
        if ($subscriber->email) {
            $conditions['email_restrictions'] = [$subscriber->email];
        }

        // Build coupon data
        $couponData = [
            'title'      => sprintf(
                __('Auto: %s%% off for %s', 'fchub-memberships'),
                $couponType === 'percentage' ? $amount : $amount,
                $subscriber->email ?: $subscriber->first_name
            ),
            'code'       => $code,
            'type'       => $couponType,
            'amount'     => (float) $amount,
            'status'     => 'active',
            'stackable'  => 'no',
            'priority'   => 0,
            'show_on_checkout' => 'no',
            'conditions' => $conditions,
        ];

        // Set date range
        $couponData['start_date'] = gmdate('Y-m-d H:i:s');
        if ($expiryDays !== '' && (int) $expiryDays > 0) {
            $couponData['end_date'] = gmdate('Y-m-d H:i:s', strtotime('+' . (int) $expiryDays . ' days'));
        }

        // Use CouponResource if available (handles amount-to-cents conversion)
        if (class_exists('\FluentCart\Api\Resource\CouponResource')) {
            $result = \FluentCart\Api\Resource\CouponResource::create($couponData);
        } else {
            // Fallback: manual creation with amount conversion
            $createData = $couponData;
            if ($couponType !== 'percentage' && class_exists('\FluentCart\App\Helpers\Helper')) {
                $createData['amount'] = \FluentCart\App\Helpers\Helper::toCent($createData['amount']);
                if (!empty($createData['conditions']['min_purchase_amount'])) {
                    $createData['conditions']['min_purchase_amount'] = \FluentCart\App\Helpers\Helper::toCent(
                        $createData['conditions']['min_purchase_amount']
                    );
                }
            }
            \FluentCart\App\Models\Coupon::query()->create($createData);
        }

        // Store the coupon code in subscriber meta for smart code access
        $subscriber->updateMeta('_fchub_last_coupon_code', $code);
        $subscriber->updateMeta('_fchub_last_coupon_amount', $amount);
        $subscriber->updateMeta('_fchub_last_coupon_type', $couponType);

        if ($expiryDays !== '' && (int) $expiryDays > 0) {
            $subscriber->updateMeta('_fchub_last_coupon_expires', gmdate('Y-m-d', strtotime('+' . (int) $expiryDays . ' days')));
        }

        do_action('fluent_cart/coupon_created', ['data' => $couponData]);
    }

    private function generateUniqueCode(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix));
        $maxAttempts = 10;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $random = strtoupper(wp_generate_password(6, false, false));
            $code = $prefix ? "{$prefix}-{$random}" : $random;

            if (!class_exists('\FluentCart\App\Models\Coupon')) {
                return $code;
            }

            $exists = \FluentCart\App\Models\Coupon::query()->where('code', $code)->first();
            if (!$exists) {
                return $code;
            }
        }

        // Fallback: include timestamp for uniqueness
        $random = strtoupper(wp_generate_password(4, false, false));
        return $prefix . '-' . dechex(time()) . '-' . $random;
    }
}
