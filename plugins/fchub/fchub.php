<?php
/**
 * Plugin Name: FCHub
 * Plugin URI: https://fchub.co/fchub
 * Description: The calm control centre for the FCHub product family — see what is installed, what needs attention, and update it all from one screen.
 * Version: 1.0.0
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub
 * Requires at least: 6.4
 * Tested up to:    6.7
 * Requires PHP: 8.1
 * Update URI: https://fchub.co/fchub
 */

defined('ABSPATH') || exit;

defined('FCHUB_HUB_VERSION') || define('FCHUB_HUB_VERSION', '1.0.0');
defined('FCHUB_HUB_FILE') || define('FCHUB_HUB_FILE', __FILE__);
defined('FCHUB_HUB_PATH') || define('FCHUB_HUB_PATH', plugin_dir_path(__FILE__));
defined('FCHUB_HUB_URL') || define('FCHUB_HUB_URL', plugin_dir_url(__FILE__));

// Isolated autoloader — resolves only classes under the FChubHub\ namespace,
// so this plugin can never shadow, or be shadowed by, another product's
// classes. This plugin requires no other plugin's runtime and adds no
// dependency on the store commerce platform its products build on.
spl_autoload_register(static function (string $class): void {
    $prefix = 'FChubHub\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = FCHUB_HUB_PATH . 'app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

\FChubHub\Core\Plugin::boot();
