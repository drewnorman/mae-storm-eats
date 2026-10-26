<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

use WP_Error;

final class SourceFetcher
{
    public const MAX_BYTES = 2_000_000;

    public function fetch(string $url): string|WP_Error
    {
        $validated = $this->validateUrl($url);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $current = $validated;
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $response = wp_safe_remote_get($current, [
                'timeout' => 15,
                'redirection' => 0,
                'limit_response_size' => self::MAX_BYTES + 1,
                'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
                'user-agent' => 'MaeStormEats/1.0',
            ]);
            if (is_wp_error($response)) {
                return $response;
            }
            $status = wp_remote_retrieve_response_code($response);
            if ($status >= 300 && $status < 400) {
                $location = wp_remote_retrieve_header($response, 'location');
                if (! is_string($location) || $location === '') {
                    return new WP_Error('invalid_redirect', 'Source returned an empty redirect.');
                }
                $next = $this->resolveRedirect($current, $location);
                $validated = $this->validateUrl($next);
                if (is_wp_error($validated)) {
                    return $validated;
                }
                $current = $validated;
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return new WP_Error('source_http_error', sprintf('Source returned HTTP %d.', $status));
            }
            $body = wp_remote_retrieve_body($response);
            if (strlen($body) > self::MAX_BYTES) {
                return new WP_Error('source_too_large', 'Source response exceeded the 2 MB limit.');
            }
            return $body;
        }
        return new WP_Error('too_many_redirects', 'Source exceeded five redirects.');
    }

    public function validateUrl(string $url): string|WP_Error
    {
        $url = esc_url_raw($url, ['http', 'https']);
        $parts = wp_parse_url($url);
        if ($url === '' || ! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return new WP_Error('invalid_source_url', 'Only HTTP and HTTPS source URLs are allowed.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return new WP_Error('private_source_host', 'Private source hosts are not allowed.');
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (dns_get_record($host, DNS_A | DNS_AAAA) ?: []);
        if ($addresses === []) {
            return new WP_Error('unresolved_source_host', 'Source host could not be resolved.');
        }
        foreach ($addresses as $record) {
            $ip = is_string($record) ? $record : ($record['ip'] ?? $record['ipv6'] ?? '');
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return new WP_Error('private_source_address', 'Source resolves to a private or reserved address.');
            }
        }
        return $url;
    }

    private function resolveRedirect(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = wp_parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        return $origin . '/' . ltrim($location, '/');
    }
}
