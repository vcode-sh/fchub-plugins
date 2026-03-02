<?php

namespace FChubMemberships\Domain\Import\Parsers;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Import\Contracts\CsvParserInterface;

class GenericCsvParser implements CsvParserInterface
{
    public function canParse(array $headers): bool
    {
        return in_array('email', $headers, true);
    }

    public function parse(array $rows): array
    {
        $normalised = [];

        foreach ($rows as $row) {
            $levelName = $this->findColumn($row, ['plan', 'membership', 'level']);
            $expiresRaw = $this->findColumn($row, ['expires', 'expiry', 'expires_at']);

            $normalised[] = [
                'source_id'   => trim($row['id'] ?? $row['source_id'] ?? ''),
                'email'       => trim($row['email'] ?? ''),
                'username'    => trim($row['username'] ?? $row['user_login'] ?? ''),
                'first_name'  => trim($row['first_name'] ?? $row['firstname'] ?? ''),
                'last_name'   => trim($row['last_name'] ?? $row['lastname'] ?? ''),
                'level_name'  => $levelName,
                'joined_at'   => $this->normaliseDate($row['joined_at'] ?? $row['joined'] ?? $row['created_at'] ?? ''),
                'start_date'  => $this->normaliseDate($row['start_date'] ?? $row['startdate'] ?? ''),
                'expires_at'  => $expiresRaw ? $this->normaliseDate($expiresRaw) : null,
                'is_lifetime' => empty($expiresRaw),
            ];
        }

        return $normalised;
    }

    public function getSourceName(): string
    {
        return 'Generic';
    }

    private function findColumn(array $row, array $candidates): string
    {
        foreach ($candidates as $key) {
            if (isset($row[$key]) && trim($row[$key]) !== '') {
                return trim($row[$key]);
            }
        }
        return '';
    }

    private function normaliseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }
}
