<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Event\EventClaimResult;
use FChubMemberships\Domain\Event\EventProcessingOutcome;
use FChubMemberships\Domain\Grant\AnchorDateCalculator;
use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Domain\Lifecycle\MembershipLifecycleCoordinator;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Logger;
use FChubMemberships\Support\Clock;
use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use FluentCart\Framework\Support\Arr;

class MembershipAccessIntegration extends BaseIntegrationManager
{
    protected $runOnBackgroundForProduct = false;
    protected $runOnBackgroundForGlobal = false;

    private Clock $clock;
    private ?AccessGrantService $accessGrantService;
    private \Closure $ownerTokenFactory;
    private ?MembershipLifecycleCoordinator $lifecycleCoordinator;

    public function __construct(
        ?Clock $clock = null,
        ?AccessGrantService $accessGrantService = null,
        ?callable $ownerTokenFactory = null,
        ?MembershipLifecycleCoordinator $lifecycleCoordinator = null
    ) {
        parent::__construct(
            'Memberships',
            'memberships',
            12
        );
        $this->clock = $clock ?? new Clock();
        $this->accessGrantService = $accessGrantService;
        $this->lifecycleCoordinator = $lifecycleCoordinator;
        $this->ownerTokenFactory = $ownerTokenFactory !== null
            ? \Closure::fromCallable($ownerTokenFactory)
            : static fn(): string => bin2hex(random_bytes(32));

        $this->description = __('Grant membership plan access when orders are paid. Supports lifetime, fixed duration, and subscription-mirrored validity.', 'fchub-memberships');
        $this->logo = FCHUB_MEMBERSHIPS_URL . 'assets/icons/memberships.svg';
        $this->category = 'membership';
        $this->scopes = ['global', 'product'];
        $this->hasGlobalMenu = true;
        $this->disableGlobalSettings = false;
    }

    /**
     * Always configured — no external API needed.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * API settings check — always active.
     */
    public function getApiSettings(): array
    {
        return ['status' => true];
    }

    /**
     * Default feed settings.
     */
    public function getIntegrationDefaults($settings): array
    {
        return [
            'enabled'       => 'yes',
            'name'          => __('Membership Access', 'fchub-memberships'),
            'event_trigger' => ['order_paid_done'],
        ];
    }

    /**
     * Settings fields for the integration feed form.
     */
    public function getSettingsFields($settings, $args = []): array
    {
        $fields = [
            [
                'key'         => 'name',
                'label'       => __('Feed Title', 'fchub-memberships'),
                'required'    => true,
                'placeholder' => __('Name', 'fchub-memberships'),
                'component'   => 'text',
                'inline_tip'  => __('Name of this feed for identification purposes.', 'fchub-memberships'),
            ],
            [
                'key'            => 'plan_id',
                'label'          => __('Membership Plan', 'fchub-memberships'),
                'required'       => true,
                'component'      => 'rest_selector',
                'option_key'     => 'plan_id',
                'is_multiple'    => false,
                'cacheable'      => true,
                'inline_tip'     => __('Select the membership plan to grant when this feed fires.', 'fchub-memberships'),
            ],
            [
                'key'       => 'validity_mode',
                'label'     => __('Validity Mode', 'fchub-memberships'),
                'required'  => true,
                'component' => 'radio_choice',
                'options'   => [
                    'lifetime'             => __('Lifetime (never expires)', 'fchub-memberships'),
                    'fixed_duration'       => __('Fixed Duration (X days)', 'fchub-memberships'),
                    'mirror_subscription'  => __('Mirror Subscription (expires with subscription)', 'fchub-memberships'),
                    'anchor_billing'       => __('Fixed Billing Anchor (monthly due date)', 'fchub-memberships'),
                ],
                'inline_tip' => __('How long the membership access should last.', 'fchub-memberships'),
            ],
            [
                'key'         => 'validity_days',
                'label'       => __('Validity Days', 'fchub-memberships'),
                'component'   => 'number',
                'placeholder' => '30',
                'inline_tip'  => __('Number of days the membership is valid. Only used with Fixed Duration mode.', 'fchub-memberships'),
                'dependency'  => [
                    'depends_on' => 'validity_mode',
                    'value'      => 'fixed_duration',
                    'operator'   => '=',
                ],
            ],
            [
                'key'         => 'billing_anchor_day',
                'label'       => __('Billing Anchor Day', 'fchub-memberships'),
                'component'   => 'number',
                'placeholder' => '20',
                'inline_tip'  => __('Day of the month (1-31) when payment is due. Access suspends if unpaid by this date. Short months clamp to the last valid day.', 'fchub-memberships'),
                'dependency'  => [
                    'depends_on' => 'validity_mode',
                    'value'      => 'anchor_billing',
                    'operator'   => '=',
                ],
            ],
            [
                'key'         => 'grace_period_days',
                'label'       => __('Grace Period (Days)', 'fchub-memberships'),
                'component'   => 'number',
                'placeholder' => '0',
                'inline_tip'  => __('Days to keep access after cancellation or failed renewal. 0 = no grace period.', 'fchub-memberships'),
            ],
            [
                'key'        => 'watch_on_access_revoke',
                'label'      => __('Enable Access Revocation', 'fchub-memberships'),
                'component'  => 'yes-no-checkbox',
                'checkbox_label' => __('Revoke access on cancel/refund events', 'fchub-memberships'),
                'inline_tip' => __('When enabled, membership access will be revoked when the associated order is cancelled or refunded.', 'fchub-memberships'),
            ],
            [
                'key'       => 'cancel_behavior',
                'label'     => __('Cancellation Behavior', 'fchub-memberships'),
                'component' => 'radio_choice',
                'options'   => [
                    'wait_validity' => __('Keep access until validity expires', 'fchub-memberships'),
                    'immediate'     => __('Revoke immediately', 'fchub-memberships'),
                ],
                'inline_tip' => __('What happens when a subscription is cancelled or a refund is issued.', 'fchub-memberships'),
            ],
            [
                'key'       => 'membership_term_mode',
                'label'     => __('Membership Term', 'fchub-memberships'),
                'component' => 'radio_choice',
                'options'   => [
                    'none'   => __('No limit (use plan default)', 'fchub-memberships'),
                    '1y'     => __('1 Year', 'fchub-memberships'),
                    '2y'     => __('2 Years', 'fchub-memberships'),
                    '3y'     => __('3 Years', 'fchub-memberships'),
                    'custom' => __('Custom', 'fchub-memberships'),
                ],
                'inline_tip' => __('Override the plan\'s membership term for this feed. Sets an absolute upper bound on membership duration.', 'fchub-memberships'),
            ],
            [
                'key'         => 'membership_term_value',
                'label'       => __('Term Length', 'fchub-memberships'),
                'component'   => 'number',
                'placeholder' => '6',
                'inline_tip'  => __('Number of units for the custom term length.', 'fchub-memberships'),
                'dependency'  => [
                    'depends_on' => 'membership_term_mode',
                    'value'      => 'custom',
                    'operator'   => '=',
                ],
            ],
            [
                'key'       => 'membership_term_unit',
                'label'     => __('Term Unit', 'fchub-memberships'),
                'component' => 'radio_choice',
                'options'   => [
                    'days'   => __('Days', 'fchub-memberships'),
                    'weeks'  => __('Weeks', 'fchub-memberships'),
                    'months' => __('Months', 'fchub-memberships'),
                    'years'  => __('Years', 'fchub-memberships'),
                ],
                'dependency' => [
                    'depends_on' => 'membership_term_mode',
                    'value'      => 'custom',
                    'operator'   => '=',
                ],
            ],
            [
                'key'        => 'auto_create_user',
                'label'      => __('Auto-Create User', 'fchub-memberships'),
                'component'  => 'yes-no-checkbox',
                'checkbox_label' => __('Create a WordPress user if one does not exist', 'fchub-memberships'),
                'inline_tip' => __('Automatically create a WordPress user from the order email when no account exists.', 'fchub-memberships'),
            ],
        ];

        $fields[] = $this->actionFields();

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => __('Memberships', 'fchub-memberships'),
        ];
    }

    /**
     * Process integration action — grant or revoke membership access.
     */
    public function processAction($order, $eventData): EventProcessingOutcome
    {
        $trigger = Arr::get($eventData, 'trigger');
        if (in_array($trigger, [
            'subscription_activated',
            'subscription_reactivated',
            'subscription_renewed',
            'subscription_canceled',
            'subscription_eot',
            'subscription_expired_validity',
        ], true)) {
            return EventProcessingOutcome::skipped();
        }

        $orderId = $this->positiveIdentifier(is_object($order) ? ($order->id ?? null) : null);
        $integrationId = $this->positiveIdentifier(Arr::get($eventData, 'integration_id'));
        $scope = Arr::get($eventData, 'scope');
        if ($orderId === null
            || $integrationId === null
            || !in_array($scope, ['product', 'global'], true)
            || !is_string($trigger)
            || trim($trigger) === ''
            || $this->characterLength($trigger) > 100
        ) {
            return EventProcessingOutcome::terminalFailure(
                'Membership event identifiers are invalid.',
                true
            );
        }

        try {
            $ownerToken = ($this->ownerTokenFactory)();
        } catch (\Throwable $exception) {
            $error = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Membership event owner token factory failed.';
            $description = sprintf(
                'Owner token factory failed (%s): %s',
                $exception::class,
                $error
            );
            Logger::orderLog(
                $order,
                __('Membership event owner token factory failed', 'fchub-memberships'),
                $description,
                'error'
            );
            Logger::error(
                __('Membership event owner token factory failed', 'fchub-memberships'),
                $description
            );

            return EventProcessingOutcome::retryableFailure($error, true);
        }
        if (!is_string($ownerToken)
            || trim($ownerToken) === ''
            || $this->characterLength($ownerToken) > 64
        ) {
            return EventProcessingOutcome::terminalFailure(
                'Membership event owner token is invalid.',
                true
            );
        }

        $isRevokeHook = Arr::get($eventData, 'is_revoke_hook') === 'yes';
        $mode = $isRevokeHook ? 'revoke' : 'grant';
        $service = $this->accessGrantService();
        $eventHash = $service->orderEventHash($orderId, $scope, $integrationId, $trigger, $mode);

        try {
            $claim = $service->claimOrderEvent(
                $orderId,
                $scope,
                $integrationId,
                $trigger,
                $mode,
                $ownerToken
            );
        } catch (\Throwable $exception) {
            Logger::orderLog(
                $order,
                __('Membership event claim failed', 'fchub-memberships'),
                $exception->getMessage(),
                'error'
            );
            Logger::error(
                __('Membership event claim failed', 'fchub-memberships'),
                $exception->getMessage(),
                ['event_hash' => $eventHash]
            );

            return EventProcessingOutcome::retryableFailure($exception->getMessage(), true);
        }

        if ($claim->outcome === EventClaimResult::DUPLICATE_SUCCEEDED) {
            Logger::orderLog(
                $order,
                __('Membership event skipped', 'fchub-memberships'),
                __('This membership event was already processed.', 'fchub-memberships')
            );

            return EventProcessingOutcome::skipped();
        }
        if ($claim->outcome === EventClaimResult::IN_PROGRESS) {
            Logger::orderLog(
                $order,
                __('Membership event already processing', 'fchub-memberships'),
                __('Another worker currently owns this membership event.', 'fchub-memberships'),
                'warning'
            );

            return EventProcessingOutcome::retryableFailure('Membership event is already processing.', true);
        }
        if ($claim->outcome === EventClaimResult::RETRYABLE_FAILED) {
            return EventProcessingOutcome::retryableFailure('Membership event retry is not due yet.', true);
        }
        if ($claim->outcome !== EventClaimResult::ACQUIRED) {
            return EventProcessingOutcome::terminalFailure('Membership event previously failed terminally.', true);
        }

        try {
            $outcome = $isRevokeHook
                ? $this->handleRevoke($order, $eventData)
                : $this->handleGrant($order, $eventData);
        } catch (\Throwable $exception) {
            Logger::orderLog(
                $order,
                __('Membership event processing failed', 'fchub-memberships'),
                $exception->getMessage(),
                'error'
            );
            $outcome = EventProcessingOutcome::retryableFailure($exception->getMessage());
        }

        return $this->finalizeClaim($order, $eventHash, $ownerToken, $outcome);
    }

    /**
     * Grant membership plan access to the order's user.
     *
     * Delegates to AccessGrantService::grantPlan() to ensure all lifecycle hooks,
     * audit logging, adapter calls, emails, trial detection, and multi-membership
     * mode enforcement are applied consistently.
     */
    private function handleGrant($order, array $eventData): EventProcessingOutcome
    {
        $settings = Arr::get($eventData, 'feed', []);
        $planId = (int) Arr::get($settings, 'plan_id', 0);

        if (!$planId) {
            Logger::orderLog($order, __('Membership grant skipped', 'fchub-memberships'), __('No plan ID configured in feed.', 'fchub-memberships'), 'warning');
            return EventProcessingOutcome::terminalFailure('No plan ID configured in feed.');
        }

        $planRepo = new PlanRepository();
        $plan = $planRepo->find($planId);

        if (!$plan) {
            /* translators: Placeholder values are runtime membership details included in this message. */
            Logger::orderLog($order, __('Membership grant failed', 'fchub-memberships'), sprintf(__('Plan #%d not found.', 'fchub-memberships'), $planId), 'error');
            return EventProcessingOutcome::terminalFailure("Plan #{$planId} not found.");
        }

        $userId = $this->resolveUserId($order, $settings);

        if (!$userId) {
            Logger::orderLog($order, __('Membership grant failed', 'fchub-memberships'), __('No user found and auto-create is disabled.', 'fchub-memberships'), 'error');
            return EventProcessingOutcome::terminalFailure('No user found and auto-create is disabled.');
        }

        $validityMode = Arr::get($settings, 'validity_mode', 'lifetime');
        $validityPolicy = $this->resolveValidityPolicy($validityMode, $settings, $plan);
        $expiresAt = $this->calculateExpiresAt($validityPolicy, $order);
        $feedId = (int) Arr::get($eventData, 'integration_id', 0);
        $graceDays = (int) Arr::get($settings, 'grace_period_days', $plan['grace_period_days'] ?? 0);

        // Detect subscription from event data to set correct source_type
        $subscription = Arr::get($eventData, 'event_data.subscription');
        $sourceType = $subscription ? 'subscription' : 'order';
        $sourceId = $subscription ? $subscription->id : $order->id;

        $context = [
            'source_type'      => $sourceType,
            'source_id'        => $sourceId,
            'feed_id'          => $feedId,
            'feed_scope'       => (string) Arr::get($eventData, 'scope'),
            'origin_event'     => $this->providerOriginEvent($order, $eventData, 'grant'),
            'order'            => $order,
            'grace_period_days' => $graceDays,
            'policy' => array_merge([
                'cancel_behavior' => Arr::get($settings, 'cancel_behavior') === 'immediate'
                    ? 'immediate'
                    : 'wait_validity',
                'grace_period_days' => max(0, $graceDays),
            ], $validityPolicy),
        ];

        if ($subscription) {
            $subscriptionId = $this->positiveIdentifier($subscription->id ?? null);
            if ($subscriptionId !== null) {
                $context['policy']['subscription_id'] = $subscriptionId;
            }
        }

        if ($expiresAt) {
            $context['expires_at'] = $expiresAt;
        }

        // Inject billing_anchor_day into grant meta for fixed_anchor plans
        $planDurationType = $plan['duration_type'] ?? 'lifetime';
        if ($planDurationType === 'fixed_anchor') {
            $planMeta = $plan['meta'] ?? [];
            $context['meta'] = array_merge($context['meta'] ?? [], [
                'billing_anchor_day' => (int) ($planMeta['billing_anchor_day'] ?? 1),
            ]);
        } elseif ($validityMode === 'anchor_billing') {
            $context['meta'] = array_merge($context['meta'] ?? [], [
                'billing_anchor_day' => (int) Arr::get($settings, 'billing_anchor_day', 1),
            ]);
        }

        // Feed-level membership term override
        $feedTermMode = Arr::get($settings, 'membership_term_mode', 'none');
        if ($feedTermMode !== 'none') {
            $feedTermConfig = ['mode' => $feedTermMode];
            if ($feedTermMode === 'custom') {
                $feedTermConfig['value'] = (int) Arr::get($settings, 'membership_term_value', 1);
                $feedTermConfig['unit'] = Arr::get($settings, 'membership_term_unit', 'months');
            } elseif ($feedTermMode === 'date') {
                $feedTermConfig['date'] = Arr::get($settings, 'membership_term_date');
            }
            $termEndsAt = MembershipTermCalculator::calculateEndDate(
                $feedTermConfig,
                $this->clock->storage($this->clock->now()),
                $this->clock
            );
            if ($termEndsAt) {
                $context['meta'] = array_merge($context['meta'] ?? [], [
                    'membership_term_ends_at' => $termEndsAt,
                ]);
                if (empty($context['expires_at'])) {
                    $context['expires_at'] = $termEndsAt;
                } else {
                    $context['expires_at'] = MembershipTermCalculator::capExpiry($context['expires_at'], $termEndsAt);
                }
            }
        }

        if (isset($context['meta']['billing_anchor_day'])) {
            $context['policy']['billing_anchor_day'] = (int) $context['meta']['billing_anchor_day'];
        }
        if (isset($context['meta']['membership_term_ends_at'])) {
            $context['policy']['membership_term_ends_at'] = (string) $context['meta']['membership_term_ends_at'];
        }

        $result = $this->lifecycleCoordinator()->paid($userId, $planId, $context);

        $failed = (int) ($result['failed'] ?? 0);
        $pending = (int) ($result['pending'] ?? 0);
        if (!empty($result['blocked'])
            && ($result['reason'] ?? null) === 'downgrade_blocked'
            && $failed === 0
        ) {
            return EventProcessingOutcome::skipped();
        }
        $unsuccessful = $failed > 0
            || $pending > 0
            || (array_key_exists('success', $result) && $result['success'] === false)
            || !empty($result['blocked']);
        if ($unsuccessful && $failed === 0 && $pending === 0) {
            $result['failed'] = 1;
        }

        self::logGrantResult(
            $order,
            $result,
            (string) $plan['title'],
            $userId,
            $expiresAt,
            $sourceType,
            (int) $sourceId
        );

        if ($unsuccessful) {
            return EventProcessingOutcome::retryableFailure(
                self::outcomeError(
                    $result,
                    $pending > 0 ? 'Membership grant is pending recovery.' : 'Membership grant failed.'
                )
            );
        }

        return EventProcessingOutcome::succeeded();
    }

    /**
     * Revoke membership access.
     *
     * Delegates to AccessGrantService::revokePlan() to ensure all lifecycle hooks,
     * audit logging, adapter calls, and emails are applied consistently.
     */
    private function handleRevoke($order, array $eventData): EventProcessingOutcome
    {
        $settings = Arr::get($eventData, 'feed', []);

        $planId = (int) Arr::get($settings, 'plan_id', 0);
        if ($planId <= 0) {
            Logger::orderLog($order, __('Membership revoke failed', 'fchub-memberships'), __('No plan ID configured in feed.', 'fchub-memberships'), 'error');
            return EventProcessingOutcome::terminalFailure('No plan ID configured in feed.');
        }

        $userId = $this->resolveUserId($order, $settings, false);
        if ($userId === null) {
            Logger::orderLog($order, __('Membership revoke skipped', 'fchub-memberships'), __('No existing user found for this order.', 'fchub-memberships'), 'warning');
            return EventProcessingOutcome::skipped();
        }

        $subscription = Arr::get($eventData, 'event_data.subscription');
        $sourceId = $subscription ? $subscription->id : $order->id;
        $sourceType = $subscription ? 'subscription' : 'order';
        $result = $this->lifecycleCoordinator()->refund($userId, $planId, [
            'source_type' => $sourceType,
            'source_id' => (int) $sourceId,
            'feed_id' => (int) Arr::get($eventData, 'integration_id'),
            'feed_scope' => (string) Arr::get($eventData, 'scope'),
            'origin_event' => $this->providerOriginEvent($order, $eventData, 'revoke'),
            'reason' => sprintf('Order #%d revoked/refunded', $order->id),
            'order' => $order,
        ]);
        $totalRevoked = (int) ($result['revoked'] ?? 0);
        $totalGraceStarted = (int) ($result['grace_started'] ?? 0);
        $totalPending = (int) ($result['pending'] ?? 0);
        $totalFailed = (int) ($result['failed'] ?? 0);
        $errors = $result['errors'] ?? [];
        $unsuccessful = array_key_exists('success', $result) && $result['success'] === false;

        $logFailed = $totalFailed;
        if ($unsuccessful && $logFailed === 0 && $totalPending === 0) {
            $logFailed = 1;
        }

        self::logRevokeResult(
            $order,
            (int) $order->id,
            $totalRevoked,
            $logFailed,
            $errors,
            $totalGraceStarted,
            $totalPending
        );

        if ($logFailed > 0 || $totalPending > 0) {
            return EventProcessingOutcome::retryableFailure(
                self::outcomeError(
                    ['errors' => $errors],
                    $totalPending > 0 ? 'Membership revoke is pending recovery.' : 'Membership revoke failed.'
                )
            );
        }
        if ($totalRevoked === 0 && $totalGraceStarted === 0) {
            return EventProcessingOutcome::skipped();
        }

        return EventProcessingOutcome::succeeded();
    }

    private function finalizeClaim(
        $order,
        string $eventHash,
        string $ownerToken,
        EventProcessingOutcome $outcome
    ): EventProcessingOutcome {
        $service = $this->accessGrantService();
        if ($outcome->success) {
            $completionException = null;
            try {
                $completed = $service->succeedEventLock($eventHash, $ownerToken);
            } catch (\Throwable $exception) {
                $completed = false;
                $completionException = $exception;
            }
            if ($completed) {
                return $outcome;
            }

            $error = 'Membership event lock completion failed after completed effects.';
            $fallbackFailed = false;
            $fallbackException = null;
            try {
                $fallbackFailed = !$service->failEventLock($eventHash, $ownerToken, $error, false);
            } catch (\Throwable $exception) {
                $fallbackFailed = true;
                $fallbackException = $exception;
            }

            $description = $completionException === null
                ? $error
                : $this->transitionFailureDescription('succeedEventLock', $error, $completionException);

            Logger::orderLog(
                $order,
                __('Membership event lock completion failed', 'fchub-memberships'),
                $description,
                'error'
            );
            Logger::error(
                __('Membership event lock completion failed', 'fchub-memberships'),
                $description,
                ['event_hash' => $eventHash]
            );
            if ($fallbackFailed) {
                $this->logTransitionFailure(
                    $order,
                    $eventHash,
                    $error,
                    'failEventLock',
                    $fallbackException
                );
            }

            return EventProcessingOutcome::terminalFailure($error, $outcome->skipped);
        }

        $error = $outcome->error ?? 'Membership event processing failed.';
        $transitioned = false;
        $transitionException = null;
        try {
            $transitioned = $service->failEventLock(
                $eventHash,
                $ownerToken,
                $error,
                $outcome->retryable
            );
        } catch (\Throwable $exception) {
            $transitioned = false;
            $transitionException = $exception;
        }
        if (!$transitioned) {
            $this->logTransitionFailure(
                $order,
                $eventHash,
                $error,
                'failEventLock',
                $transitionException
            );
        }

        return $outcome;
    }

    private function logTransitionFailure(
        $order,
        string $eventHash,
        string $originalError,
        string $stage,
        ?\Throwable $exception = null
    ): void {
        $description = $this->transitionFailureDescription($stage, $originalError, $exception);
        Logger::orderLog(
            $order,
            __('Membership event lock transition failed', 'fchub-memberships'),
            $description,
            'error'
        );
        Logger::error(
            __('Membership event lock transition failed', 'fchub-memberships'),
            $description,
            ['event_hash' => $eventHash]
        );
    }

    private function transitionFailureDescription(
        string $stage,
        string $originalError,
        ?\Throwable $exception
    ): string {
        $description = sprintf(
            'The event lock transition failed at %s after: %s',
            $stage,
            $originalError
        );
        if ($exception === null) {
            return $description;
        }

        return sprintf(
            '%s Throwable: %s: %s',
            $description,
            $exception::class,
            $exception->getMessage()
        );
    }

    private function accessGrantService(): AccessGrantService
    {
        return $this->accessGrantService ??= new AccessGrantService();
    }

    private function lifecycleCoordinator(): MembershipLifecycleCoordinator
    {
        return $this->lifecycleCoordinator ??= new MembershipLifecycleCoordinator(
            $this->accessGrantService(),
            null,
            null,
            $this->clock
        );
    }

    private function providerOriginEvent(object $order, array $eventData, string $mode): string
    {
        $identity = [
            'order_id' => (int) ($order->id ?? 0),
            'scope' => (string) Arr::get($eventData, 'scope'),
            'integration_id' => (int) Arr::get($eventData, 'integration_id'),
            'trigger' => (string) Arr::get($eventData, 'trigger'),
            'mode' => $mode,
        ];

        return 'membership:' . $mode . ':' . hash('sha256', wp_json_encode($identity));
    }

    private function positiveIdentifier(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || $value === '' || !ctype_digit($value)) {
            return null;
        }

        $normalised = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        if ($normalised === ''
            || strlen($normalised) > strlen($maximum)
            || (strlen($normalised) === strlen($maximum) && strcmp($normalised, $maximum) > 0)
        ) {
            return null;
        }

        return (int) $normalised;
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $length = preg_match_all('/./us', $value);

        return $length === false ? strlen($value) : $length;
    }

    private static function outcomeError(array $result, string $fallback): string
    {
        $messages = [];
        foreach ($result['errors'] ?? [] as $error) {
            if (is_string($error) && $error !== '') {
                $messages[] = $error;
            } elseif (is_array($error) && !empty($error['message'])) {
                $messages[] = (string) $error['message'];
            }
        }
        if ($messages !== []) {
            return implode('; ', $messages);
        }
        if (!empty($result['reason'])) {
            return (string) $result['reason'];
        }

        return $fallback;
    }

    public static function logGrantResult(
        $order,
        array $result,
        string $planTitle,
        int $userId,
        ?string $expiresAt,
        string $sourceType,
        int $sourceId
    ): void {
        $created = (int) ($result['created'] ?? 0);
        $updated = (int) ($result['updated'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $pending = (int) ($result['pending'] ?? 0);
        $succeeded = $created + $updated;

        if ($pending > 0) {
            Logger::orderLog(
                $order,
                __('Membership access grant pending', 'fchub-memberships'),
                sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('Plan "%1$s" for user #%2$d: %3$d resources are pending provider recovery.', 'fchub-memberships'),
                    $planTitle,
                    $userId,
                    $pending
                ),
                'warning'
            );
            return;
        }

        if ($failed > 0) {
            $partial = $succeeded > 0;
            Logger::orderLog(
                $order,
                $partial
                    ? __('Membership access partially granted', 'fchub-memberships')
                    : __('Membership access grant failed', 'fchub-memberships'),
                sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('Plan "%1$s" for user #%2$d: %3$d resources applied, %4$d failed. %5$s', 'fchub-memberships'),
                    $planTitle,
                    $userId,
                    $succeeded,
                    $failed,
                    self::resultErrors($result['errors'] ?? [])
                ),
                $partial ? 'warning' : 'error'
            );
            return;
        }

        Logger::orderLog(
            $order,
            __('Membership access granted', 'fchub-memberships'),
            sprintf(
                /* translators: Placeholder values are runtime membership details included in this message. */
                __('Plan "%1$s" granted to user #%2$d (%3$d created, %4$d updated, validity: %5$s, source: %6$s #%7$d).', 'fchub-memberships'),
                $planTitle,
                $userId,
                $created,
                $updated,
                $expiresAt ?? __('lifetime', 'fchub-memberships'),
                $sourceType,
                $sourceId
            )
        );
    }

    public static function logRevokeResult(
        $order,
        int $orderId,
        int $revoked,
        int $failed,
        array $errors = [],
        int $graceStarted = 0,
        int $pending = 0
    ): void {
        if ($pending > 0) {
            Logger::orderLog(
                $order,
                __('Membership access revocation pending', 'fchub-memberships'),
                sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('Order #%1$d: %2$d provider revocations are pending recovery.', 'fchub-memberships'),
                    $orderId,
                    $pending
                ),
                'warning'
            );
            return;
        }
        if ($failed > 0) {
            $partial = $revoked > 0 || $graceStarted > 0;
            Logger::orderLog(
                $order,
                $partial
                    ? ($graceStarted > 0
                        ? __('Membership access revocation partially processed', 'fchub-memberships')
                        : __('Membership access partially revoked', 'fchub-memberships'))
                    : __('Membership access revoke failed', 'fchub-memberships'),
                sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('Order #%1$d: %2$d grants revoked, %3$d scheduled after grace, %4$d failed. %5$s', 'fchub-memberships'),
                    $orderId,
                    $revoked,
                    $graceStarted,
                    $failed,
                    self::resultErrors($errors)
                ),
                $partial ? 'warning' : 'error'
            );
            return;
        }

        if ($graceStarted > 0) {
            Logger::orderLog(
                $order,
                $revoked > 0
                    ? __('Membership access revocation processed', 'fchub-memberships')
                    : __('Membership access revocation scheduled', 'fchub-memberships'),
                $revoked > 0
                    ? sprintf(
                        /* translators: Placeholder values are runtime membership details included in this message. */
                        __('Order #%1$d: %2$d revoked, %3$d scheduled after grace.', 'fchub-memberships'),
                        $orderId,
                        $revoked,
                        $graceStarted
                    )
                    : sprintf(
                        /* translators: Placeholder values are runtime membership details included in this message. */
                        __('%1$d grant(s) scheduled for revocation after grace for order #%2$d.', 'fchub-memberships'),
                        $graceStarted,
                        $orderId
                    )
            );
            return;
        }

        Logger::orderLog(
            $order,
            __('Membership access revoked', 'fchub-memberships'),
            /* translators: Placeholder values are runtime membership details included in this message. */
            sprintf(__('%1$d grant(s) revoked for order #%2$d.', 'fchub-memberships'), $revoked, $orderId)
        );
    }

    private static function resultErrors(array $errors): string
    {
        $messages = [];
        foreach ($errors as $error) {
            if (is_string($error) && $error !== '') {
                $messages[] = $error;
            } elseif (is_array($error) && !empty($error['message'])) {
                $messages[] = (string) $error['message'];
            }
        }

        return $messages ? implode('; ', $messages) : __('No provider error details were returned.', 'fchub-memberships');
    }

    /**
     * Calculate the expiration date based on validity mode.
     * Plan data is the primary source of truth for duration configuration.
     */
    private function calculateExpiresAt(array $validityPolicy, $order): ?string
    {
        switch ($validityPolicy['validity_mode']) {
            case 'fixed_duration':
                $days = (int) $validityPolicy['validity_days'];
                return $this->clock->storage($this->clock->plusDays(max(1, $days)));

            case 'mirror_subscription':
                return $this->getSubscriptionNextBillingDate($order);

            case 'anchor_billing':
                $anchorDay = (int) $validityPolicy['billing_anchor_day'];
                return AnchorDateCalculator::nextAnchorDate(
                    $anchorDay,
                    $this->clock->storage($this->clock->now()),
                    $this->clock
                );

            case 'lifetime':
            default:
                return null;
        }
    }

    private function resolveValidityPolicy(string $validityMode, array $settings, array $plan): array
    {
        $durationType = (string) ($plan['duration_type'] ?? 'lifetime');
        if ($durationType === 'fixed_days') {
            return [
                'validity_mode' => 'fixed_duration',
                'validity_days' => max(1, (int) ($plan['duration_days'] ?? 1)),
            ];
        }
        if ($durationType === 'fixed_anchor') {
            $meta = is_array($plan['meta'] ?? null) ? $plan['meta'] : [];
            return [
                'validity_mode' => 'anchor_billing',
                'billing_anchor_day' => min(31, max(1, (int) ($meta['billing_anchor_day'] ?? 1))),
            ];
        }
        if ($durationType === 'lifetime') {
            return ['validity_mode' => 'lifetime'];
        }

        return match ($validityMode) {
            'fixed_duration' => [
                'validity_mode' => 'fixed_duration',
                'validity_days' => max(1, (int) Arr::get($settings, 'validity_days', 30)),
            ],
            'mirror_subscription' => ['validity_mode' => 'mirror_subscription'],
            'anchor_billing' => [
                'validity_mode' => 'anchor_billing',
                'billing_anchor_day' => min(31, max(1, (int) Arr::get($settings, 'billing_anchor_day', 1))),
            ],
            default => ['validity_mode' => 'lifetime'],
        };
    }

    /**
     * Get the next billing date from the order's subscription.
     */
    private function getSubscriptionNextBillingDate($order): ?string
    {
        if (!method_exists($order, 'subscriptions') && !property_exists($order, 'subscriptions')) {
            return null;
        }

        $subscriptions = is_callable([$order, 'subscriptions'])
            ? $order->subscriptions()->get()
            : ($order->subscriptions ?? []);

        foreach ($subscriptions as $subscription) {
            $nextBilling = is_array($subscription)
                ? Arr::get($subscription, 'next_billing_date')
                : ($subscription->next_billing_date ?? null);

            if ($nextBilling) {
                return $nextBilling;
            }
        }

        return null;
    }

    /**
     * Resolve the WordPress user ID from the order, optionally creating one.
     */
    private function resolveUserId($order, array $settings, bool $allowCreate = true): ?int
    {
        $userId = $order->user_id ?? null;

        if ($userId) {
            return (int) $userId;
        }

        // Try to find by email
        $email = $order->customer_email ?? ($order->customer->email ?? null);
        if ($email) {
            $user = get_user_by('email', $email);
            if ($user) {
                return $user->ID;
            }
        }

        // Auto-create user if enabled
        $autoCreate = Arr::get($settings, 'auto_create_user', 'yes');
        if (!$allowCreate || $autoCreate !== 'yes' || empty($email)) {
            return null;
        }

        $username = sanitize_user(current(explode('@', $email)), true);
        if (username_exists($username)) {
            $username .= '_' . wp_rand(100, 999);
        }

        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => wp_generate_password(16),
            'first_name' => $order->customer_first_name ?? '',
            'last_name'  => $order->customer_last_name ?? '',
            'role'       => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            Logger::error(
                __('Failed to create user', 'fchub-memberships'),
                $userId->get_error_message()
            );
            return null;
        }

        return (int) $userId;
    }

}
