<?php

namespace FChubMemberships\Email;

defined('ABSPATH') || exit;

final class NotificationTemplateRenderer
{
    private const BLOCK_TYPES = ['rich_text', 'heading', 'button', 'image', 'divider', 'spacer', 'dynamic'];

    /**
     * @param array<string, mixed>|string|null $template
     * @param array<string, mixed> $codes
     * @param array<string, mixed>|null $theme
     * @return array{subject: string, preheader: string, html: string, template: array<string, mixed>, theme: array<string, mixed>}
     */
    public function compose(
        string $key,
        array|string|null $template,
        array $codes,
        ?array $theme = null,
        ?array $themeOverride = null
    ): array
    {
        $definition = NotificationCatalog::get($key);
        if (!$definition) {
            throw new \InvalidArgumentException('Unknown email notification type.');
        }

        $template = $this->normaliseTemplate($key, $template);
        $theme = $this->normaliseTheme($theme ?? $this->storedTheme());
        if ($themeOverride !== null) {
            $theme = $this->normaliseTheme(array_replace($theme, $themeOverride));
        }
        $subject = sanitize_text_field($this->replaceSubjectCodes($template['subject'], $codes));
        $preheader = sanitize_text_field($this->replaceSubjectCodes($template['preheader'], $codes));
        $body = $this->renderBlocks($key, $template['blocks'], $codes, $theme);
        $renderTheme = $theme;
        $renderTheme['header_text'] = $this->replaceSubjectCodes($theme['header_text'], $codes);
        $renderTheme['footer_html'] = $this->replaceBodyCodes($key, $theme['footer_html'], $codes);

        return [
            'subject' => $subject,
            'preheader' => $preheader,
            'html' => $this->wrapHtml($subject, $preheader, $body, $renderTheme),
            'template' => $template,
            'theme' => $theme,
        ];
    }

    /**
     * @param array<string, mixed>|string|null $template
     * @return array<string, mixed>
     */
    public function normaliseTemplate(string $key, array|string|null $template): array
    {
        $definition = NotificationCatalog::get($key);
        if (!$definition) {
            throw new \InvalidArgumentException('Unknown email notification type.');
        }

        if (is_string($template)) {
            $template = [
                'subject' => $definition['default_subject'],
                'preheader' => $definition['default_preheader'],
                'blocks' => [[
                    'id' => 'legacy-content',
                    'type' => 'rich_text',
                    'content' => $template,
                ]],
            ];
        }

        $template = is_array($template) ? $template : [];
        $blocks = [];
        foreach (array_slice((array) ($template['blocks'] ?? []), 0, 50) as $index => $block) {
            if (!is_array($block) || !in_array($block['type'] ?? '', self::BLOCK_TYPES, true)) {
                continue;
            }
            $blocks[] = $this->normaliseBlock($block, $index);
        }

        if (!$blocks) {
            $blocks[] = [
                'id' => 'message-content',
                'type' => 'rich_text',
                'content' => $this->sanitiseRichText((string) $definition['default_body']),
            ];
        }

        return [
            'version' => 1,
            'subject' => sanitize_text_field((string) ($template['subject'] ?? $definition['default_subject'])),
            'preheader' => sanitize_text_field((string) ($template['preheader'] ?? $definition['default_preheader'])),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string, mixed>|null $theme
     * @return array<string, mixed>
     */
    public function normaliseTheme(?array $theme): array
    {
        $theme = $theme ?? [];
        return [
            'logo_url' => $this->safeRemoteUrl((string) ($theme['logo_url'] ?? '')),
            'logo_width' => max(60, min(240, (int) ($theme['logo_width'] ?? 160))),
            'header_style' => in_array(($theme['header_style'] ?? ''), ['brand', 'logo', 'text', 'none'], true)
                ? $theme['header_style']
                : 'brand',
            'header_text' => sanitize_text_field((string) ($theme['header_text'] ?? '')),
            'header_alignment' => in_array(($theme['header_alignment'] ?? ''), ['left', 'center', 'right'], true)
                ? $theme['header_alignment']
                : 'center',
            'header_background' => $this->colour((string) ($theme['header_background'] ?? ($theme['primary_color'] ?? '#2563eb')), '#2563eb'),
            'primary_color' => $this->colour((string) ($theme['primary_color'] ?? '#2563eb'), '#2563eb'),
            'background_color' => $this->colour((string) ($theme['background_color'] ?? '#f3f4f6'), '#f3f4f6'),
            'panel_color' => $this->colour((string) ($theme['panel_color'] ?? '#ffffff'), '#ffffff'),
            'content_color' => $this->colour((string) ($theme['content_color'] ?? '#374151'), '#374151'),
            'link_color' => $this->colour((string) ($theme['link_color'] ?? ($theme['primary_color'] ?? '#2563eb')), '#2563eb'),
            'content_width' => max(480, min(680, (int) ($theme['content_width'] ?? 600))),
            'content_padding' => max(20, min(56, (int) ($theme['content_padding'] ?? 32))),
            'border_radius' => max(0, min(24, (int) ($theme['border_radius'] ?? 12))),
            'font_family' => in_array(($theme['font_family'] ?? ''), ['system', 'arial', 'georgia'], true)
                ? $theme['font_family']
                : 'system',
            'footer_text' => sanitize_text_field((string) ($theme['footer_text'] ?? '')),
            'footer_html' => $this->sanitiseRichText((string) ($theme['footer_html'] ?? '')),
            'footer_background' => $this->colour((string) ($theme['footer_background'] ?? '#f9fafb'), '#f9fafb'),
            'footer_color' => $this->colour((string) ($theme['footer_color'] ?? '#6b7280'), '#6b7280'),
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function normaliseBlock(array $block, int $index): array
    {
        $type = (string) $block['type'];
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($block['id'] ?? '')) ?: $type . '-' . ($index + 1);
        $normalised = ['id' => $id, 'type' => $type];

        if ($type === 'rich_text') {
            $normalised['content'] = $this->sanitiseRichText((string) ($block['content'] ?? ''));
        } elseif ($type === 'heading') {
            $normalised['content'] = sanitize_text_field((string) ($block['content'] ?? ''));
            $normalised['align'] = in_array(($block['align'] ?? ''), ['left', 'center', 'right'], true) ? $block['align'] : 'left';
        } elseif ($type === 'button') {
            $normalised['label'] = sanitize_text_field((string) ($block['label'] ?? __('Continue', 'fchub-memberships')));
            $normalised['url'] = sanitize_text_field((string) ($block['url'] ?? '#'));
            $normalised['align'] = in_array(($block['align'] ?? ''), ['left', 'center', 'right'], true) ? $block['align'] : 'left';
        } elseif ($type === 'image') {
            $normalised['url'] = $this->safeRemoteUrl((string) ($block['url'] ?? ''));
            $normalised['alt'] = sanitize_text_field((string) ($block['alt'] ?? ''));
            $normalised['link_url'] = sanitize_text_field((string) ($block['link_url'] ?? ''));
        } elseif ($type === 'spacer') {
            $normalised['height'] = max(8, min(80, (int) ($block['height'] ?? 24)));
        } elseif ($type === 'dynamic') {
            $normalised['variable'] = sanitize_text_field((string) ($block['variable'] ?? ''));
        }

        return $normalised;
    }

    private function safeRemoteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        return esc_url_raw($url);
    }

    private function sanitiseRichText(string $content): string
    {
        $content = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $content) ?? '';
        if (function_exists('wp_kses')) {
            return wp_kses($content, [
                'p' => ['style' => []],
                'br' => [],
                'strong' => [],
                'b' => [],
                'em' => [],
                'i' => [],
                'u' => [],
                's' => [],
                'a' => ['href' => [], 'target' => [], 'rel' => [], 'style' => []],
                'ul' => [],
                'ol' => [],
                'li' => [],
                'blockquote' => [],
                'h1' => [],
                'h2' => [],
                'h3' => [],
                'h4' => [],
            ]);
        }

        return strip_tags($content, '<p><br><strong><b><em><i><u><s><a><ul><ol><li><blockquote><h1><h2><h3><h4>');
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed> $codes
     * @param array<string, mixed> $theme
     */
    private function renderBlocks(string $key, array $blocks, array $codes, array $theme): string
    {
        $html = '';
        foreach ($blocks as $block) {
            $type = $block['type'];
            if ($type === 'rich_text') {
                $html .= '<div style="margin:0 0 20px;">' . $this->replaceBodyCodes($key, $block['content'], $codes) . '</div>';
            } elseif ($type === 'heading') {
                $content = $this->replaceBodyCodes($key, $block['content'], $codes);
                $html .= '<h2 style="margin:0 0 16px;color:' . esc_attr($theme['content_color']) . ';font-size:24px;line-height:1.25;text-align:' . esc_attr($block['align']) . ';">' . $content . '</h2>';
            } elseif ($type === 'button') {
                $label = $this->replaceBodyCodes($key, $block['label'], $codes);
                $url = esc_url($this->replaceUrlCodes($block['url'], $codes));
                $html .= '<table role="presentation" cellspacing="0" cellpadding="0" width="100%" style="margin:4px 0 24px;"><tr><td align="' . esc_attr($block['align']) . '"><a href="' . $url . '" style="display:inline-block;padding:12px 22px;background:' . esc_attr($theme['primary_color']) . ';border-radius:7px;color:#ffffff;text-decoration:none;font-weight:600;line-height:1.2;">' . $label . '</a></td></tr></table>';
            } elseif ($type === 'image' && $block['url']) {
                $src = esc_url($block['url']);
                $alt = esc_attr($this->replaceSubjectCodes($block['alt'], $codes));
                $image = '<img src="' . $src . '" alt="' . $alt . '" width="100%" style="display:block;max-width:100%;height:auto;border:0;">';
                if ($block['link_url']) {
                    $image = '<a href="' . esc_url($this->replaceUrlCodes($block['link_url'], $codes)) . '">' . $image . '</a>';
                }
                $html .= '<div style="margin:0 0 20px;">' . $image . '</div>';
            } elseif ($type === 'divider') {
                $html .= '<div style="height:1px;background:#e5e7eb;margin:24px 0;"></div>';
            } elseif ($type === 'spacer') {
                $html .= '<div style="height:' . (int) $block['height'] . 'px;line-height:' . (int) $block['height'] . 'px;">&nbsp;</div>';
            } elseif ($type === 'dynamic') {
                $html .= '<div style="margin:0 0 20px;">' . $this->replaceBodyCodes($key, $block['variable'], $codes) . '</div>';
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $codes
     */
    private function replaceSubjectCodes(string $content, array $codes): string
    {
        $values = [];
        foreach ($codes as $key => $value) {
            $values[$key] = wp_strip_all_tags((string) $value);
        }
        return str_replace(array_keys($values), array_values($values), $content);
    }

    /**
     * @param array<string, mixed> $codes
     */
    private function replaceBodyCodes(string $key, string $content, array $codes): string
    {
        $definition = NotificationCatalog::get($key);
        $variables = $definition['variables'] ?? [];
        $values = [];
        foreach ($codes as $variable => $value) {
            $type = $variables[$variable]['type'] ?? 'text';
            if ($type === 'rich') {
                $values[$variable] = $this->sanitiseRichText((string) $value);
            } elseif ($type === 'url') {
                $values[$variable] = esc_url((string) $value);
            } else {
                $values[$variable] = esc_html(wp_strip_all_tags((string) $value));
            }
        }
        return str_replace(array_keys($values), array_values($values), $content);
    }

    /**
     * @param array<string, mixed> $codes
     */
    private function replaceUrlCodes(string $content, array $codes): string
    {
        $values = array_map(static fn($value): string => wp_strip_all_tags((string) $value), $codes);
        return str_replace(array_keys($values), array_values($values), $content);
    }

    /**
     * @param array<string, mixed> $theme
     */
    private function wrapHtml(string $title, string $preheader, string $body, array $theme): string
    {
        $siteName = esc_html(get_bloginfo('name'));
        $fontFamily = match ($theme['font_family']) {
            'arial' => 'Arial,Helvetica,sans-serif',
            'georgia' => 'Georgia,Times,serif',
            default => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
        };
        $footer = $theme['footer_html'] ?: esc_html(
            $theme['footer_text'] ?: sprintf(__('Sent by %s', 'fchub-memberships'), get_bloginfo('name'))
        );
        $headerText = esc_html($theme['header_text'] ?: get_bloginfo('name'));
        $brand = '';
        if (in_array($theme['header_style'], ['brand', 'logo'], true) && $theme['logo_url']) {
            $brand = '<img src="' . esc_url($theme['logo_url']) . '" alt="' . esc_attr(get_bloginfo('name')) . '" width="' . (int) $theme['logo_width'] . '" style="display:block;width:' . (int) $theme['logo_width'] . 'px;max-width:100%;height:auto;border:0;margin:' . ($theme['header_alignment'] === 'center' ? '0 auto' : '0') . ';">';
        }
        if ($theme['header_style'] === 'brand' && $brand === '') {
            $brand = '<div style="color:#ffffff;font-size:22px;font-weight:700;">' . $headerText . '</div>';
        } elseif ($theme['header_style'] === 'text') {
            $brand = '<div style="color:#ffffff;font-size:22px;font-weight:700;">' . $headerText . '</div>';
        }
        $header = $theme['header_style'] === 'none'
            ? ''
            : '<tr><td style="padding:28px ' . (int) $theme['content_padding'] . 'px;background:' . esc_attr($theme['header_background']) . ';text-align:' . esc_attr($theme['header_alignment']) . ';">' . $brand . '</td></tr>';

        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . esc_attr($theme['background_color']) . ';font-family:' . esc_attr($fontFamily) . ';">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html($preheader) . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:' . esc_attr($theme['background_color']) . ';"><tr><td align="center" style="padding:32px 16px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;max-width:' . (int) $theme['content_width'] . 'px;background:' . esc_attr($theme['panel_color']) . ';border-radius:' . (int) $theme['border_radius'] . 'px;overflow:hidden;">'
            . $header
            . '<tr><td style="padding:' . (int) $theme['content_padding'] . 'px;color:' . esc_attr($theme['content_color']) . ';font-size:15px;line-height:1.65;">' . $body . '</td></tr>'
            . '<tr><td style="padding:20px ' . (int) $theme['content_padding'] . 'px;background:' . esc_attr($theme['footer_background']) . ';color:' . esc_attr($theme['footer_color']) . ';font-size:12px;line-height:1.5;text-align:center;">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * @return array<string, mixed>
     */
    private function storedTheme(): array
    {
        $settings = get_option('fchub_memberships_settings', []);
        return is_array($settings['email_theme'] ?? null) ? $settings['email_theme'] : [];
    }

    private function colour(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
    }
}
