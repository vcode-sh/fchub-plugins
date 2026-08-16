<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

if (!function_exists(__NAMESPACE__ . '\\setcookie')) {
    /**
     * Captures the native cookie call so tests can assert the browser contract.
     *
     * @param array<string, mixed>|int $expiresOrOptions
     */
    function setcookie(
        string $name,
        string $value = '',
        array|int $expiresOrOptions = 0,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false,
    ): bool {
        $GLOBALS['fchub_mc_setcookie_calls'][] = [
            'name' => $name,
            'value' => $value,
            'options' => $expiresOrOptions,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
        ];

        return $GLOBALS['fchub_mc_setcookie_result'] ?? true;
    }
}
