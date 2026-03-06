<?php

/**
 * Plugin Name: FCHub - Custom Thank You Pages
 * Plugin URI: https://fchub.co
 * Description: Per-product post-payment redirect pages for FluentCart.
 * Version:     0.1.0
 * Author:      Vibe Code
 * Author URI:  https://x.com/vcode_sh
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-thank-you
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to:    6.7
 * Requires PHP: 8.3
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('FCHUB_THANK_YOU_VERSION', '0.1.0');
define('FCHUB_THANK_YOU_PATH', plugin_dir_path(__FILE__));
define('FCHUB_THANK_YOU_URL', plugin_dir_url(__FILE__));
define('FCHUB_THANK_YOU_FILE', __FILE__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'FchubThankYou\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = FCHUB_THANK_YOU_PATH . 'app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

add_action('plugins_loaded', static function (): void {
    \FchubThankYou\Bootstrap\Plugin::boot();
});
