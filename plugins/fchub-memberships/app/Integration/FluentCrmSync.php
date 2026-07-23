<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FChubMemberships\FluentCRM\Projection\MembershipContactProjector;
use FChubMemberships\Http\Controllers\SettingsController;
use FChubMemberships\Storage\FluentCrmProjectionJobRepository;
use FChubMemberships\Support\Clock;

class FluentCrmSync
{
    public const WORKER_HOOK = 'fchub_memberships_process_crm_projection';

    /** @var \Closure(int): array */
    private \Closure $reconciler;
    /** @var \Closure(int): array */
    private \Closure $postflight;
    /** @var \Closure(int, string, array<int, int>, string, bool): int */
    private \Closure $scheduler;
    /** @var \Closure(): string */
    private \Closure $ownerFactory;
    private FluentCrmProjectionJobRepository $jobs;
    private Clock $clock;
    private bool $registered = false;

    private const REQUIRED_METHODS = [
        'lifecycle' => [
            'FluentCrm\\App\\Api\\Classes\\Contacts' => [
                'getContactByUserRef',
                'getContactByUserId',
                'createOrUpdate',
                'getCustomFields',
            ],
            'FluentCrm\\App\\Api\\Classes\\Tags' => [
                'getInstance',
                'importBulk',
            ],
            'FluentCrm\\App\\Models\\Subscriber' => [
                'attachTags',
                'detachTags',
                'attachLists',
                'detachLists',
                'syncCustomFieldValues',
                'custom_fields',
            ],
        ],
        'automation' => [
            'FluentCrm\\App\\Services\\Funnel\\BaseTrigger' => ['register'],
            'FluentCrm\\App\\Services\\Funnel\\BaseAction' => ['register'],
            'FluentCrm\\App\\Services\\Funnel\\BaseBenchMark' => ['register'],
        ],
    ];

    public function __construct(
        ?callable $reconciler = null,
        ?FluentCrmProjectionJobRepository $jobs = null,
        ?callable $postflight = null,
        ?Clock $clock = null,
        ?callable $scheduler = null,
        ?callable $ownerFactory = null
    ) {
        $projector = new MembershipContactProjector();
        $this->reconciler = \Closure::fromCallable($reconciler ?? [$projector, 'reconcile']);
        $this->postflight = \Closure::fromCallable($postflight ?? [$projector, 'preview']);
        $this->clock = $clock ?? new Clock();
        $this->jobs = $jobs ?? new FluentCrmProjectionJobRepository($this->clock);
        $this->scheduler = \Closure::fromCallable($scheduler ?? static function (
            int $timestamp,
            string $hook,
            array $args,
            string $group,
            bool $unique
        ): int {
            if (!function_exists('as_schedule_single_action')) {
                return 0;
            }

            return (int) as_schedule_single_action($timestamp, $hook, $args, $group, $unique);
        });
        $this->ownerFactory = \Closure::fromCallable(
            $ownerFactory ?? static fn(): string => wp_generate_password(32, false, false)
        );
    }

    public static function hasRequiredCapabilities(
        string $surface,
        ?callable $functionExists = null,
        ?callable $classExists = null,
        ?callable $methodExists = null,
        ?callable $apiResolver = null
    ): bool {
        if (!defined('FLUENTCRM') || !isset(self::REQUIRED_METHODS[$surface])) {
            return false;
        }

        if (
            $surface === 'automation'
            && (!defined('FLUENTCRM_PLUGIN_VERSION') || version_compare(FLUENTCRM_PLUGIN_VERSION, '2.0.0', '<'))
        ) {
            return false;
        }

        $functionExists ??= 'function_exists';
        $classExists ??= 'class_exists';
        $methodExists ??= 'method_exists';

        if ($surface === 'lifecycle' && !$functionExists('FluentCrmApi')) {
            return false;
        }

        foreach (self::REQUIRED_METHODS[$surface] as $class => $methods) {
            if (!$classExists($class)) {
                return false;
            }

            foreach ($methods as $method) {
                if (!$methodExists($class, $method)) {
                    return false;
                }
            }
        }

        if ($surface === 'lifecycle') {
            $apiResolver ??= static fn(string $resource): object => FluentCrmApi($resource);
            if (!self::hasRuntimeLifecycleApi($apiResolver)) {
                return false;
            }
        }

        return true;
    }

    public function register(
        ?callable $functionExists = null,
        ?callable $classExists = null,
        ?callable $methodExists = null,
        ?callable $apiResolver = null
    ): void
    {
        if ($this->registered) {
            return;
        }

        if (!self::hasRequiredCapabilities(
            'lifecycle',
            $functionExists,
            $classExists,
            $methodExists,
            $apiResolver
        )) {
            return;
        }

        $settings = SettingsController::getSettings();
        if (($settings['fluentcrm_enabled'] ?? 'no') !== 'yes') {
            return;
        }

        add_action('fchub_memberships/grant_created', [$this, 'onGrantCreated'], 10, 3);
        add_action('fchub_memberships/grant_revoked', [$this, 'onGrantRevoked'], 10, 4);
        add_action('fchub_memberships/grant_paused', [$this, 'onGrantPaused'], 10, 2);
        add_action('fchub_memberships/grant_resumed', [$this, 'onGrantResumed'], 10, 1);
        add_action('fchub_memberships/grant_expired', [$this, 'onGrantExpired'], 10, 1);
        add_action('fchub_memberships/grant_renewed', [$this, 'onGrantRenewed'], 10, 2);
        add_action(self::WORKER_HOOK, [$this, 'processProjectionJob'], 10, 3);
        $this->registered = true;
    }

    private static function hasRuntimeLifecycleApi(callable $apiResolver): bool
    {
        try {
            $contacts = $apiResolver('contacts');
            if (!is_object($contacts)) {
                return false;
            }

            foreach (['getContactByUserRef', 'getContactByUserId', 'createOrUpdate', 'getCustomFields'] as $method) {
                if (!is_callable([$contacts, $method])) {
                    return false;
                }
            }

            $tags = $apiResolver('tags');
            if (!is_object($tags)) {
                return false;
            }
            foreach (['getInstance', 'importBulk'] as $method) {
                if (!is_callable([$tags, $method])) {
                    return false;
                }
            }

            $tagModel = $tags->getInstance();
            if (!is_object($tagModel) || !is_callable([$tagModel, 'newQuery'])) {
                return false;
            }

            $query = $tagModel->newQuery();
            if (!is_object($query)) {
                return false;
            }

            foreach (['where', 'first'] as $method) {
                if (!is_callable([$query, $method])) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function onGrantCreated(int $userId, int $planId, array $context = []): void
    {
        $this->reconcileUser($userId);
    }

    public function onGrantRevoked(array $grants, int $planId, int $userId, string $reason = ''): void
    {
        $this->reconcileUser($userId);
    }

    public function onGrantPaused(array $grant, string $reason = ''): void
    {
        $this->reconcileGrant($grant);
    }

    public function onGrantResumed(array $grant): void
    {
        $this->reconcileGrant($grant);
    }

    public function onGrantExpired(array $grant): void
    {
        $this->reconcileGrant($grant);
    }

    public function onGrantRenewed(array $grant, int $renewalCount): void
    {
        $this->reconcileGrant($grant);
    }

    private function reconcileGrant(array $grant): void
    {
        $userId = (int) ($grant['user_id'] ?? 0);
        if ($userId > 0) {
            $this->reconcileUser($userId);
        }
    }

    public function processProjectionJob(int $userId, int $requestVersion, int $attempt): void
    {
        if ($userId <= 0 || $requestVersion <= 0 || $attempt < 1 || $attempt > 4) {
            return;
        }

        $owner = $this->workerOwner();
        try {
            $claimed = $this->jobs->claim($userId, $requestVersion, $attempt, $owner);
        } catch (\Throwable) {
            return;
        }
        if ($claimed === null) {
            return;
        }

        $errorCode = null;
        try {
            $result = ($this->reconciler)($userId);
            if (empty($result['success'])) {
                $errorCode = $this->errorCode($result);
            } else {
                $postflight = ($this->postflight)($userId);
                if (empty($postflight['success']) || (int) ($postflight['drift'] ?? 0) !== 0) {
                    $errorCode = 'projection_postflight_failed';
                }
            }
        } catch (\Throwable) {
            $errorCode = 'projection_unexpected_failure';
        }

        if ($errorCode === null) {
            try {
                $completed = $this->jobs->completeSuccess($userId, $requestVersion, $attempt, $owner);
            } catch (\Throwable) {
                $completed = false;
            }
            if (!$completed) {
                $this->requeueLatest($userId);
            }
            return;
        }

        try {
            $job = $this->jobs->completeFailure(
                $userId,
                $requestVersion,
                $attempt,
                $owner,
                $errorCode
            );
        } catch (\Throwable) {
            return;
        }
        if ($job === null) {
            $this->requeueLatest($userId);
        } elseif (($job['status'] ?? null) === 'pending') {
            $this->schedule($job);
        }
    }

    public function recoverDue(int $limit = 50): int
    {
        $limit = max(1, min(50, $limit));
        try {
            $jobs = $this->jobs->findRecoverable($limit);
        } catch (\Throwable) {
            return 0;
        }

        $scheduled = 0;
        foreach ($jobs as $job) {
            if ($this->schedule($job)) {
                $scheduled++;
            }
        }

        return $scheduled;
    }

    /** @return array{accepted:bool,user_id:int,request_version:int,status:string,scheduled:bool} */
    public function queueProjection(int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('CRM projection user ID must be greater than zero.');
        }

        $job = $this->jobs->request($userId);
        $requestVersion = max(1, (int) ($job['request_version'] ?? 0));

        return [
            'accepted' => true,
            'user_id' => $userId,
            'request_version' => $requestVersion,
            'status' => 'pending',
            'scheduled' => $this->schedule($job),
        ];
    }

    private function reconcileUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $job = $this->jobs->request($userId);
        } catch (\Throwable) {
            return;
        }

        $this->processProjectionJob(
            $userId,
            (int) $job['request_version'],
            1
        );
    }

    private function requeueLatest(int $userId): void
    {
        try {
            $job = $this->jobs->request($userId);
        } catch (\Throwable) {
            return;
        }

        $this->schedule($job);
    }

    /** @param array<string, mixed> $job */
    private function schedule(array $job): bool
    {
        $userId = (int) ($job['user_id'] ?? 0);
        $requestVersion = (int) ($job['request_version'] ?? 0);
        $status = (string) ($job['status'] ?? '');
        $attemptCount = (int) ($job['attempt_count'] ?? 0);
        $attempt = $status === 'processing' ? $attemptCount : $attemptCount + 1;
        if (!in_array($status, ['pending', 'processing'], true)
            || $userId <= 0 || $requestVersion <= 0 || $attempt < 1 || $attempt > 4
        ) {
            return false;
        }

        $runAt = $this->clock->now();
        $nextRetryAt = $job['next_retry_at'] ?? null;
        if (is_string($nextRetryAt) && $nextRetryAt !== '') {
            try {
                $candidate = $this->clock->parseLocal($nextRetryAt);
                if ($candidate > $runAt) {
                    $runAt = $candidate;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        $group = sprintf(
            'fchub-memberships-crm-projection-%d-v%d-a%d',
            $userId,
            $requestVersion,
            $attempt
        );

        return ($this->scheduler)(
            $runAt->getTimestamp(),
            self::WORKER_HOOK,
            [$userId, $requestVersion, $attempt],
            $group,
            true
        ) > 0;
    }

    /** @param array<string, mixed> $result */
    private function errorCode(array $result): string
    {
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $errors = array_values(array_filter($errors, 'is_string'));

        if (!empty($result['degraded']) || $this->containsError($errors, ['rollback', 'compensation'])) {
            return 'projection_compensation_failed';
        }
        if (in_array('invalid_user', $errors, true)) {
            return 'projection_invalid_user';
        }
        if (in_array('projection_load_failed', $errors, true)) {
            return 'projection_load_failed';
        }
        if ($this->containsError($errors, ['contact_'])) {
            return 'projection_contact_failed';
        }
        if ($this->containsError($errors, ['tag_resolve'])) {
            return 'projection_tag_failed';
        }
        if ($this->containsError($errors, ['_read_failed'])) {
            return 'projection_relation_read_failed';
        }
        if (in_array('state_save_failed', $errors, true)) {
            return 'projection_state_commit_failed';
        }
        if ($this->containsError($errors, ['custom_field_sync'])) {
            return 'projection_custom_fields_failed';
        }
        if ($this->containsError($errors, ['_attach_', '_detach_', '_attach_failed', '_detach_failed'])) {
            return 'projection_relation_write_failed';
        }

        return 'projection_unexpected_failure';
    }

    /** @param list<string> $errors @param list<string> $needles */
    private function containsError(array $errors, array $needles): bool
    {
        foreach ($errors as $error) {
            foreach ($needles as $needle) {
                if (str_contains($error, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function workerOwner(): string
    {
        $owner = preg_replace('/[^A-Za-z0-9._:-]/', '', (string) ($this->ownerFactory)());
        if (!is_string($owner) || $owner === '') {
            $owner = hash('sha256', (string) $this->clock->now()->getTimestamp());
        }

        return substr($owner, 0, 64);
    }
}
