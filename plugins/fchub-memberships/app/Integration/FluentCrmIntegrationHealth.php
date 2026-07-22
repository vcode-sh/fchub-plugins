<?php

declare(strict_types=1);

namespace FChubMemberships\Integration;

use FChubMemberships\Http\Controllers\SettingsController;

defined('ABSPATH') || exit;

final class FluentCrmIntegrationHealth
{
    private const OPTION = 'fchub_memberships_fluentcrm_reconciliation_health';

    /** @var \Closure(): array{active:bool, version:?string} */
    private \Closure $providerResolver;
    /** @var \Closure(): bool */
    private \Closure $compatibilityResolver;
    /** @var \Closure(): array<string, mixed> */
    private \Closure $settingsResolver;
    /** @var \Closure(): array<string, int> */
    private \Closure $catalogueResolver;
    /** @var \Closure(): array<string, mixed> */
    private \Closure $summaryReader;
    /** @var \Closure(array<string, mixed>): bool */
    private \Closure $summaryWriter;
    /** @var \Closure(): string */
    private \Closure $clock;

    public function __construct(
        ?callable $providerResolver = null,
        ?callable $compatibilityResolver = null,
        ?callable $settingsResolver = null,
        ?callable $catalogueResolver = null,
        ?callable $summaryReader = null,
        ?callable $summaryWriter = null,
        ?callable $clock = null
    ) {
        $this->providerResolver = \Closure::fromCallable($providerResolver ?? static fn(): array => [
            'active' => defined('FLUENTCRM'),
            'version' => defined('FLUENTCRM_PLUGIN_VERSION') ? (string) FLUENTCRM_PLUGIN_VERSION : null,
        ]);
        $this->compatibilityResolver = \Closure::fromCallable(
            $compatibilityResolver ?? static fn(): bool => FluentCrmSync::hasRequiredCapabilities('lifecycle')
        );
        $this->settingsResolver = \Closure::fromCallable(
            $settingsResolver ?? static fn(): array => SettingsController::getSettings()
        );
        $this->catalogueResolver = \Closure::fromCallable($catalogueResolver ?? [self::class, 'registeredCatalogue']);
        $this->summaryReader = \Closure::fromCallable(
            $summaryReader ?? static fn(): array => get_option(self::OPTION, [])
        );
        $this->summaryWriter = \Closure::fromCallable(
            $summaryWriter ?? static fn(array $summary): bool => update_option(self::OPTION, $summary, false)
        );
        $this->clock = \Closure::fromCallable(
            $clock ?? static fn(): string => current_time('mysql', true)
        );
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $provider = ($this->providerResolver)();
        $active = !empty($provider['active']);
        $settings = ($this->settingsResolver)();
        $lifecycleSync = ($settings['fluentcrm_enabled'] ?? 'no') === 'yes';
        $compatible = $active && (bool) ($this->compatibilityResolver)();
        $summary = $this->normaliseSummary(($this->summaryReader)());

        if (!$active) {
            [$status, $action] = ['inactive', 'Install and activate FluentCRM.'];
        } elseif (!$lifecycleSync) {
            [$status, $action] = ['disabled', 'Enable FluentCRM lifecycle sync.'];
        } elseif (!$compatible) {
            [$status, $action] = ['incompatible', 'Update FluentCRM to a compatible version.'];
        } elseif ($summary['failed'] > 0 || $summary['drift'] > 0) {
            [$status, $action] = ['degraded', 'Run a dry reconciliation and resolve failures.'];
        } else {
            [$status, $action] = ['healthy', 'No action required.'];
        }

        return [
            'status' => $status,
            'action' => $action,
            'provider' => [
                'active' => $active,
                'version' => $active ? $this->normaliseVersion($provider['version'] ?? null) : null,
            ],
            'compatible' => $compatible,
            'lifecycle_sync' => $lifecycleSync,
            'catalogue' => $this->normaliseCatalogue(($this->catalogueResolver)()),
            'last_reconciliation' => $summary['last_reconciliation'],
            'failed_projections' => $summary['failed'],
            'drift' => $summary['drift'],
        ];
    }

    public function record(int $processed, int $failed, int $drift): void
    {
        ($this->summaryWriter)([
            'last_reconciliation' => ($this->clock)(),
            'processed' => max(0, $processed),
            'failed' => max(0, $failed),
            'drift' => max(0, $drift),
        ]);
    }

    /** @return array{last_reconciliation:?string, processed:int, failed:int, drift:int} */
    private function normaliseSummary(array $summary): array
    {
        $lastReconciliation = $summary['last_reconciliation'] ?? null;

        return [
            'last_reconciliation' => is_string($lastReconciliation) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lastReconciliation)
                ? $lastReconciliation
                : null,
            'processed' => max(0, (int) ($summary['processed'] ?? 0)),
            'failed' => max(0, (int) ($summary['failed'] ?? 0)),
            'drift' => max(0, (int) ($summary['drift'] ?? 0)),
        ];
    }

    /** @return array{triggers:int, actions:int, benchmarks:int, smart_codes:int, filters:int} */
    private function normaliseCatalogue(array $catalogue): array
    {
        return [
            'triggers' => max(0, (int) ($catalogue['triggers'] ?? 0)),
            'actions' => max(0, (int) ($catalogue['actions'] ?? 0)),
            'benchmarks' => max(0, (int) ($catalogue['benchmarks'] ?? 0)),
            'smart_codes' => max(0, (int) ($catalogue['smart_codes'] ?? 0)),
            'filters' => max(0, (int) ($catalogue['filters'] ?? 0)),
        ];
    }

    /** @return array{triggers:int, actions:int, benchmarks:int, smart_codes:int, filters:int} */
    private static function registeredCatalogue(): array
    {
        $empty = ['triggers' => 0, 'actions' => 0, 'benchmarks' => 0, 'smart_codes' => 0, 'filters' => 0];
        if (!function_exists('apply_filters')) {
            return $empty;
        }

        $triggers = apply_filters('fluentcrm_funnel_triggers', []);
        $blocks = apply_filters('fluentcrm_funnel_blocks', [], (object) ['settings' => [], 'conditions' => []]);
        $smartCodeGroups = apply_filters('fluent_crm_funnel_context_smart_codes', []);
        $filterGroups = apply_filters('fluentcrm_advanced_filter_options', []);

        $membershipBlocks = array_filter($blocks, static fn(mixed $block): bool => is_array($block)
            && ($block['category'] ?? '') === 'FCHub Memberships');
        $membershipSmartCode = array_values(array_filter($smartCodeGroups, static fn(mixed $group): bool => is_array($group)
            && ($group['key'] ?? '') === 'membership'));
        $membershipFilters = is_array($filterGroups['fchub_memberships']['children'] ?? null)
            ? $filterGroups['fchub_memberships']['children']
            : [];

        return [
            'triggers' => count(array_filter($triggers, static fn(mixed $trigger, mixed $name): bool => is_string($name)
                && str_starts_with($name, 'fchub_memberships/'), ARRAY_FILTER_USE_BOTH)),
            'actions' => count(array_filter($membershipBlocks, static fn(array $block): bool => ($block['type'] ?? '') === 'action')),
            'benchmarks' => count(array_filter($blocks, static fn(mixed $block, mixed $name): bool => is_array($block)
                && is_string($name)
                && str_starts_with($name, 'fchub_memberships/')
                && ($block['type'] ?? '') === 'benchmark', ARRAY_FILTER_USE_BOTH)),
            'smart_codes' => count($membershipSmartCode[0]['shortcodes'] ?? []),
            'filters' => count($membershipFilters),
        ];
    }

    private function normaliseVersion(mixed $version): ?string
    {
        if (!is_string($version) || !preg_match('/^[0-9]+(?:\.[0-9]+){0,3}(?:[-+][A-Za-z0-9.-]+)?$/', $version)) {
            return null;
        }

        return $version;
    }
}
