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
        define('FLUENT_COMMUNITY_PLUGIN_VERSION', '1.0.0');
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
        public static function query(): object
        {
            return new class {
                private string $search = '';

                public function where(string $column, string $operator, string $value): self
                {
                    $this->search = trim($value, '%');
                    return $this;
                }

                public function limit(int $limit): self
                {
                    return $this;
                }

                public function get(): array
                {
                    $spaces = [
                        (object) ['id' => 31, 'title' => 'VIP Space'],
                        (object) ['id' => 32, 'title' => 'General Space'],
                    ];

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

    use FChubMemberships\Http\DynamicOptionsController;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class DynamicOptionsControllerFeatureTest extends PluginTestCase
    {
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
            $spaces = DynamicOptionsController::fcSpaces(new \WP_REST_Request('GET', '/fc-spaces', [
                'search' => 'vip',
            ]))->get_data();

            self::assertContains(['value' => 'fluentcrm', 'label' => 'FluentCRM'], $providers['data']);
            self::assertContains(['value' => 'learndash', 'label' => 'LearnDash'], $providers['data']);
            self::assertContains(['value' => 'fluent_community', 'label' => 'FluentCommunity'], $providers['data']);
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
    }
}
