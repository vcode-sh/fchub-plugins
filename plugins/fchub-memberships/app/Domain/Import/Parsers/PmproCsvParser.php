<?php

namespace FChubMemberships\Domain\Import\Parsers;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Import\Contracts\CsvParserInterface;

class PmproCsvParser implements CsvParserInterface
{
    private const REQUIRED_COLUMNS = ['membership', 'username', 'joined', 'startdate', 'expires'];

    public function canParse(array $headers): bool
    {
        foreach (self::REQUIRED_COLUMNS as $col) {
            if (!in_array($col, $headers, true)) {
                return false;
            }
        }
        return true;
    }

    public function parse(array $rows): array
    {
        $normalised = [];

        foreach ($rows as $row) {
            $expires = trim($row['expires'] ?? '');
            $isLifetime = $expires === '' || $expires === 'Brak danych';

            $normalised[] = [
                'source_id'   => trim($row['id'] ?? ''),
                'email'       => trim($row['email'] ?? ''),
                'username'    => trim($row['username'] ?? ''),
                'first_name'  => trim($row['firstname'] ?? $row['first_name'] ?? ''),
                'last_name'   => trim($row['lastname'] ?? $row['last_name'] ?? ''),
                'level_name'  => trim($row['membership'] ?? ''),
                'joined_at'   => $this->normaliseDate($row['joined'] ?? ''),
                'start_date'  => $this->normaliseDate($row['startdate'] ?? $row['start_date'] ?? ''),
                'expires_at'  => $isLifetime ? null : $this->normaliseDate($expires),
                'is_lifetime' => $isLifetime,
            ];
        }

        return $normalised;
    }

    public function getSourceName(): string
    {
        return 'PMPro';
    }

    private function normaliseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === 'Brak danych') {
            return null;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }
}
