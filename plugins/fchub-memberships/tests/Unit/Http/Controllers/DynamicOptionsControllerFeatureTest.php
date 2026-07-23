<?php

declare(strict_types=1);

namespace {
    if (!defined('FLUENTCRM')) {
        define('FLUENTCRM', '1.0.0');
    }

    if (!defined('LEARNDASH_VERSION')) {
        define('LEARNDASH_VERSION', '4.0.0');
    }

    if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
        define('FLUENT_COMMUNITY_PLUGIN_VERSION', '2.7.0');
    }

    final class FchubTestFluentCrmQuery
    {
        private string $search = '';
        private int $limit = 20;

        public function __construct(private array $items)
        {
        }

        public function where(string $column, string $operator, string $value): self
        {
            $this->search = trim($value, '%');
            return $this;
        }

        public function limit(int $limit): self
        {
            $this->limit = $limit;
            return $this;
        }

        public function get(): array
        {
            $items = $this->items;
            if ($this->search !== '') {
                $items = array_values(array_filter($items, fn(object $item): bool => str_contains(strtolower($item->title), strtolower($this->search))));
            }

            return array_slice($items, 0, $this->limit);
        }
    }

    final class FchubTestFluentCrmApi
    {
        public function __construct(private array $items)
        {
        }

        public function getInstance(): self
        {
            return $this;
        }

        public function newQuery(): FchubTestFluentCrmQuery
        {
            return new FchubTestFluentCrmQuery($this->items);
        }

        public function find(int $id): ?object
        {
            foreach ($this->items as $item) {
                if ((int) $item->id === $id) {
                    return $item;
                }
            }

            return null;
        }
    }

    if (!function_exists('FluentCrmApi')) {
        function FluentCrmApi(string $resource): object
        {
            if (isset($GLOBALS['_fchub_test_fluentcrm_api'][$resource])) {
                return $GLOBALS['_fchub_test_fluentcrm_api'][$resource];
            }

            return match ($resource) {
                'tags' => new FchubTestFluentCrmApi([
                    (object) ['id' => 11, 'title' => 'Gold Members'],
                    (object) ['id' => 12, 'title' => 'Silver Members'],
                ]),
                'lists' => new FchubTestFluentCrmApi([
                    (object) ['id' => 21, 'title' => 'Premium List'],
                    (object) ['id' => 22, 'title' => 'Community Updates'],
                ]),
                default => new \stdClass(),
            };
        }
    }
}

namespace FluentCommunity\App\Models {
    final class Space
    {
        /** @return list<object> */
        public static function testSpaces(): array
        {
            if (isset($GLOBALS['_fchub_test_fluent_community_spaces'])) {
                return $GLOBALS['_fchub_test_fluent_community_spaces'];
            }

            return [
                (object) ['id' => 31, 'title' => 'VIP Space'],
                (object) ['id' => 32, 'title' => 'General Space'],
            ];
        }

        public static function find(int $id): ?object
        {
            $GLOBALS['_fchub_test_fluent_community_space_finds'][] = $id;
            foreach (self::testSpaces() as $space) {
                if ((int) $space->id === $id) {
                    return $space;
                }
            }

            return null;
        }

        public static function query(): object
        {
            return new class {
                private string $search = '';
                private array $ids = [];

                public function where(string $column, string $operator, string $value): self
                {
                    $this->search = trim($value, '%');
                    return $this;
                }

                public function limit(int $limit): self
                {
                    return $this;
                }

                public function whereIn(string $column, array $ids): self
                {
                    $this->ids = array_map('intval', $ids);
                    return $this;
                }

                public function get(): array
                {
                    $spaces = Space::testSpaces();

                    if ($this->ids !== []) {
                        return array_values(array_filter(
                            $spaces,
                            fn(object $space): bool => in_array((int) $space->id, $this->ids, true)
                        ));
                    }

                    if ($this->search === '') {
                        return $spaces;
                    }

                    return array_values(array_filter($spaces, fn(object $space): bool => str_contains(strtolower($space->title), strtolower($this->search))));
                }
            };
        }
    }
}

namespace FChubMemberships\Tests\Unit\Http\Controllers {

    use FChubMemberships\Adapters\FluentCommunityAdapter;
    use FChubMemberships\Http\DynamicOptionsController;
    use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;

    final class DynamicOptionsControllerFeatureTest extends PluginTestCase
    {
        public function test_fluentcommunity_search_includes_saved_resources_outside_the_query(): void
        {
            $GLOBALS['_fchub_test_fluent_community_space_finds'] = [];
            $GLOBALS['_fchub_test_fluent_community_spaces'] = [
                (object) ['id' => 31, 'title' => 'Start Here'],
                (object) ['id' => 99, 'title' => 'Legacy Lounge'],
            ];

            $response = DynamicOptionsController::fcSpaces(
                new \WP_REST_Request('GET', '/fc-spaces', [
                    'search' => 'Start',
                    'include' => '99',
                ]),
                new FluentCommunityAdapter($this->spaceCapabilities())
            )->get_data();

            self::assertSame(['31', '99'], array_column($response['data'], 'id'));
            self::assertSame([], $GLOBALS['_fchub_test_fluent_community_space_finds']);
        }

        public function test_fluentcrm_api_double_uses_the_per_test_resource_override(): void
        {
            $contacts = new \stdClass();
            $GLOBALS['_fchub_test_fluentcrm_api'] = ['contacts' => $contacts];

            self::assertSame($contacts, \FluentCrmApi('contacts'));
        }

        public function test_dynamic_options_controller_exposes_integrated_provider_search_results_and_permission_checks(): void
        {
            $providers = DynamicOptionsController::providers(new \WP_REST_Request('GET', '/providers'))->get_data();
            $resourceTypes = DynamicOptionsController::resourceTypes(new \WP_REST_Request('GET', '/resource-types', [
                'provider' => 'fluentcrm',
            ]))->get_data();
            $tags = DynamicOptionsController::fluentcrmTags(new \WP_REST_Request('GET', '/fluentcrm-tags', [
                'search' => 'gold',
            ]))->get_data();
            $lists = DynamicOptionsController::fluentcrmLists(new \WP_REST_Request('GET', '/fluentcrm-lists', [
                'search' => 'premium',
            ]))->get_data();
            $spaces = DynamicOptionsController::fcSpaces(
                new \WP_REST_Request('GET', '/fc-spaces', [
                    'search' => 'vip',
                ]),
                new FluentCommunityAdapter($this->spaceCapabilities())
            )->get_data();

            $providersByValue = array_column($providers['data'], null, 'value');
            self::assertSame('FluentCRM', $providersByValue['fluentcrm']['label']);
            self::assertSame('LearnDash', $providersByValue['learndash']['label']);
            self::assertSame('FluentCommunity', $providersByValue['fluent_community']['label']);
            self::assertSame([
                'value',
                'label',
                'status',
                'version',
                'reason',
                'capabilities',
                'pending_operations',
                'failed_operations',
                'last_successful_reconciliation',
                'repair_url',
            ], array_keys($providersByValue['fluent_community']));
            self::assertSame([
                ['value' => 'fluentcrm_tag', 'label' => 'FluentCRM Tag'],
                ['value' => 'fluentcrm_list', 'label' => 'FluentCRM List'],
            ], $resourceTypes['data']);
            self::assertSame([['id' => '11', 'label' => 'Gold Members']], $tags['data']);
            self::assertSame([['id' => '21', 'label' => 'Premium List']], $lists['data']);
            self::assertSame([['id' => '31', 'label' => 'VIP Space']], $spaces['data']);

            $GLOBALS['_fchub_test_current_user_can'] = false;

            self::assertFalse(DynamicOptionsController::adminPermission());
        }

        public function test_badge_endpoint_exposes_no_options_for_the_unsupported_numeric_contract(): void
        {
            $response = DynamicOptionsController::fcBadges(new \WP_REST_Request('GET', '/fc-badges', [
                'search' => 'legacy',
                'include' => '12,34',
            ]))->get_data();

            self::assertSame(['data' => []], $response);
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function test_badge_endpoint_exposes_only_exact_installed_slugs_for_certified_pro(): void
        {
            define('FLUENT_COMMUNITY_PRO', true);
            define('FLUENT_COMMUNITY_PRO_VERSION', '2.7.0');
            class_alias(CertifiedProHelper::class, 'FluentCommunity\\App\\Services\\Helper');
            class_alias(CertifiedProUtility::class, 'FluentCommunity\\App\\Functions\\Utility');
            class_alias(CertifiedProXProfile::class, 'FluentCommunity\\App\\Models\\XProfile');
            class_alias(
                CertifiedProLeaderBoardHelper::class,
                'FluentCommunityPro\\App\\Modules\\LeaderBoard\\Services\\LeaderBoardHelper'
            );
            CertifiedProUtility::$options = [
                'user_badges' => [
                    'founding-member' => ['title' => 'Founding Member'],
                    'legacy_number' => ['title' => 'Legacy Number'],
                ],
            ];

            $response = DynamicOptionsController::fcBadges(new \WP_REST_Request('GET', '/fc-badges', [
                'search' => 'founding',
            ]))->get_data();

            self::assertSame([
                'data' => [['id' => 'founding-member', 'label' => 'Founding Member']],
            ], $response);
        }

        public function test_dynamic_options_controller_contains_no_executable_numeric_badge_contract(): void
        {
            $source = file_get_contents(dirname(__DIR__, 4) . '/app/Http/DynamicOptionsController.php');

            self::assertIsString($source);
            self::assertStringNotContainsString('FluentCommunity\\App\\Models\\Badge', $source);
            self::assertStringNotContainsString('Badge::query', $source);
        }

        private function spaceCapabilities(): CommunityCapabilityRegistry
        {
            return new CommunityCapabilityRegistry(
                static fn(): array => [
                    'core_active' => true,
                    'core_version' => '2.7.0',
                    'pro_active' => false,
                    'pro_version' => null,
                ],
                static fn(string $feature): bool => $feature === 'course_module',
                static fn(string $capability): bool => $capability === 'spaces'
            );
        }
    }

    final class CertifiedProHelper
    {
        public static function isFeatureEnabled(string $feature): bool
        {
            return in_array($feature, ['course_module', 'user_badge', 'leader_board_module'], true);
        }
    }

    final class CertifiedProUtility
    {
        /** @var array<string, mixed> */
        public static array $options = [];

        public static function getOption(string $key, mixed $default = null): mixed
        {
            return self::$options[$key] ?? $default;
        }
    }

    final class CertifiedProXProfile
    {
        public static function query(): object
        {
            return new \stdClass();
        }
    }

    final class CertifiedProLeaderBoardHelper
    {
        public static function getLevelByPoint(): array
        {
            return [];
        }
    }
}
