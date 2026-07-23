<?php

declare(strict_types=1);

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

final class WebhookRetryPolicy
{
    public const DELAYS = [60, 300, 1800, 7200, 21600, 86400];
    public const MAX_ATTEMPTS = 7;

    /**
     * @param array<string, mixed>|\WP_Error $response
     * @return array{outcome:string, code:?int, body:string, error:string, next_timestamp:?int}
     */
    public function classify(int $attempt, array|\WP_Error $response, int $now): array
    {
        if ($attempt < 1 || $attempt > self::MAX_ATTEMPTS || $now < 0) {
            throw new \InvalidArgumentException('Invalid webhook attempt classification.');
        }

        if (is_wp_error($response)) {
            $errorCode = (string) $response->get_error_code();
            $error = str_starts_with($errorCode, 'webhook_')
                ? $errorCode
                : 'webhook_transport_error';

            return $this->failure($attempt, null, '', $error, $now, null);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = $this->boundedUtf8(wp_remote_retrieve_body($response), 2048);
        if ($code >= 200 && $code <= 299) {
            return [
                'outcome' => 'succeeded',
                'code' => $code,
                'body' => $body,
                'error' => '',
                'next_timestamp' => null,
            ];
        }

        $retryAfter = null;
        if ($code === 429 || $code === 503) {
            $retryAfter = $this->retryAfter(wp_remote_retrieve_header($response, 'retry-after'), $now);
        }

        return $this->failure($attempt, $code, $body, 'webhook_http_' . $code, $now, $retryAfter);
    }

    /** @return array{outcome:string, code:?int, body:string, error:string, next_timestamp:?int} */
    private function failure(
        int $attempt,
        ?int $code,
        string $body,
        string $error,
        int $now,
        ?int $retryAfter
    ): array {
        if ($attempt >= self::MAX_ATTEMPTS) {
            return [
                'outcome' => 'failed',
                'code' => $code,
                'body' => $body,
                'error' => $error,
                'next_timestamp' => null,
            ];
        }

        $delay = $retryAfter ?? self::DELAYS[$attempt - 1];

        return [
            'outcome' => 'retry',
            'code' => $code,
            'body' => $body,
            'error' => $error,
            'next_timestamp' => $now + $delay,
        ];
    }

    private function retryAfter(mixed $value, int $now): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[0-9]+$/D', $value) === 1) {
            return min(86400, (int) $value);
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!D, d M Y H:i:s \G\M\T',
            $value,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        if ($date->format('D, d M Y H:i:s \G\M\T') !== $value) {
            return null;
        }

        return min(86400, max(0, $date->getTimestamp() - $now));
    }

    private function boundedUtf8(string $value, int $bytes): string
    {
        $value = substr($value, 0, $bytes);
        if (preg_match('//u', $value) !== 1) {
            $converted = function_exists('iconv') ? iconv('UTF-8', 'UTF-8//IGNORE', $value) : false;
            $value = is_string($converted) ? $converted : preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value);
            $value = is_string($value) ? $value : '';
        }
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }
}
