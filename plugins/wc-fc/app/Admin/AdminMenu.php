<?php

namespace WcFc\Admin;

defined('ABSPATH') or die;

class AdminMenu
{
    const MENU_SLUG = 'wc-fc-migrator';

    /** @var string The hook suffix returned by add_management_page. */
    private string $hookSuffix = '';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPage(): void
    {
        $this->hookSuffix = add_management_page(
            __('WC to FluentCart Migrator', 'wc-fc'),
            __('WC-FC Migrator', 'wc-fc'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Enqueue assets only on our plugin page.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== $this->hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'wc-fc-admin',
            WCFC_PLUGIN_URL . 'resources/admin/style.css',
            [],
            (string) filemtime(WCFC_PLUGIN_PATH . 'resources/admin/style.css')
        );

        wp_enqueue_script(
            'wc-fc-admin',
            WCFC_PLUGIN_URL . 'resources/admin/app.js',
            [],
            (string) filemtime(WCFC_PLUGIN_PATH . 'resources/admin/app.js'),
            true
        );

        wp_localize_script('wc-fc-admin', 'wcfc', [
            'restUrl' => rest_url('wc-fc/v1/'),
            'nonce'   => wp_create_nonce('wp_rest'),
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
        echo '<div class="wcfc-page-wrap"><div id="wc-fc-app"></div></div>';
    }
}
