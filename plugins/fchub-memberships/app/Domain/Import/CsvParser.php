<?php

namespace FChubMemberships\Domain\Import;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Import\Contracts\CsvParserInterface;
use FChubMemberships\Domain\Import\Parsers\PmproCsvParser;
use FChubMemberships\Domain\Import\Parsers\GenericCsvParser;

class CsvParser
{
    /** @var CsvParserInterface[] */
    private array $parsers;

    public function __construct()
    {
        $this->parsers = [
            new PmproCsvParser(),
            new GenericCsvParser(),
        ];
    }

    /**
     * Parse CSV content string into structured import data.
     *
     * @return array{format: string, levels: array, members: array, stats: array, warnings: string[]}
     */
    public function parse(string $content): array
    {
        $warnings = [];

        $content = $this->removeBom($content);
        $content = $this->ensureUtf8($content);

        $delimiter = $this->detectDelimiter($content);
        $rows = $this->csvToArray($content, $delimiter);

        if (empty($rows)) {
            return [
                'format'   => 'unknown',
                'levels'   => [],
                'members'  => [],
                'stats'    => ['total' => 0, 'unique_emails' => 0, 'levels_count' => 0],
                'warnings' => ['CSV file is empty or could not be parsed.'],
            ];
        }

        $headers = array_keys($rows[0]);
        $lowerHeaders = array_map('strtolower', array_map('trim', $headers));

        $parser = $this->resolveParser($lowerHeaders);

        if (!$parser) {
            return [
                'format'   => 'unknown',
                'levels'   => [],
                'members'  => [],
                'stats'    => ['total' => 0, 'unique_emails' => 0, 'levels_count' => 0],
                'warnings' => ['Could not detect CSV format. Ensure the file contains at least an "email" column.'],
            ];
        }

        // Normalise header keys to lowercase for the parser
        $normalisedRows = [];
        foreach ($rows as $row) {
            $normalisedRow = [];
            foreach ($row as $key => $value) {
                $normalisedRow[strtolower(trim($key))] = $value;
            }
            $normalisedRows[] = $normalisedRow;
        }

        $members = $parser->parse($normalisedRows);

        // Filter out rows without email and collect warnings
        $validMembers = [];
        foreach ($members as $i => $member) {
            if (empty($member['email'])) {
                $warnings[] = sprintf('Row %d skipped: missing email address.', $i + 2);
                continue;
            }
            if (!filter_var($member['email'], FILTER_VALIDATE_EMAIL)) {
                $warnings[] = sprintf('Row %d: invalid email "%s".', $i + 2, $member['email']);
                continue;
            }
            $validMembers[] = $member;
        }

        // Build levels summary
        $levelMap = [];
        foreach ($validMembers as $member) {
            $name = $member['level_name'] ?: '(no level)';
            if (!isset($levelMap[$name])) {
                $levelMap[$name] = ['name' => $name, 'count' => 0, 'has_expiry' => false];
            }
            $levelMap[$name]['count']++;
            if (!$member['is_lifetime']) {
                $levelMap[$name]['has_expiry'] = true;
            }
        }

        $uniqueEmails = count(array_unique(array_column($validMembers, 'email')));

        return [
            'format'   => $parser->getSourceName(),
            'levels'   => array_values($levelMap),
            'members'  => $validMembers,
            'stats'    => [
                'total'         => count($validMembers),
                'unique_emails' => $uniqueEmails,
                'levels_count'  => count($levelMap),
            ],
            'warnings' => $warnings,
        ];
    }

    private function resolveParser(array $lowerHeaders): ?CsvParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->canParse($lowerHeaders)) {
                return $parser;
            }
        }
        return null;
    }

    private function removeBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }
        return $content;
    }

    private function ensureUtf8(string $content): string
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = mb_convert_encoding($content, 'UTF-8', 'Windows-1250,ISO-8859-2,ISO-8859-1');
            if ($converted !== false) {
                return $converted;
            }
        }
        return $content;
    }

    private function detectDelimiter(string $content): string
    {
        $firstLine = strstr($content, "\n", true) ?: $content;
        if ($firstLine === '') {
            return ',';
        }

        $delimiters = [',' => 0, ';' => 0, "\t" => 0];
        foreach ($delimiters as $d => &$count) {
            $count = substr_count($firstLine, $d);
        }
        unset($count);

        arsort($delimiters);
        $best = array_key_first($delimiters);

        return $delimiters[$best] > 0 ? $best : ',';
    }

    private function csvToArray(string $content, string $delimiter): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $headers = fgetcsv($stream, 0, $delimiter);
        if ($headers === false) {
            fclose($stream);
            return [];
        }
        $headers = array_map('trim', $headers);
        $count = count($headers);

        $rows = [];
        while (($values = fgetcsv($stream, 0, $delimiter)) !== false) {
            if (count($values) === 1 && ($values[0] === null || trim($values[0]) === '')) {
                continue; // skip blank lines
            }

            if (count($values) < $count) {
                $values = array_pad($values, $count, '');
            } elseif (count($values) > $count) {
                $values = array_slice($values, 0, $count);
            }

            $rows[] = array_combine($headers, $values);
        }

        fclose($stream);
        return $rows;
    }
}
