<?php

namespace FChubMemberships\Email;

defined('ABSPATH') || exit;

final class NotificationEmailComposer
{
    /**
     * @param array<string, mixed> $codes
     * @return array{subject: string, preheader: string, html: string, template: array<string, mixed>, theme: array<string, mixed>}
     */
    public function compose(string $key, array $codes): array
    {
        $settings = get_option('fchub_memberships_settings', []);
        $templates = is_array($settings['email_templates'] ?? null) ? $settings['email_templates'] : [];
        $theme = is_array($settings['email_theme'] ?? null) ? $settings['email_theme'] : [];
        $overrides = is_array($settings['email_theme_overrides'] ?? null) ? $settings['email_theme_overrides'] : [];

        return (new NotificationTemplateRenderer())->compose(
            $key,
            $templates[$key] ?? null,
            $codes,
            $theme,
            is_array($overrides[$key] ?? null) ? $overrides[$key] : null
        );
    }
}
