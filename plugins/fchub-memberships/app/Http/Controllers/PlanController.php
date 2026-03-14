<?php

namespace FChubMemberships\Http\Controllers;

use FChubMemberships\Http\Controllers\Plans\PlanProductController;
use FChubMemberships\Http\Controllers\Plans\PlanReadController;
use FChubMemberships\Http\Controllers\Plans\PlanTransferController;
use FChubMemberships\Http\Controllers\Plans\PlanWriteController;

defined('ABSPATH') || exit;

final class PlanController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/plans', [
            [
                'methods'             => 'GET',
                'callback'            => [PlanReadController::class, 'index'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [PlanWriteController::class, 'store'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
        ]);

        register_rest_route($ns, '/admin/plans/options', [
            'methods'             => 'GET',
            'callback'            => [PlanReadController::class, 'options'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [PlanReadController::class, 'show'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [PlanWriteController::class, 'update'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [PlanWriteController::class, 'destroy'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/duplicate', [
            'methods'             => 'POST',
            'callback'            => [PlanWriteController::class, 'duplicate'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/drip-schedule', [
            'methods'             => 'GET',
            'callback'            => [PlanReadController::class, 'dripSchedule'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/linked-products', [
            'methods'             => 'GET',
            'callback'            => [PlanProductController::class, 'linkedProducts'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/link-product', [
            'methods'             => 'POST',
            'callback'            => [PlanProductController::class, 'linkProduct'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/unlink-product/(?P<feed_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [PlanProductController::class, 'unlinkProduct'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/search-products', [
            'methods'             => 'GET',
            'callback'            => [PlanProductController::class, 'searchProducts'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/resolve-resources', [
            'methods'             => 'POST',
            'callback'            => [PlanReadController::class, 'resolveResources'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/export', [
            'methods'             => 'GET',
            'callback'            => [PlanTransferController::class, 'export'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/export-all', [
            'methods'             => 'GET',
            'callback'            => [PlanTransferController::class, 'exportAll'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/import', [
            'methods'             => 'POST',
            'callback'            => [PlanTransferController::class, 'import'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/schedule', [
            'methods'             => 'POST',
            'callback'            => [PlanTransferController::class, 'schedule'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
