<?php
// If uninstall is not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

// FCHub is a control plane, not a product — it owns exactly four keys, all of
// them cached catalogue state. Nothing here ever touches a product plugin,
// its data, or its own options.
$hubOptions = [
    'fchub_catalogue_last_good',
    'fchub_catalogue_etag',
    'fchub_catalogue_last_refresh',
];

$cleanupSite = static function () use ($hubOptions): void {
    foreach ($hubOptions as $option) {
        delete_option($option);
    }

    delete_transient('fchub_catalogue_fresh');
};

if (is_multisite()) {
    $page = 1;
    $perPage = 100;

    do {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);

        foreach ($siteIds as $siteId) {
            switch_to_blog((int) $siteId);
            $cleanupSite();
            restore_current_blog();
        }

        $page++;
    } while (count($siteIds) === $perPage);
} else {
    $cleanupSite();
}
