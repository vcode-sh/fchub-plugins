<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;

class UrlProtection
{
    private AccessEvaluator $evaluator;
    private ProtectionRuleRepository $protectionRepo;

    /** @var array|null Cached URL rules */
    private ?array $cachedRules = null;

    public function __construct()
    {
        $this->evaluator = new AccessEvaluator();
        $this->protectionRepo = new ProtectionRuleRepository();
    }

    public function register(): void
    {
        // Hook early in template_redirect, before ContentProtection
        add_action('template_redirect', [$this, 'checkUrlProtection'], 5);
    }

    /**
     * Main hook: check if current URL matches any protection rules.
     */
    public function checkUrlProtection(): void
    {
        if (is_admin()) {
            return;
        }

        if (wp_doing_ajax()) {
            return;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        // Skip login/register pages
        if (in_array($GLOBALS['pagenow'] ?? '', ['wp-login.php', 'wp-register.php'], true)) {
            return;
        }

        $rules = $this->getUrlRules();
        if (empty($rules)) {
            return;
        }

        $currentUrl = $this->getCurrentUrl();

        // Sort rules by specificity: exact > prefix > regex
        $matchOrder = ['exact' => 1, 'prefix' => 2, 'regex' => 3];
        usort($rules, function ($a, $b) use ($matchOrder) {
            $modeA = $a['meta']['match_mode'] ?? 'prefix';
            $modeB = $b['meta']['match_mode'] ?? 'prefix';
            return ($matchOrder[$modeA] ?? 99) <=> ($matchOrder[$modeB] ?? 99);
        });

        foreach ($rules as $rule) {
            if (!$this->matchUrl($currentUrl, $rule)) {
                continue;
            }

            // Check exclusions
            $excludePatterns = $rule['meta']['exclude_patterns'] ?? [];
            if (!empty($excludePatterns) && $this->isExcluded($currentUrl, $excludePatterns)) {
                continue;
            }

            // Check user access
            $userId = get_current_user_id();
            $resourceId = (string) $rule['resource_id'];

            if ($userId && $this->evaluator->canAccess($userId, Constants::PROVIDER_WORDPRESS_CORE, 'url_pattern', $resourceId)) {
                return;
            }

            // Also check wildcard access
            if ($userId && $this->evaluator->canAccess($userId, Constants::PROVIDER_WORDPRESS_CORE, 'url_pattern', '*')) {
                return;
            }

            $this->handleRestriction($rule);
            return;
        }
    }

    /**
     * Check if a URL matches a protection rule.
     */
    public function matchUrl(string $currentUrl, array $rule): bool
    {
        $mode = $rule['meta']['match_mode'] ?? 'prefix';
        $pattern = $rule['meta']['url_pattern'] ?? '';

        if (empty($pattern)) {
            return false;
        }

        switch ($mode) {
            case 'exact':
                return $this->matchExact($currentUrl, $pattern);
            case 'prefix':
                return $this->matchPrefix($currentUrl, $pattern);
            case 'regex':
                return $this->matchRegex($currentUrl, $pattern);
            default:
                return false;
        }
    }

    /**
     * Exact URL match (path-only comparison).
     */
    public function matchExact(string $url, string $pattern): bool
    {
        $urlPath = $this->normalizePath($url);
        $patternPath = $this->normalizePath($pattern);

        return $urlPath === $patternPath;
    }

    /**
     * Prefix match with wildcard support.
     * Supports trailing * as wildcard (e.g. /members-area/*).
     */
    public function matchPrefix(string $url, string $pattern): bool
    {
        $urlPath = $this->normalizePath($url);
        $patternPath = rtrim($pattern, '/*');
        $patternPath = $this->normalizePath($patternPath);

        // If pattern ends with * in original, match as prefix
        if (str_ends_with(rtrim($pattern, '/'), '*')) {
            return str_starts_with($urlPath, $patternPath);
        }

        // Exact prefix match (the URL path starts with the pattern path segment)
        return $urlPath === $patternPath || str_starts_with($urlPath, $patternPath . '/');
    }

    /**
     * Regex match against the URL path.
     */
    public function matchRegex(string $url, string $pattern): bool
    {
        $urlPath = $this->normalizePath($url);

        // Suppress warnings from invalid regex
        $result = @preg_match($pattern, $urlPath);
        return $result === 1;
    }

    /**
     * Check if a URL matches any exclusion pattern.
     *
     * @param string   $url
     * @param string[] $excludePatterns
     * @return bool
     */
    public function isExcluded(string $url, array $excludePatterns): bool
    {
        $urlPath = $this->normalizePath($url);

        foreach ($excludePatterns as $pattern) {
            $pattern = trim($pattern);
            if (empty($pattern)) {
                continue;
            }

            $patternPath = rtrim($pattern, '/*');
            $patternPath = $this->normalizePath($patternPath);

            // Exact match
            if ($urlPath === $patternPath) {
                return true;
            }

            // Prefix match with wildcard
            if (str_ends_with(rtrim($pattern, '/'), '*')) {
                if (str_starts_with($urlPath, $patternPath)) {
                    return true;
                }
            }

            // Prefix + subpath match
            if (str_starts_with($urlPath, $patternPath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle restriction action: redirect, message, or login redirect.
     */
    public function handleRestriction(array $rule): void
    {
        $action = $rule['meta']['action'] ?? 'redirect';

        switch ($action) {
            case 'login':
                $currentUrl = $this->getCurrentUrl();
                wp_safe_redirect(wp_login_url($currentUrl));
                if (!defined('FCHUB_TESTING')) {
                    exit;
                }
                return;

            case 'message':
                $message = $rule['meta']['restriction_message']
                    ?? __('This area is for members only.', 'fchub-memberships');
                wp_die(
                    wp_kses_post(wpautop($message)),
                    esc_html__('Access Restricted', 'fchub-memberships'),
                    ['response' => 403]
                );
                break;

            case 'redirect':
            default:
                $redirectUrl = $rule['meta']['redirect_url'] ?? '';
                if (empty($redirectUrl)) {
                    $settings = get_option('fchub_memberships_settings', []);
                    $redirectUrl = $settings['default_redirect_url'] ?? home_url('/');
                }
                wp_safe_redirect($redirectUrl);
                if (!defined('FCHUB_TESTING')) {
                    exit;
                }
                return;
        }
    }

    /**
     * Get all URL pattern rules with transient caching.
     *
     * @return array
     */
    public function getUrlRules(): array
    {
        if ($this->cachedRules !== null) {
            return $this->cachedRules;
        }

        $cacheKey = 'fchub_url_protection_rules';
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            $this->cachedRules = $cached;
            return $this->cachedRules;
        }

        $rules = $this->protectionRepo->all(['resource_type' => 'url_pattern']);
        $this->cachedRules = $rules;

        set_transient($cacheKey, $rules, 5 * MINUTE_IN_SECONDS);

        return $this->cachedRules;
    }

    /**
     * Clear the URL rules cache (called when rules are modified).
     */
    public static function clearCache(): void
    {
        delete_transient('fchub_url_protection_rules');
    }

    /**
     * Get the current request URL path.
     */
    private function getCurrentUrl(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        return home_url($requestUri);
    }

    /**
     * Normalize a URL or path to just the path component, trimmed of trailing slashes.
     */
    private function normalizePath(string $urlOrPath): string
    {
        // If it looks like a full URL, parse out the path
        if (str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://')) {
            $parsed = wp_parse_url($urlOrPath);
            $path = $parsed['path'] ?? '/';
        } else {
            $path = $urlOrPath;
        }

        // Normalize: remove trailing slash, ensure leading slash
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/');
    }
}
