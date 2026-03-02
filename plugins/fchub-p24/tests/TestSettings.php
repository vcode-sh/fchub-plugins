<?php

namespace FChubP24\Tests;

use FChubP24\Gateway\Przelewy24Settings;

/**
 * Test-friendly settings class that bypasses database
 */
class TestSettings extends Przelewy24Settings
{
    private string $mode;

    public function __construct(array $overrides = [], string $mode = 'test')
    {
        $this->mode = $mode;
        // Skip parent constructor (avoids DB calls)
        $this->settings = array_merge(static::getDefaults(), $overrides);
    }

    public function getMode()
    {
        return $this->mode;
    }
}
