<?php

declare(strict_types=1);

namespace FluentCart\App\Modules\Integrations {
    #[\AllowDynamicProperties]
    class BaseIntegrationManager
    {
        public function __construct(string $title = '', string $key = '', int $priority = 10)
        {
        }

        public function register(): void
        {
            $GLOBALS['_fchub_test_registered_integrations'][] = static::class;
        }

        protected function actionFields(): array
        {
            return [];
        }
    }
}

namespace FChubMemberships\Tests\Unit\Modules {

    use FChubMemberships\Modules\Runtime\FluentCartRuntimeModule;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    if (!defined('FLUENTCART_VERSION')) {
        define('FLUENTCART_VERSION', '1.0.0');
    }

    final class RuntimeModuleBootFeatureTest extends PluginTestCase
    {
        public function test_boot_runtime_runs_migrations_and_registers_runtime_wiring(): void
        {
            $GLOBALS['_fchub_test_is_admin'] = true;
            $GLOBALS['_fchub_test_post_types'] = ['post', 'page'];
            $GLOBALS['_fchub_test_taxonomies'] = ['category'];
            $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = '0.9.0';
            $GLOBALS['_fchub_test_registered_integrations'] = [];

            $module = new FluentCartRuntimeModule();
            $module->bootRuntime();

            self::assertSame(FCHUB_MEMBERSHIPS_DB_VERSION, $GLOBALS['_fchub_test_options']['fchub_memberships_db_version']);
            self::assertNotEmpty($GLOBALS['_fchub_test_dbdelta']);
            self::assertContains('fchub_restrict', array_keys($GLOBALS['_fchub_test_shortcodes']));
            self::assertContains('fchub_my_memberships', array_keys($GLOBALS['_fchub_test_shortcodes']));
            self::assertArrayHasKey('fluent_cart/integration/global_integration_settings_memberships', $GLOBALS['_fchub_test_filters']);
            self::assertContains(
                'FChubMemberships\\Integration\\MembershipAccessIntegration',
                $GLOBALS['_fchub_test_registered_integrations']
            );
            self::assertArrayHasKey('fluent_cart/payments/subscription_status_changed', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('fluent_cart/order_payment_failed', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('template_redirect', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('comments_open', $GLOBALS['_fchub_test_filters']);
            self::assertArrayHasKey('wp_nav_menu_objects', $GLOBALS['_fchub_test_filters']);
            self::assertArrayHasKey('category_edit_form_fields', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('fluent_cart/integration/integration_options_plan_id', $GLOBALS['_fchub_test_filters']);
            self::assertArrayHasKey('fluent_cart/integration/addons', $GLOBALS['_fchub_test_filters']);
            self::assertArrayHasKey('rest_api_init', $GLOBALS['_fchub_test_actions']);
        }
    }
}
