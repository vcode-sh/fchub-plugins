<?php

/**
 * FluentCRM function and class stubs for unit testing.
 */

if (!function_exists('FluentCrmApi')) {
    function FluentCrmApi($module = null)
    {
        return new class {
            public function getContactByUserId($userId)
            {
                return $GLOBALS['fluentcrm_mock_contact'] ?? null;
            }

            public function getContactByEmail($email)
            {
                return $GLOBALS['fluentcrm_mock_contact'] ?? null;
            }
        };
    }
}

// Tag model stub
if (!class_exists('FluentCrm\App\Models\Tag')) {
    // phpcs:ignore
    class FluentCrm_App_Models_Tag
    {
        public int $id = 1;
        public string $title = '';
        public string $slug = '';

        public static function firstOrCreate(array $attributes, array $values = [])
        {
            $tag = new self();
            $tag->slug = $attributes['slug'] ?? '';
            $tag->title = $values['title'] ?? $attributes['slug'] ?? '';
            $tag->id = $GLOBALS['fluentcrm_mock_tag_id'] ?? 1;
            return $tag;
        }
    }

    class_alias('FluentCrm_App_Models_Tag', 'FluentCrm\App\Models\Tag');
}

// FunnelSubscriber model stub
if (!class_exists('FluentCrm\App\Models\FunnelSubscriber')) {
    // phpcs:ignore
    class FluentCrm_App_Models_FunnelSubscriber
    {
        /** @var array<int, array<string, mixed>> */
        private static array $mockData = [];

        /** @param array<int, array<string, mixed>> $data */
        public static function setMockData(array $data): void
        {
            self::$mockData = $data;
        }

        public static function resetMockData(): void
        {
            self::$mockData = [];
        }

        public static function where(string $field, $value): object
        {
            $data = self::$mockData[$value] ?? null;

            return new class ($data) {
                /** @var array<string, mixed>|null */
                private ?array $data;

                /** @param array<string, mixed>|null $data */
                public function __construct(?array $data)
                {
                    $this->data = $data;
                }

                /** @return array<string, mixed>|null */
                public function first(): ?array
                {
                    return $this->data;
                }
            };
        }
    }

    class_alias('FluentCrm_App_Models_FunnelSubscriber', 'FluentCrm\App\Models\FunnelSubscriber');
}

// FluentCRM contact stub
if (!class_exists('FluentCrm_Mock_Contact')) {
    class FluentCrm_Mock_Contact
    {
        public int $id = 1;
        public string $email = '';
        public int $user_id = 0;
        public array $custom_fields = [];
        public array $attached_tags = [];

        public function updateCustomFieldBySlug(string $slug, $value): void
        {
            $this->custom_fields[$slug] = $value;
            $GLOBALS['fluentcrm_custom_field_updates'][] = ['slug' => $slug, 'value' => $value];
        }

        public function syncCustomFieldValues(array $fields, bool $detachAll = true): void
        {
            foreach ($fields as $slug => $value) {
                $this->custom_fields[$slug] = $value;
                $GLOBALS['fluentcrm_custom_field_updates'][] = ['slug' => $slug, 'value' => $value];
            }
        }

        public function attachTags(array $tagIds): void
        {
            $this->attached_tags = array_merge($this->attached_tags, $tagIds);
            $GLOBALS['fluentcrm_attached_tags'][] = $tagIds;
        }
    }
}
