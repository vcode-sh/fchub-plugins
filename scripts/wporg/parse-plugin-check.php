<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

[$script, $slug, $rawPath, $allowlistPath, $outputPath] = array_pad($argv, 5, null);

if (!$slug || !$rawPath || !$allowlistPath || !$outputPath) {
    fwrite(STDERR, "Usage: php {$script} <slug> <raw-output> <allowlist> <result>\n");
    exit(2);
}

$raw = file_get_contents($rawPath);
$allowlistJson = file_get_contents($allowlistPath);

if ($raw === false || $allowlistJson === false) {
    fwrite(STDERR, "Unable to read Plugin Check evidence.\n");
    exit(2);
}

try {
    $ledger = json_decode($allowlistJson, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    fwrite(STDERR, "Invalid allowlist JSON: {$error->getMessage()}\n");
    exit(2);
}

if (!is_array($ledger) || !array_key_exists($slug, $ledger) || !is_array($ledger[$slug])) {
    fwrite(STDERR, "Allowlist must contain an array for {$slug}.\n");
    exit(2);
}

$requiredEvidence = ['code', 'file', 'line', 'reason', 'command', 'regressionTest'];

foreach ($ledger[$slug] as $index => $entry) {
    if (!is_array($entry)) {
        fwrite(STDERR, "Allowlist entry {$index} for {$slug} must be an object.\n");
        exit(2);
    }

    foreach ($requiredEvidence as $field) {
        if (!array_key_exists($field, $entry) || $entry[$field] === '' || $entry[$field] === null) {
            fwrite(STDERR, "Allowlist entry {$index} for {$slug} is missing {$field}.\n");
            exit(2);
        }
    }

    if (!is_int($entry['line']) || $entry['line'] < 1) {
        fwrite(STDERR, "Allowlist entry {$index} for {$slug} needs an exact positive line.\n");
        exit(2);
    }

    if (str_contains((string) $entry['code'], '*') || str_contains((string) $entry['file'], '*')) {
        fwrite(STDERR, "Allowlist entry {$index} for {$slug} cannot use wildcards.\n");
        exit(2);
    }
}

$source = 'strict-json';
$findings = null;
$trimmed = trim($raw);

if (str_contains($trimmed, 'Success: Checks complete. No errors found.')) {
    $findings = [];
    $source = 'wp-cli-success';
} else {
    $jsonStart = strpos($trimmed, '[');
    if ($jsonStart !== false) {
        try {
            $decoded = json_decode(substr($trimmed, $jsonStart), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $findings = $decoded;
            }
        } catch (JsonException) {
            $findings = null;
        }
    }
}

if ($findings === null) {
    fwrite(STDERR, "Plugin Check output was neither strict JSON nor a clean success message.\n");
    exit(2);
}

$errors = [];
$warnings = [];

foreach ($findings as $finding) {
    if (!is_array($finding)) {
        fwrite(STDERR, "Plugin Check returned a malformed finding.\n");
        exit(2);
    }

    $normalised = [
        'code' => (string) ($finding['code'] ?? ''),
        'file' => ltrim((string) ($finding['file'] ?? ''), './'),
        'line' => (int) ($finding['line'] ?? 0),
        'message' => (string) ($finding['message'] ?? ''),
    ];

    if ($normalised['code'] === '' || $normalised['file'] === '' || $normalised['line'] < 0) {
        fwrite(STDERR, "Plugin Check returned a finding without a code, file, and reported line.\n");
        exit(2);
    }

    $suppressed = false;
    foreach ($ledger[$slug] as $entry) {
        if (
            hash_equals((string) $entry['code'], $normalised['code'])
            && hash_equals(ltrim((string) $entry['file'], './'), $normalised['file'])
            && $entry['line'] === $normalised['line']
        ) {
            $suppressed = true;
            break;
        }
    }

    if ($suppressed) {
        continue;
    }

    $type = strtoupper((string) ($finding['type'] ?? $finding['severity'] ?? 'ERROR'));
    if (str_contains($type, 'WARN')) {
        $warnings[] = $normalised;
    } else {
        $errors[] = $normalised;
    }
}

$report = [
    'plugin' => $slug,
    'mode' => 'new',
    'errors' => $errors,
    'warnings' => $warnings,
    'source' => $source,
];

file_put_contents(
    $outputPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);

if ($errors !== [] || $warnings !== []) {
    fwrite(STDERR, "Plugin Check reported unsuppressed errors or warnings for {$slug}.\n");
    exit(1);
}
