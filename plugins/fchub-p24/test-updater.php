<?php
/**
 * Temporary test script for GitHubUpdater.
 * Run via: docker compose exec wpcli wp eval-file /var/www/html/wp-content/test-updater.php
 */

// Clear all caches
delete_transient('fchub_github_releases');
delete_site_transient('update_plugins');

// Force WordPress to re-check for updates
wp_update_plugins();

$updates = get_site_transient('update_plugins');

echo "=== Updates available ===\n";
if (!empty($updates->response)) {
    foreach ($updates->response as $file => $data) {
        if (is_object($data)) {
            $pkg = !empty($data->package) ? 'YES' : 'NO';
            echo "  {$file} → v" . ($data->version ?? '?') . " (package: {$pkg})\n";
        }
    }
} else {
    echo "  (none — all plugins are at latest version)\n";
}

echo "\n=== FCHub plugins checked, confirmed up to date ===\n";
if (!empty($updates->no_update)) {
    foreach ($updates->no_update as $file => $data) {
        if (strpos($file, 'fchub') !== false || strpos($file, 'wc-fc') !== false) {
            $v = is_object($data) ? ($data->new_version ?? '?') : '?';
            echo "  {$file} (v{$v})\n";
        }
    }
}
