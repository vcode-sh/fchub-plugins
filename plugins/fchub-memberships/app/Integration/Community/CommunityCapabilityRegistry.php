<?php

declare(strict_types=1);

namespace FChubMemberships\Integration\Community;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Models\XProfile;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCommunity\Modules\Course\Services\CourseHelper;
use FluentCommunityPro\App\Modules\LeaderBoard\Services\LeaderBoardHelper;

defined('ABSPATH') || exit;

final class CommunityCapabilityRegistry
{
    private \Closure $environmentResolver;
    private \Closure $featureResolver;
    private \Closure $contractResolver;
    /** @var null|array<string, array<string, mixed>> */
    private ?array $resolved = null;

    public function __construct(
        ?callable $environmentResolver = null,
        ?callable $featureResolver = null,
        ?callable $contractResolver = null
    ) {
        $this->environmentResolver = \Closure::fromCallable(
            $environmentResolver ?? self::defaultEnvironment(...)
        );
        $this->featureResolver = \Closure::fromCallable(
            $featureResolver ?? self::featureEnabled(...)
        );
        $this->contractResolver = \Closure::fromCallable(
            $contractResolver ?? self::contractAvailable(...)
        );
    }

    /** @return array<string, array<string, mixed>> */
    public function capabilities(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $environment = ($this->environmentResolver)();
        $coreActive = !empty($environment['core_active']);
        $coreVersion = self::version($environment['core_version'] ?? null);
        $proActive = $coreActive && !empty($environment['pro_active']);
        $proVersion = self::version($environment['pro_version'] ?? null);
        $proCertified = array_key_exists('pro_certified', $environment)
            ? !empty($environment['pro_certified'])
            : self::isCertifiedProRuntime($coreActive, $coreVersion, $proActive, $proVersion);

        $this->resolved = [
            'spaces' => $this->coreCapability('spaces', $coreActive, $coreVersion),
            'courses' => $this->coreCapability(
                'courses',
                $coreActive,
                $coreVersion,
                'course_module'
            ),
            'profile_verification_read' => $this->coreCapability(
                'profile_verification_read',
                $coreActive,
                $coreVersion
            ),
            'badges' => $this->proCapability(
                'badges',
                'user_badge',
                $proActive,
                $proVersion,
                $proCertified
            ),
            'points' => $this->proCapability(
                'points',
                'leader_board_module',
                $proActive,
                $proVersion,
                $proCertified
            ),
            'leaderboard_levels' => $this->proCapability(
                'leaderboard_levels',
                'leader_board_module',
                $proActive,
                $proVersion,
                $proCertified
            ),
        ];

        return $this->resolved;
    }

    public function supports(string $capability): bool
    {
        $state = $this->capabilities()[$capability] ?? null;

        return is_array($state)
            && ($state['status'] ?? '') === 'available'
            && ($state['available'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    private function coreCapability(
        string $capability,
        bool $active,
        ?string $version,
        ?string $feature = null
    ): array {
        if (!$active) {
            return self::state('inactive', false, 'community_core_inactive', $version, 'core');
        }
        if ($feature !== null && !$this->feature($feature)) {
            return self::state('disabled', false, $feature . '_disabled', $version, 'core');
        }
        if (!$this->contract($capability)) {
            return self::state('incompatible', false, 'required_callable_missing', $version, 'core');
        }

        return self::state('available', true, 'community_core_available', $version, 'core');
    }

    /** @return array<string, mixed> */
    private function proCapability(
        string $capability,
        string $feature,
        bool $active,
        ?string $version,
        bool $certified
    ): array {
        if (!$active) {
            return self::state('inactive', false, 'community_pro_inactive', $version, 'pro');
        }
        if (!$this->feature($feature)) {
            return self::state('disabled', false, $feature . '_disabled', $version, 'pro');
        }
        if (!$this->contract($capability)) {
            return self::state('incompatible', false, 'required_callable_missing', $version, 'pro');
        }
        if (!$certified) {
            return self::state('unverified', false, 'community_pro_not_certified', $version, 'pro');
        }

        return self::state('available', true, 'community_pro_certified', $version, 'pro');
    }

    private function feature(string $feature): bool
    {
        try {
            return (bool) ($this->featureResolver)($feature);
        } catch (\Throwable) {
            return false;
        }
    }

    private function contract(string $capability): bool
    {
        try {
            return (bool) ($this->contractResolver)($capability);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{status:string, available:bool, reason:string, version:?string, tier:string} */
    private static function state(
        string $status,
        bool $available,
        string $reason,
        ?string $version,
        string $tier
    ): array {
        return [
            'status' => $status,
            'available' => $available,
            'reason' => $reason,
            'version' => $version,
            'tier' => $tier,
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultEnvironment(): array
    {
        $coreActive = defined('FLUENT_COMMUNITY_PLUGIN_VERSION');
        $proActive = defined('FLUENT_COMMUNITY_PRO') && (bool) FLUENT_COMMUNITY_PRO;

        $coreVersion = $coreActive
            ? (string) FLUENT_COMMUNITY_PLUGIN_VERSION
            : self::installedVersion('fluent-community/fluent-community.php');
        $proVersion = defined('FLUENT_COMMUNITY_PRO_VERSION')
            ? (string) FLUENT_COMMUNITY_PRO_VERSION
            : self::installedVersion('fluent-community-pro/fluent-community-pro.php');

        return [
            'core_active' => $coreActive,
            'core_version' => $coreVersion,
            'pro_active' => $proActive,
            'pro_version' => $proVersion,
        ];
    }

    private static function isCertifiedProRuntime(
        bool $coreActive,
        ?string $coreVersion,
        bool $proActive,
        ?string $proVersion
    ): bool {
        return $coreActive
            && $proActive
            && $coreVersion === '2.7.0'
            && $proVersion === '2.7.0';
    }

    private static function featureEnabled(string $feature): bool
    {
        if (!is_callable([Helper::class, 'isFeatureEnabled'])) {
            return false;
        }

        return Helper::isFeatureEnabled($feature);
    }

    private static function contractAvailable(string $capability): bool
    {
        return match ($capability) {
            'spaces' => is_callable([Helper::class, 'getUserSpaces'])
                && is_callable([Helper::class, 'getUserSpaceIds'])
                && is_callable([Helper::class, 'isUserInSpace'])
                && is_callable([Helper::class, 'addToSpace'])
                && is_callable([Helper::class, 'removeFromSpace'])
                && self::accessCacheContractAvailable(),
            'courses' => is_callable([Helper::class, 'getUserSpaceIds'])
                && is_callable([Helper::class, 'isUserInSpace'])
                && is_callable([CourseHelper::class, 'getEnrolledCourseIds'])
                && is_callable([CourseHelper::class, 'getCourseProgress'])
                && is_callable([CourseHelper::class, 'enrollCourse'])
                && is_callable([CourseHelper::class, 'leaveCourse'])
                && class_exists(Course::class)
                && is_callable([Course::class, 'query'])
                && self::accessCacheContractAvailable(),
            'profile_verification_read', 'points' => class_exists(XProfile::class)
                && is_callable([XProfile::class, 'query']),
            'badges' => is_callable([Utility::class, 'getOption'])
                && class_exists(XProfile::class)
                && is_callable([XProfile::class, 'query']),
            'leaderboard_levels' => is_callable([LeaderBoardHelper::class, 'getLevelByPoint']),
            default => false,
        };
    }

    private static function accessCacheContractAvailable(): bool
    {
        return class_exists(User::class)
            && is_callable([User::class, 'find'])
            && method_exists(User::class, 'cacheAccessSpaces');
    }

    private static function installedVersion(string $relativePluginFile): ?string
    {
        if (!defined('WP_PLUGIN_DIR')) {
            return null;
        }
        $path = rtrim((string) WP_PLUGIN_DIR, '/\\') . '/' . ltrim($relativePluginFile, '/\\');
        if (!is_readable($path)) {
            return null;
        }
        $header = file_get_contents($path, false, null, 0, 8192);
        if (!is_string($header)
            || preg_match('/^[ \t*#@]*Version:\\s*(.+)$/mi', $header, $matches) !== 1
        ) {
            return null;
        }

        return self::version($matches[1] ?? null);
    }

    private static function version(mixed $version): ?string
    {
        $version = trim((string) $version);

        return $version !== '' && preg_match('/^[0-9A-Za-z.+_-]{1,32}$/', $version) === 1
            ? $version
            : null;
    }
}
