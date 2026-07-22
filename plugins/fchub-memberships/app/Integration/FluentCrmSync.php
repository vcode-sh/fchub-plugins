<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FChubMemberships\FluentCRM\Projection\MembershipContactProjector;
use FChubMemberships\Http\Controllers\SettingsController;

class FluentCrmSync
{
    /** @var \Closure(int): array */
    private \Closure $reconciler;

    private const REQUIRED_METHODS = [
        'lifecycle' => [
            'FluentCrm\\App\\Api\\Classes\\Contacts' => [
                'getContactByUserRef',
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
            ],
        ],
        'automation' => [
            'FluentCrm\\App\\Services\\Funnel\\BaseTrigger' => ['register'],
            'FluentCrm\\App\\Services\\Funnel\\BaseAction' => ['register'],
            'FluentCrm\\App\\Services\\Funnel\\BaseBenchMark' => ['register'],
        ],
    ];

    public function __construct(?callable $reconciler = null)
    {
        $this->reconciler = \Closure::fromCallable(
            $reconciler ?? static fn(int $userId): array => (new MembershipContactProjector())->reconcile($userId)
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
    }

    private static function hasRuntimeLifecycleApi(callable $apiResolver): bool
    {
        try {
            $contacts = $apiResolver('contacts');
            if (!is_object($contacts)) {
                return false;
            }

            foreach (['getContactByUserRef', 'createOrUpdate', 'getCustomFields'] as $method) {
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

    private function reconcileUser(int $userId): void
    {
        if ($userId > 0) {
            ($this->reconciler)($userId);
        }
    }
}
