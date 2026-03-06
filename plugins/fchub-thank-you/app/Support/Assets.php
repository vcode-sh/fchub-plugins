<?php

declare(strict_types=1);

namespace FchubThankYou\Support;

final class Assets
{
    public static function adminScriptUrl(): string
    {
        return FCHUB_THANK_YOU_URL . 'assets/js/fchub-thank-you-admin.js';
    }

    public static function version(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }
        $file = FCHUB_THANK_YOU_PATH . 'assets/js/fchub-thank-you-admin.js';
        $version = FCHUB_THANK_YOU_VERSION . '.' . (is_file($file) ? (string) filemtime($file) : '0');
        return $version;
    }
}
