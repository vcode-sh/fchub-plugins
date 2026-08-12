<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

final class LoadedWordPressMediaGateway implements WordPressMediaGateway
{
    public function insert(string $file, string $mimeType, string $title): int
    {
        if (!function_exists('wp_insert_attachment')) {
            throw new \RuntimeException('WordPress media APIs are unavailable.');
        }

        $result = wp_insert_attachment([
            'post_mime_type' => $mimeType,
            'post_title' => pathinfo($title, PATHINFO_FILENAME),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file, 0, true);

        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }
        if (!is_int($result) || $result <= 0) {
            throw new \RuntimeException('WordPress returned no attachment ID.');
        }
        return $result;
    }

    public function generateMetadata(int $attachmentId, string $file): void
    {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $metadata = wp_generate_attachment_metadata($attachmentId, $file);
        if ($metadata === false) {
            throw new \RuntimeException('WordPress attachment metadata generation failed.');
        }
        if ($metadata !== [] && function_exists('wp_update_attachment_metadata')) {
            $result = wp_update_attachment_metadata($attachmentId, $metadata);
            $stored = function_exists('wp_get_attachment_metadata')
                ? wp_get_attachment_metadata($attachmentId, true)
                : false;
            if ($result === false && $stored !== $metadata) {
                throw new \RuntimeException('WordPress attachment metadata update failed.');
            }
        }
    }

    public function updateMeta(int $attachmentId, string $key, string $value): void
    {
        if (update_post_meta($attachmentId, $key, $value) === false) {
            throw new \RuntimeException('WordPress attachment ownership metadata update failed.');
        }
    }

    public function file(int $attachmentId): ?string
    {
        $file = get_attached_file($attachmentId, true);
        return is_string($file) && $file !== '' ? $file : null;
    }

    public function files(int $attachmentId): array
    {
        $original = $this->file($attachmentId);
        if ($original === null) {
            return [];
        }
        $files = [$original => true];
        $directory = dirname($original);
        $metadata = wp_get_attachment_metadata($attachmentId, true);
        if (is_array($metadata)) {
            foreach ((array) ($metadata['sizes'] ?? []) as $size) {
                $name = is_array($size) ? ($size['file'] ?? null) : null;
                if (is_string($name) && $name !== '' && basename($name) === $name) {
                    $files[$directory . '/' . $name] = true;
                }
            }
            $originalImage = $metadata['original_image'] ?? null;
            if (is_string($originalImage) && $originalImage !== '' && basename($originalImage) === $originalImage) {
                $files[$directory . '/' . $originalImage] = true;
            }
        }
        $backups = get_post_meta($attachmentId, '_wp_attachment_backup_sizes', true);
        foreach (is_array($backups) ? $backups : [] as $backup) {
            $name = is_array($backup) ? ($backup['file'] ?? null) : null;
            if (is_string($name) && $name !== '' && basename($name) === $name) {
                $files[$directory . '/' . $name] = true;
            }
        }
        $paths = array_keys($files);
        sort($paths, SORT_STRING);
        return $paths;
    }

    public function meta(int $attachmentId, string $key): ?string
    {
        $value = get_post_meta($attachmentId, $key, true);
        return is_scalar($value) ? (string) $value : null;
    }

    public function delete(int $attachmentId): bool
    {
        return wp_delete_attachment($attachmentId, true) !== false;
    }
}
