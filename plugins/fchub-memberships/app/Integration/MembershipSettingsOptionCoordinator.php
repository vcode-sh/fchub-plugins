<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

final class MembershipSettingsOptionCoordinator
{
    private const OPTION_NAME = 'fchub_memberships_settings';
    private const LOCK_NAME = 'fchub_memberships_settings';

    /** @var \Closure(): bool */
    private \Closure $acquire;

    /** @var \Closure(): void */
    private \Closure $release;

    /** @var \Closure(): array */
    private \Closure $reader;

    /** @var \Closure(array): bool */
    private \Closure $writer;

    public function __construct(
        ?callable $acquire = null,
        ?callable $release = null,
        ?callable $reader = null,
        ?callable $writer = null
    ) {
        $this->acquire = $acquire !== null
            ? \Closure::fromCallable($acquire)
            : static function (): bool {
                global $wpdb;
                return (string) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
                    'SELECT GET_LOCK(%s, %d)',
                    self::LOCK_NAME,
                    5
                )) === '1';
            };
        $this->release = $release !== null
            ? \Closure::fromCallable($release)
            : static function (): void {
                global $wpdb;
                \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME));
            };
        $this->reader = $reader !== null
            ? \Closure::fromCallable($reader)
            : static function (): array {
                global $wpdb;

                $stored = \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                    self::OPTION_NAME
                ));
                self::refreshOptionCaches($stored);
                $settings = $stored === null ? [] : maybe_unserialize($stored);
                return is_array($settings) ? $settings : [];
            };
        $this->writer = $writer !== null
            ? \Closure::fromCallable($writer)
            : static fn(array $settings): bool => update_option(self::OPTION_NAME, $settings);
    }

    /**
     * @return array{success:bool, value?:mixed, reason?:string, exception?:\Throwable}
     */
    public function synchronized(callable $operation): array
    {
        if (!(($this->acquire)())) {
            return ['success' => false, 'reason' => 'lock_unavailable'];
        }

        try {
            return ['success' => true, 'value' => $operation($this)];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'reason' => 'operation_failed',
                'exception' => $exception,
            ];
        } finally {
            ($this->release)();
        }
    }

    public function read(): array
    {
        return ($this->reader)();
    }

    /**
     * Must be called from synchronized().
     *
     * @return array{success:bool, changed:bool, settings:array, reason?:string}
     */
    public function compareAndSwap(array $expected, array $next): array
    {
        $current = $this->read();
        if ($current !== $expected) {
            return [
                'success' => false,
                'changed' => false,
                'settings' => $current,
                'reason' => 'conflict',
            ];
        }

        if ($next === $current) {
            return ['success' => true, 'changed' => false, 'settings' => $current];
        }

        $written = ($this->writer)($next);
        $stored = $this->read();
        if ($written || $stored === $next) {
            return ['success' => true, 'changed' => true, 'settings' => $stored];
        }

        return [
            'success' => false,
            'changed' => false,
            'settings' => $stored,
            'reason' => 'write_failed',
        ];
    }

    /**
     * @return array{success:bool, changed:bool, settings:array, reason?:string}
     */
    public function mutate(callable $mutation): array
    {
        $result = $this->synchronized(function (self $coordinator) use ($mutation): array {
            $current = $coordinator->read();
            $next = $mutation($current);
            if (!is_array($next)) {
                throw new \UnexpectedValueException('The settings mutation must return an array.');
            }

            return $coordinator->compareAndSwap($current, $next);
        });

        if (!$result['success']) {
            return [
                'success' => false,
                'changed' => false,
                'settings' => $this->read(),
                'reason' => $result['reason'] ?? 'operation_failed',
            ];
        }

        return $result['value'];
    }

    private static function refreshOptionCaches(mixed $stored): void
    {
        wp_cache_delete(self::OPTION_NAME, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');

        if ($stored !== null) {
            wp_cache_set(self::OPTION_NAME, maybe_unserialize($stored), 'options');
        }
    }
}
