<?php

defined('ABSPATH') || exit;

$fail = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fail(
    defined('FLUENT_COMMUNITY_PLUGIN_VERSION')
        && FLUENT_COMMUNITY_PLUGIN_VERSION === '2.7.0',
    'FluentCommunity core 2.7.0 must be active for Plan 020 runtime certification.'
);
$fail(
    defined('FLUENT_COMMUNITY_PRO')
        && FLUENT_COMMUNITY_PRO
        && defined('FLUENT_COMMUNITY_PRO_VERSION')
        && FLUENT_COMMUNITY_PRO_VERSION === '2.7.0',
    'FluentCommunity Pro 2.7.0 must be active for Plan 020 runtime certification.'
);

foreach (['course_module', 'user_badge', 'leader_board_module'] as $feature) {
    $fail(
        \FluentCommunity\App\Services\Helper::isFeatureEnabled($feature),
        "Required FluentCommunity feature is disabled: {$feature}."
    );
}

$pluginDir = rtrim((string) WP_PLUGIN_DIR, '/\\');
$sources = [
    'badge_module' => $pluginDir . '/fluent-community-pro/app/Modules/UserBadge/UserBadgeModule.php',
    'badge_controller' => $pluginDir . '/fluent-community-pro/app/Modules/UserBadge/Controllers/UserBadgeController.php',
    'badge_assignment' => $pluginDir . '/fluent-community-pro/app/Services/Integrations/FluentCRM/ContactAdvancedFilter.php',
    'adapter' => $pluginDir . '/fchub-memberships/app/Adapters/FluentCommunityAdapter.php',
    'resources' => $pluginDir . '/fchub-memberships/app/Support/ResourceTypeRegistry.php',
];
$contents = [];
foreach ($sources as $name => $path) {
    $source = is_readable($path) ? file_get_contents($path) : false;
    $fail(is_string($source), "Unable to read {$name} contract source.");
    $contents[$name] = $source;
}

$proofs = [
    'pro_admin_badge_route' => str_contains($contents['badge_module'], "prefix('admin/user-badges')")
        && str_contains($contents['badge_module'], "->get('/', 'UserBadgeController@getBadges')")
        && str_contains($contents['badge_module'], "->post('/', 'UserBadgeController@saveBadges')"),
    'pro_badges_use_exact_slug_catalogue' => str_contains($contents['badge_controller'], "getOption('user_badges'")
        && str_contains($contents['badge_controller'], '$formattedBadges[$slug]'),
    'pro_badges_mutate_xprofile_model' => str_contains($contents['badge_assignment'], "'badge_slug'")
        && str_contains($contents['badge_assignment'], '->save()'),
    'memberships_registers_slug_badge_resource' => str_contains($contents['resources'], "'fc_badge'"),
    'memberships_adapter_supports_slug_badges' => str_contains($contents['adapter'], "'fc_badge'")
        && str_contains($contents['adapter'], "'badge_slug'")
        && str_contains($contents['adapter'], '->save()'),
];
foreach ($proofs as $proof => $passed) {
    $fail($passed, "Plan 020 static contract failed: {$proof}.");
}

echo wp_json_encode([
    'success' => true,
    'core_version' => FLUENT_COMMUNITY_PLUGIN_VERSION,
    'pro_version' => FLUENT_COMMUNITY_PRO_VERSION,
    'proofs' => $proofs,
], JSON_PRETTY_PRINT) . PHP_EOL;
