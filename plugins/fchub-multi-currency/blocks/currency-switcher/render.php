<?php

declare(strict_types=1);

use FChubMultiCurrency\Blocks\CurrencySwitcherBlock;

defined('ABSPATH') || exit;

return CurrencySwitcherBlock::render(is_array($attributes ?? null) ? $attributes : []);
