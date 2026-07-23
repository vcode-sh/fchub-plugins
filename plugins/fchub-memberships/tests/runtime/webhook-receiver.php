<?php

declare(strict_types=1);

const RECEIVER_SECRET_ENV = 'FCHUB_WEBHOOK_RECEIVER_SECRET';
const RECEIVER_TOKEN_ENV = 'FCHUB_WEBHOOK_RECEIVER_TOKEN';
const RECEIVER_LOG_ENV = 'FCHUB_WEBHOOK_RECEIVER_LOG';

if (PHP_SAPI !== 'cli-server') {
    startReceiver($argv);
}

handleRequest();

/** @param list<string> $arguments */
function startReceiver(array $arguments): never
{
    if (count($arguments) !== 4) {
        failStartup('Usage: webhook-receiver.php <port> <random-path-token> <mode-600-jsonl-path>');
    }

    $port = filter_var(
        $arguments[1],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1024, 'max_range' => 65535]]
    );
    if (!is_int($port)) {
        failStartup('The receiver port must be between 1024 and 65535.');
    }

    $token = $arguments[2];
    if (preg_match('/\A[A-Za-z0-9_-]{24,128}\z/', $token) !== 1) {
        failStartup('The receiver path token must contain 24 to 128 URL-safe characters.');
    }

    $secret = getenv(RECEIVER_SECRET_ENV);
    if (!is_string($secret) || $secret === '') {
        failStartup(RECEIVER_SECRET_ENV . ' must be set.');
    }

    $directory = realpath(dirname($arguments[3]));
    if ($directory === false || !is_dir($directory)) {
        failStartup('The JSONL parent directory does not exist.');
    }

    $logPath = $directory . DIRECTORY_SEPARATOR . basename($arguments[3]);
    if (is_link($logPath)) {
        failStartup('The JSONL path must not be a symbolic link.');
    }

    $log = @fopen($logPath, 'wb');
    if (!is_resource($log)) {
        failStartup('Unable to create the JSONL file.');
    }
    fclose($log);

    if (!chmod($logPath, 0600)) {
        failStartup('Unable to protect the JSONL file.');
    }

    clearstatcache(true, $logPath);
    if ((fileperms($logPath) & 0777) !== 0600) {
        failStartup('The JSONL file is not mode 600.');
    }

    putenv(RECEIVER_TOKEN_ENV . '=' . $token);
    putenv(RECEIVER_LOG_ENV . '=' . $logPath);

    if (!function_exists('pcntl_exec')) {
        failStartup('The disposable receiver requires pcntl_exec.');
    }

    pcntl_exec(PHP_BINARY, [
        '-d',
        'display_errors=0',
        '-d',
        'log_errors=1',
        '-S',
        '0.0.0.0:' . $port,
        __FILE__,
    ]);

    failStartup('Unable to start the disposable receiver.');
}

function handleRequest(): never
{
    $token = getenv(RECEIVER_TOKEN_ENV);
    $logPath = getenv(RECEIVER_LOG_ENV);
    $secret = getenv(RECEIVER_SECRET_ENV);
    if (!is_string($token) || !is_string($logPath) || !is_string($secret) || $secret === '') {
        respond(500);
    }

    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($requestPath) || !hash_equals('/' . $token, rawurldecode($requestPath))) {
        respond(404);
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    if ($method === 'GET') {
        respond(204);
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(405);
    }

    $responseSequence = $_GET['responses'] ?? null;
    $supportedSequences = [
        '204' => [204],
        '500,204' => [500, 204],
        '400' => [400],
    ];
    if (!is_string($responseSequence) || !isset($supportedSequences[$responseSequence])) {
        respond(400);
    }

    $body = file_get_contents('php://input');
    if (!is_string($body)) {
        respond(500);
    }

    $envelope = json_decode($body, true);
    $envelope = is_array($envelope) ? $envelope : [];
    $eventId = is_string($envelope['id'] ?? null) ? $envelope['id'] : '';
    $eventType = is_string($envelope['event_type'] ?? null) ? $envelope['event_type'] : '';
    $occurredAt = is_string($envelope['occurred_at'] ?? null) ? $envelope['occurred_at'] : '';

    $headers = requestHeaders();
    $providedSignature = $headers['X-FCHub-Signature'];
    $expectedSignature = hash_hmac('sha256', $body, $secret);
    $signatureValid = strlen($providedSignature) === 64
        && ctype_xdigit($providedSignature)
        && hash_equals($expectedSignature, strtolower($providedSignature));

    $timestampValid = $occurredAt !== ''
        && hash_equals($occurredAt, $headers['X-FCHub-Timestamp'])
        && isValidTimestamp($headers['X-FCHub-Timestamp']);

    $log = @fopen($logPath, 'c+');
    if (!is_resource($log) || !flock($log, LOCK_EX)) {
        if (is_resource($log)) {
            fclose($log);
        }
        respond(500);
    }

    try {
        $records = readRecords($log);
        $sequenceOffset = count(array_filter(
            $records,
            static fn(array $record): bool => ($record['response_sequence'] ?? null) === $responseSequence
        ));
        $codes = $supportedSequences[$responseSequence];
        $responseCode = $codes[min($sequenceOffset, count($codes) - 1)];

        $record = [
            'ordinal' => count($records) + 1,
            'headers' => $headers,
            'body_sha256' => hash('sha256', $body),
            'signature_valid' => $signatureValid,
            'delivery_valid' => $eventId !== '' && hash_equals($eventId, $headers['X-FCHub-Delivery']),
            'timestamp_valid' => $timestampValid,
            'event_type_valid' => $eventType !== '' && hash_equals($eventType, $headers['X-FCHub-Event']),
            'event_id' => $eventId,
            'event_type' => $eventType,
            'response_code' => $responseCode,
            'response_sequence' => $responseSequence,
        ];

        fseek($log, 0, SEEK_END);
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (fwrite($log, $encoded . PHP_EOL) === false || !fflush($log)) {
            throw new RuntimeException('Unable to append the receiver audit record.');
        }
    } catch (Throwable) {
        flock($log, LOCK_UN);
        fclose($log);
        respond(500);
    }

    flock($log, LOCK_UN);
    fclose($log);
    respond($responseCode, $responseCode === 400 ? str_repeat('x', 4096) : '');
}

/** @return array<string, string> */
function requestHeaders(): array
{
    return [
        'Content-Type' => trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')),
        'X-FCHub-Event' => trim((string) ($_SERVER['HTTP_X_FCHUB_EVENT'] ?? '')),
        'X-FCHub-Delivery' => trim((string) ($_SERVER['HTTP_X_FCHUB_DELIVERY'] ?? '')),
        'X-FCHub-Timestamp' => trim((string) ($_SERVER['HTTP_X_FCHUB_TIMESTAMP'] ?? '')),
        'X-FCHub-Signature' => trim((string) ($_SERVER['HTTP_X_FCHUB_SIGNATURE'] ?? '')),
    ];
}

/** @param resource $log @return list<array<string, mixed>> */
function readRecords($log): array
{
    rewind($log);
    $content = stream_get_contents($log);
    if (!is_string($content) || trim($content) === '') {
        return [];
    }

    $records = [];
    foreach (preg_split('/\R/', trim($content)) ?: [] as $line) {
        $record = json_decode($line, true);
        if (is_array($record)) {
            $records[] = $record;
        }
    }

    return $records;
}

function isValidTimestamp(string $timestamp): bool
{
    if ($timestamp === '') {
        return false;
    }

    try {
        new DateTimeImmutable($timestamp);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function respond(int $status, string $body = ''): never
{
    http_response_code($status);
    if ($body !== '') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
    }
    exit;
}

function failStartup(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(64);
}
