<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class OptionStore
{
    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $saved = get_option(Constants::OPTION_SETTINGS, []);

        return array_merge(
            Constants::DEFAULT_SETTINGS,
            is_array($saved) ? $saved : [],
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $current = $this->all();
        $merged = array_merge($current, $values);

        update_option(Constants::OPTION_SETTINGS, $merged);
    }
}
