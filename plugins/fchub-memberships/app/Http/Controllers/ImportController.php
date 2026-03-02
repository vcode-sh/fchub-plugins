<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Import\CsvParser;
use FChubMemberships\Domain\Import\ImportService;

class ImportController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/import/parse', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'parse'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/import/prepare', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'prepare'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/import/execute', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'execute'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    /**
     * Parse CSV content and return detected format, levels, and preview.
     */
    public static function parse(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $content = $data['content'] ?? '';

        if (empty($content)) {
            return new \WP_REST_Response([
                'message' => __('CSV content is required.', 'fchub-memberships'),
            ], 422);
        }

        $parser = new CsvParser();
        $result = $parser->parse($content);

        return new \WP_REST_Response([
            'data' => [
                'format'   => $result['format'],
                'levels'   => $result['levels'],
                'members'  => $result['members'],
                'stats'    => $result['stats'],
                'warnings' => $result['warnings'],
                'preview'  => array_slice($result['members'], 0, 10),
            ],
        ]);
    }

    /**
     * Create plans for levels mapped as 'create_new' and return updated mappings.
     */
    public static function prepare(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $mappings = $data['mappings'] ?? [];

        if (empty($mappings)) {
            return new \WP_REST_Response([
                'message' => __('Mappings are required.', 'fchub-memberships'),
            ], 422);
        }

        try {
            $service = new ImportService();
            $updatedMappings = $service->createPlansForLevels($mappings);

            return new \WP_REST_Response([
                'data' => [
                    'mappings' => $updatedMappings,
                ],
            ]);
        } catch (\Throwable $e) {
            return new \WP_REST_Response([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute the import: process members in batch.
     */
    public static function execute(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $members = $data['members'] ?? [];
        $mappings = $data['mappings'] ?? [];
        $conflictMode = $data['conflict_mode'] ?? 'skip';
        $createCustomers = (bool) ($data['create_customers'] ?? false);

        if (!in_array($conflictMode, ['skip', 'extend', 'overwrite'], true)) {
            $conflictMode = 'skip';
        }

        // Enforce batch size limit
        if (count($members) > 100) {
            $members = array_slice($members, 0, 100);
        }

        if (empty($members) || empty($mappings)) {
            return new \WP_REST_Response([
                'message' => __('Members and mappings are required.', 'fchub-memberships'),
            ], 422);
        }

        $service = new ImportService();
        $result = $service->processBatch($members, $mappings, $conflictMode, $createCustomers);

        return new \WP_REST_Response([
            'data' => $result,
        ]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
