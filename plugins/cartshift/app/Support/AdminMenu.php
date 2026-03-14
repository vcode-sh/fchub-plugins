<?php

declare(strict_types=1);

namespace CartShift\Support;

use CartShift\Core\FeatureFlags;

defined('ABSPATH') || exit();

final class AdminMenu
{
    public const string MENU_SLUG = 'cartshift-migrator';

    private string $hookSuffix = '';

    public function __construct(
        private readonly FeatureFlags $flags,
    ) {}

    public function addMenuPage(): void
    {
        $this->hookSuffix = add_management_page(
            __('WC to FluentCart Migrator', 'cartshift'),
            __('CartShift', 'cartshift'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== $this->hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'cartshift-admin',
            CARTSHIFT_PLUGIN_URL . 'resources/admin/style.css',
            [],
            (string) filemtime(CARTSHIFT_PLUGIN_PATH . 'resources/admin/style.css'),
        );

        wp_enqueue_script(
            'cartshift-admin',
            CARTSHIFT_PLUGIN_URL . 'resources/admin/app.js',
            [],
            (string) filemtime(CARTSHIFT_PLUGIN_PATH . 'resources/admin/app.js'),
            true,
        );

        wp_localize_script('cartshift-admin', 'cartshift', [
            'restUrl'  => rest_url('cartshift/v1/'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'version'  => CARTSHIFT_VERSION,
            'features' => $this->flags->all(),
        ]);
    }

    public function renderPage(): void
    {
        echo '<style>#wpbody-content { padding-bottom: 0; } #wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error, #wpbody-content > .update-nag { display: none !important; }</style>';
        echo '<script>'
            . '(function(){'
            . 'var t=localStorage.getItem("fcart_admin_theme");'
            . 'if(t&&t.split(":").pop()==="dark"){'
            . '["body",".wp-toolbar","#wpbody-content","#wpfooter"].forEach(function(s){'
            . 'var e=s==="body"?document.body:document.querySelector(s);'
            . 'if(e)e.classList.add("dark");'
            . '});}'
            . '})();'
            . '</script>';
        echo '<div class="cartshift-page-wrap"><div id="cartshift-app"></div></div>';
    }
}
