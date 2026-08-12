<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\MutationSpy;

use CartShift\Support\CanonicalJson;

final class MutationSpy
{
    private const string MUTATING_SQL = '/\A\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|RENAME|CALL|LOAD|SET)\b/i';

    /** @var list<string> */
    private array $queries = [];
    private bool $installed = false;

    public function install(): void
    {
        if ($this->installed) {
            throw new \LogicException('Mutation spy is already installed.');
        }

        add_filter('query', [$this, 'assertReadOnlySql'], PHP_INT_MIN);
        $this->installed = true;
    }

    public function uninstall(): void
    {
        if (!$this->installed) {
            return;
        }

        remove_filter('query', [$this, 'assertReadOnlySql'], PHP_INT_MIN);
        $this->installed = false;
    }

    public function assertReadOnlySql(string $sql): string
    {
        $this->queries[] = $sql;

        if (preg_match(self::MUTATING_SQL, $sql) === 1) {
            throw new \RuntimeException('Audit attempted mutating SQL.');
        }

        return $sql;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        global $wpdb;

        $tables = [];
        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        $names = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        sort($names);

        foreach ($names as $name) {
            $name = (string) $name;

            if (preg_match('/\A[A-Za-z0-9_]+\z/D', $name) !== 1) {
                throw new \RuntimeException('Unexpected table identifier in mutation snapshot.');
            }

            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$name}`");
            $checksumRow = $wpdb->get_row("CHECKSUM TABLE `{$name}`", ARRAY_A);
            $tables[$name] = [
                'rows' => $count,
                'checksum' => isset($checksumRow['Checksum']) ? (string) $checksumRow['Checksum'] : null,
            ];
        }

        $uploads = wp_upload_dir(null, false);
        $files = $this->treeFingerprint((string) ($uploads['basedir'] ?? ''));
        $spies = $GLOBALS['cartshift_contract_spies'] ?? [];
        ksort($spies);
        $snapshot = [
            'tables' => $tables,
            'uploads' => $files,
            'outgoing' => $spies,
        ];
        $snapshot['fingerprint'] = CanonicalJson::fingerprint($snapshot);

        return $snapshot;
    }

    /** @return array{files: int, bytes: int, fingerprint: string} */
    private function treeFingerprint(string $root): array
    {
        if ($root === '' || !is_dir($root)) {
            return ['files' => 0, 'bytes' => 0, 'fingerprint' => CanonicalJson::fingerprint([])];
        }

        $entries = [];
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $path = $file->getRealPath();

            if (!is_string($path)) {
                throw new \RuntimeException('Upload snapshot could not resolve a file.');
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            $digest = hash_file('sha256', $path);

            if (!is_string($digest)) {
                throw new \RuntimeException('Upload snapshot could not hash a file.');
            }

            $size = $file->getSize();
            $bytes += $size;
            $entries[$relative] = ['bytes' => $size, 'sha256' => $digest];
        }

        ksort($entries);

        return [
            'files' => count($entries),
            'bytes' => $bytes,
            'fingerprint' => CanonicalJson::fingerprint($entries),
        ];
    }
}
