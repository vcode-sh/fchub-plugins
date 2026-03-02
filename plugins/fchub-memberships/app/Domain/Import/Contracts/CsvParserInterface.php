<?php

namespace FChubMemberships\Domain\Import\Contracts;

defined('ABSPATH') || exit;

interface CsvParserInterface
{
    /**
     * Check if this parser can handle the given CSV headers.
     *
     * @param array $headers Lowercase, trimmed header names.
     */
    public function canParse(array $headers): bool;

    /**
     * Parse raw CSV rows into normalised member records.
     *
     * @param array $rows Associative arrays keyed by original headers.
     * @return array Normalised rows with keys: source_id, email, username,
     *               first_name, last_name, level_name, joined_at, start_date,
     *               expires_at, is_lifetime
     */
    public function parse(array $rows): array;

    /**
     * Human-readable name of the source format (e.g. "PMPro", "Generic").
     */
    public function getSourceName(): string;
}
