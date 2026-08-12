<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Legacy;

defined('ABSPATH') || exit;

/**
 * Closed inventory of every migration-facing runtime callback and its effects.
 *
 * This is intentionally tedious. A migration entry point nobody classified is
 * merely a back door wearing a nametag.
 */
final readonly class LegacyCommandPolicy
{
    /** @return array<string, array{effects: list<string>, family: string}> */
    public static function inventory(): array
    {
        $entries = [];
        $add = static function (string $id, array $effects, string $family = 'allowed') use (&$entries): void {
            if (isset($entries[$id]) || $effects === []) {
                throw new \LogicException('legacy_entry_point_inventory_invalid');
            }
            $effects = array_values(array_unique($effects));
            sort($effects, SORT_STRING);
            $entries[$id] = ['effects' => $effects, 'family' => $family];
        };

        $genericRun = ['id_map', 'migration_log', 'migration_state', 'scheduled_actions', 'target_commerce_rows', 'wordpress_files'];
        foreach (['migrate', 'retry'] as $name) {
            $add('cli:cartshift ' . $name, $genericRun, 'generic');
        }
        $add('cli:cartshift rollback', ['id_map', 'migration_log', 'target_commerce_rows', 'wordpress_files'], 'generic');
        $add('cli:cartshift reset', ['id_map', 'migration_state', 'scheduled_actions'], 'generic');
        $add('cli:cartshift finalize', ['migration_log', 'target_commerce_rows', 'transients'], 'generic');
        $add('cli:cartshift status', ['none']);
        $add('cli:cartshift log', ['none']);

        foreach (['audit', 'compatibility', 'validate-package'] as $name) {
            $add('cli:cartshift subscriptions ' . $name, ['none']);
        }
        $add('cli:cartshift subscriptions export', ['private_files']);
        $add('cli:cartshift subscriptions prepare-package', ['configuration_option'], 'subscription_package');
        $add('cli:cartshift subscriptions forget-package', ['configuration_option'], 'subscription_package');
        $add('cli:cartshift subscriptions delete-package', ['private_files'], 'subscription_package');
        $add('cli:cartshift subscriptions stage', ['id_map', 'private_files', 'target_commerce_rows'], 'subscription');
        $add('cli:cartshift subscriptions cutover-source', ['private_files', 'source_commerce_rows'], 'subscription');
        $add('cli:cartshift subscriptions activate', ['private_files', 'target_commerce_rows'], 'subscription');
        $add('cli:cartshift subscriptions reconcile', ['private_files'], 'subscription');
        $add('cli:cartshift subscriptions restore-source', ['private_files', 'source_commerce_rows'], 'subscription');

        foreach (['compatibility', 'audit', 'propose-decisions', 'validate-package', 'inspect-target', 'status'] as $name) {
            $add('cli:cartshift transfer ' . $name, ['none']);
        }
        $add('cli:cartshift transfer source-instance', ['private_files']);
        $add('cli:cartshift transfer export', ['private_files']);
        $add('cli:cartshift transfer prepare', ['private_files']);
        $add('cli:cartshift transfer upgrade-schema', ['schema']);
        $add('cli:cartshift transfer stage', ['id_map', 'journal', 'private_files', 'target_commerce_rows', 'wordpress_files']);
        $add('cli:cartshift transfer reconcile', ['journal']);
        $add('cli:cartshift transfer promote', ['id_map', 'journal']);
        $add('cli:cartshift transfer prepare-subscription-cutover', ['private_files']);
        $add('cli:cartshift transfer release-subscription-source', ['private_files', 'source_commerce_rows']);
        $add('cli:cartshift transfer activate-subscriptions', ['private_files', 'target_commerce_rows']);
        $add('cli:cartshift transfer activate-catalogue', ['journal', 'target_commerce_rows']);
        $add('cli:cartshift transfer complete', ['journal']);
        $add('cli:cartshift transfer rollback', ['id_map', 'journal', 'target_commerce_rows', 'wordpress_files']);

        foreach (['preflight', 'counts'] as $route) {
            $add('rest:GET:cartshift/v1/' . $route, ['none']);
        }
        $add('rest:POST:cartshift/v1/preview', ['none']);
        $add('rest:GET:cartshift/v1/scope/search', ['none']);
        $add('rest:POST:cartshift/v1/migrate', $genericRun, 'generic');
        $add('rest:POST:cartshift/v1/retry', $genericRun, 'generic');
        $add('rest:POST:cartshift/v1/migrate/batch', $genericRun, 'generic');
        $add('rest:GET:cartshift/v1/progress', ['none']);
        $add('rest:POST:cartshift/v1/cancel', ['migration_state', 'scheduled_actions'], 'generic');
        $add('rest:POST:cartshift/v1/reset', ['id_map', 'migration_state', 'scheduled_actions'], 'generic');
        $add('rest:POST:cartshift/v1/rollback', ['id_map', 'migration_log', 'target_commerce_rows', 'wordpress_files'], 'generic');
        $add('rest:POST:cartshift/v1/finalize', ['migration_log', 'target_commerce_rows', 'transients'], 'generic');
        $add('rest:GET:cartshift/v1/log', ['none']);
        $add('rest:GET:cartshift/v1/log/stats', ['none']);
        foreach (['rows', 'catalogue', 'variants'] as $route) {
            $add('rest:GET:cartshift/v1/mapping/' . $route, ['none']);
        }
        foreach (['decide', 'bulk', 'clear'] as $route) {
            $add('rest:POST:cartshift/v1/mapping/' . $route, ['mapping_decisions'], 'mapping');
        }
        $add('rest:GET:cartshift/v1/subscriptions/audit', ['none']);
        $add('rest:GET:cartshift/v1/subscriptions/audit/records', ['none']);
        $add('rest:GET:cartshift/v1/subscriptions/packages', ['none']);
        $add('rest:POST:cartshift/v1/subscriptions/packages/prepare', ['configuration_option'], 'subscription_package');
        $add('rest:POST:cartshift/v1/subscriptions/packages/forget', ['configuration_option'], 'subscription_package');
        $add('action:cartshift/migration/process_batch', $genericRun, 'generic');

        ksort($entries, SORT_STRING);
        return $entries;
    }

    /** @return list<string> */
    public static function effectsFor(string $entryPoint): array
    {
        return self::inventory()[$entryPoint]['effects']
            ?? throw new \RuntimeException('legacy_entry_point_not_classified');
    }

    /** @return array{reason_code: string, next_command: string} */
    public function refusal(string $entryPoint): array
    {
        $entry = self::inventory()[$entryPoint] ?? throw new \RuntimeException('legacy_entry_point_not_classified');
        return match ($entry['family']) {
            'generic' => [
                'reason_code' => 'legacy_generic_migration_closed',
                'next_command' => $this->genericReplacement($entryPoint),
            ],
            'mapping' => [
                'reason_code' => 'legacy_mapping_write_closed',
                'next_command' => 'wp cartshift transfer prepare',
            ],
            'subscription' => [
                'reason_code' => 'legacy_subscription_v1_write_closed',
                'next_command' => $this->subscriptionReplacement($entryPoint),
            ],
            'subscription_package' => [
                'reason_code' => 'legacy_subscription_v1_package_write_closed',
                'next_command' => 'wp cartshift transfer validate-package',
            ],
            default => throw new \RuntimeException('legacy_entry_point_is_read_only_or_v2'),
        };
    }

    /** @return array{code: string, message: string, next_command: string, writes: array{nothing: true}} */
    public function refusalPayload(string $entryPoint): array
    {
        $refusal = $this->refusal($entryPoint);

        return [
            'code' => $refusal['reason_code'],
            'message' => sprintf(
                'This legacy write route is closed. Continue with `%s`.',
                $refusal['next_command'],
            ),
            'next_command' => $refusal['next_command'],
            'writes' => ['nothing' => true],
        ];
    }

    private function genericReplacement(string $entryPoint): string
    {
        return match (true) {
            str_contains($entryPoint, 'rollback') => 'wp cartshift transfer rollback',
            str_contains($entryPoint, 'finalize') => 'wp cartshift transfer promote',
            str_contains($entryPoint, 'retry'), str_contains($entryPoint, '/migrate/batch') => 'wp cartshift transfer stage',
            str_contains($entryPoint, 'reset'), str_contains($entryPoint, 'cancel') => 'wp cartshift transfer status',
            default => 'wp cartshift transfer prepare',
        };
    }

    private function subscriptionReplacement(string $entryPoint): string
    {
        return match (true) {
            str_contains($entryPoint, 'reconcile') => 'wp cartshift transfer reconcile',
            str_contains($entryPoint, 'stage') => 'wp cartshift transfer stage',
            str_contains($entryPoint, 'activate') => 'wp cartshift transfer activate-catalogue',
            str_contains($entryPoint, 'restore-source') => 'wp cartshift transfer rollback',
            str_contains($entryPoint, 'cutover-source') => 'wp cartshift transfer stage',
            default => 'wp cartshift transfer audit',
        };
    }
}
