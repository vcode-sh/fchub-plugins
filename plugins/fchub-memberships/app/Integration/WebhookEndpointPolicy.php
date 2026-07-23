<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

final class WebhookEndpointPolicy
{
    public const MAX_ENDPOINTS = 10;

    private string $environment;

    /** @var \Closure(string): array */
    private \Closure $resolver;

    public function __construct(?string $environment = null, ?callable $resolver = null)
    {
        $this->environment = $environment ?? wp_get_environment_type();
        $this->resolver = $resolver !== null
            ? \Closure::fromCallable($resolver)
            : static fn(string $host): array => self::resolveHost($host);
    }

    /** @return list<string> */
    public function normalise(string $raw): array
    {
        $normalised = [];

        foreach ($this->lines($raw) as $url) {
            $canonical = $this->canonicalise($url);
            if ($canonical !== null) {
                $normalised[$canonical] = $canonical;
            }
        }

        return array_values($normalised);
    }

    public function validate(string $raw): true|\WP_Error
    {
        $normalised = [];

        foreach ($this->lines($raw) as $url) {
            $canonical = $this->canonicalise($url);
            if ($canonical === null) {
                return $this->error();
            }

            $normalised[$canonical] = true;
        }

        if (count($normalised) > self::MAX_ENDPOINTS) {
            return $this->error(sprintf(
                __('Configure no more than %d webhook destinations.', 'fchub-memberships'),
                self::MAX_ENDPOINTS
            ));
        }

        return true;
    }

    public function equivalent(string $first, string $second): bool
    {
        if ($first === $second) {
            return true;
        }

        $firstUrls = $this->normaliseSyntax($first);
        $secondUrls = $this->normaliseSyntax($second);
        if ($firstUrls === null || $secondUrls === null) {
            return false;
        }

        sort($firstUrls);
        sort($secondUrls);
        return $firstUrls === $secondUrls;
    }

    /** @return list<string> */
    private function lines(string $raw): array
    {
        $lines = preg_split('/\R/', $raw) ?: [];

        return array_values(array_filter(
            array_map(static fn(string $url): string => trim($url), $lines),
            static fn(string $url): bool => $url !== ''
        ));
    }

    private function canonicalise(string $url): ?string
    {
        $parsed = $this->canonicaliseSyntax($url);
        if ($parsed === null) {
            return null;
        }

        if ($parsed['scheme'] === 'http'
            && !in_array($this->environment, ['local', 'development'], true)
        ) {
            return null;
        }

        $addresses = filter_var($parsed['host'], FILTER_VALIDATE_IP) !== false
            ? [$parsed['host']]
            : ($this->resolver)($parsed['host']);
        if ($addresses === []) {
            return null;
        }

        foreach (array_unique($addresses) as $address) {
            if (!is_string($address) || !$this->isPublicAddress($address)) {
                return null;
            }
        }

        return wp_http_validate_url($parsed['canonical']) === false ? null : $parsed['canonical'];
    }

    /**
     * @return array{canonical:string, scheme:string, host:string}|null
     */
    private function canonicaliseSyntax(string $url): ?array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || array_key_exists('fragment', $parts)
        ) {
            return null;
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        if ($host === '') {
            return null;
        }

        $displayHost = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $includePort = $port !== null
            && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80));
        $canonical = $scheme . '://' . $displayHost;
        if ($includePort) {
            $canonical .= ':' . $port;
        }
        $canonical .= (string) ($parts['path'] ?? '');
        if (array_key_exists('query', $parts)) {
            $canonical .= '?' . (string) $parts['query'];
        }

        return ['canonical' => $canonical, 'scheme' => $scheme, 'host' => $host];
    }

    /** @return list<string>|null */
    private function normaliseSyntax(string $raw): ?array
    {
        $normalised = [];

        foreach ($this->lines($raw) as $url) {
            $parsed = $this->canonicaliseSyntax($url);
            if ($parsed === null) {
                return null;
            }
            $normalised[$parsed['canonical']] = $parsed['canonical'];
        }

        return array_values($normalised);
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
    }

    /** @return list<string> */
    private static function resolveHost(string $host): array
    {
        $addresses = [];
        $records = function_exists('dns_get_record')
            ? @dns_get_record($host, DNS_A | DNS_AAAA)
            : false;
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }

        $ipv4 = function_exists('gethostbynamel') ? @gethostbynamel($host) : false;
        if (is_array($ipv4)) {
            $addresses = array_merge($addresses, $ipv4);
        }

        return array_values(array_unique($addresses));
    }

    private function error(?string $message = null): \WP_Error
    {
        return new \WP_Error(
            'fchub_invalid_webhook_endpoints',
            $message ?? __('Enter valid public webhook URLs using HTTPS.', 'fchub-memberships'),
            ['status' => 422]
        );
    }
}
