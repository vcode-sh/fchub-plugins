<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

use FChubMemberships\Adapters\FluentCommunityAdapter;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Domain\Reconciliation\ProviderReconciliationService;
use FChubMemberships\Support\ResourceTypeRegistry;

class DynamicOptionsController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/resource-types', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'resourceTypes'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/providers', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'providers'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/fluentcrm-tags', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'fluentcrmTags'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/fluentcrm-lists', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'fluentcrmLists'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/fc-spaces', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'fcSpaces'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/fc-badges', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'fcBadges'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function planOptions(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $includeIds = self::requestedIds($request);
        return new \WP_REST_Response(['data' => $service->getOptions($includeIds)]);
    }

    public static function resourceTypes(\WP_REST_Request $request): \WP_REST_Response
    {
        $provider = (string) ($request->get_param('provider') ?: 'wordpress_core');
        $options = [];

        foreach (ResourceTypeRegistry::getInstance()->getAll() as $type) {
            if (($type['provider'] ?? '') !== $provider) {
                continue;
            }

            $options[] = ['value' => $type['key'], 'label' => $type['label']];
        }

        return new \WP_REST_Response(['data' => $options]);
    }

    public static function providers(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'data' => (new ProviderReconciliationService())->providerSummaries(),
        ]);
    }

    public static function fluentcrmTags(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!defined('FLUENTCRM') || !function_exists('FluentCrmApi')) {
            return new \WP_REST_Response(['data' => []]);
        }

        $search = $request->get_param('search') ?: '';
        $adapter = new \FChubMemberships\Adapters\FluentCrmAdapter();
        $tags = $adapter->searchResources($search, 'fluentcrm_tag', 50);

        return new \WP_REST_Response(['data' => $tags]);
    }

    public static function fluentcrmLists(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!defined('FLUENTCRM') || !function_exists('FluentCrmApi')) {
            return new \WP_REST_Response(['data' => []]);
        }

        $search = $request->get_param('search') ?: '';
        $adapter = new \FChubMemberships\Adapters\FluentCrmAdapter();
        $lists = $adapter->searchResources($search, 'fluentcrm_list', 50);

        return new \WP_REST_Response(['data' => $lists]);
    }

    public static function fcSpaces(
        \WP_REST_Request $request,
        ?FluentCommunityAdapter $adapter = null
    ): \WP_REST_Response {
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            return new \WP_REST_Response(['data' => []]);
        }

        $search = $request->get_param('search') ?: '';
        $adapter ??= new FluentCommunityAdapter();
        $spaces = $adapter->searchResources($search, 'fc_space', 50);
        $spaces = self::appendRequestedModelOptions(
            $spaces,
            self::requestedIds($request),
            'FluentCommunity\App\Models\Space'
        );

        return new \WP_REST_Response(['data' => $spaces]);
    }

    public static function fcBadges(
        \WP_REST_Request $request,
        ?FluentCommunityAdapter $adapter = null
    ): \WP_REST_Response {
        $search = $request->get_param('search') ?: '';
        $adapter ??= new FluentCommunityAdapter();

        return new \WP_REST_Response([
            'data' => $adapter->searchResources($search, 'fc_badge', 50),
        ]);
    }

    private static function requestedIds(\WP_REST_Request $request): array
    {
        $raw = (string) ($request->get_param('include') ?: '');
        return array_values(array_unique(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn(int $id): bool => $id > 0
        )));
    }

    private static function appendRequestedModelOptions(array $options, array $requestedIds, string $modelClass): array
    {
        $existingIds = array_map('strval', array_column($options, 'id'));
        $missingIds = array_values(array_filter(
            $requestedIds,
            static fn(int $id): bool => !in_array((string) $id, $existingIds, true)
        ));

        if ($missingIds === [] || !class_exists($modelClass)) {
            return $options;
        }

        $models = $modelClass::query()->whereIn('id', $missingIds)->get();
        $modelsById = [];
        foreach ($models as $model) {
            $modelsById[(int) $model->id] = $model;
        }

        foreach ($missingIds as $missingId) {
            if (isset($modelsById[$missingId])) {
                $options[] = [
                    'id' => (string) $modelsById[$missingId]->id,
                    'label' => $modelsById[$missingId]->title,
                ];
            }
        }

        return $options;
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
