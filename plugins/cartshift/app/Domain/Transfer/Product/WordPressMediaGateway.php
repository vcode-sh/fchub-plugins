<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

interface WordPressMediaGateway
{
    public function insert(string $file, string $mimeType, string $title): int;

    public function generateMetadata(int $attachmentId, string $file): void;

    public function updateMeta(int $attachmentId, string $key, string $value): void;

    public function file(int $attachmentId): ?string;

    /** @return list<string> Original and every generated attachment file. */
    public function files(int $attachmentId): array;

    public function meta(int $attachmentId, string $key): ?string;

    public function delete(int $attachmentId): bool;
}
