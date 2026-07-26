<?php

namespace App\Support;

use RuntimeException;

/**
 * SSRF guard for user-supplied fetch targets (json_api sources).
 *
 * Unlike career_page URLs — fetched off-host via Firecrawl — a json_api source
 * is fetched directly from the Laravel worker, so a hostile/misconfigured URL
 * can reach cloud metadata (169.254.169.254), localhost, or private ranges.
 * This guard is intentionally proportionate: scheme allowlist + resolve-and-
 * block private/reserved IPs. Redirect hops are re-validated by the caller and
 * response size is capped there.
 */
class UrlGuard
{
    /** Throw if the URL isn't a safe, public http(s) target. */
    public static function assertPublicHttpUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException("Invalid URL: {$url}");
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Unsupported URL scheme '{$scheme}'. Only http and https are allowed.");
        }

        $host = $parts['host'];

        foreach (self::resolveIps($host) as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new RuntimeException("Refusing to fetch a private or reserved address ({$host} → {$ip}).");
            }
        }
    }

    /** True when the URL is a safe public http(s) target (no throw). */
    public static function isPublicHttpUrl(string $url): bool
    {
        try {
            self::assertPublicHttpUrl($url);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Resolve a host to the IPs we must vet. A literal IP resolves to itself; a
     * name is resolved via DNS. An unresolvable host yields no IPs — we let the
     * request proceed (the HTTP client will fail it) rather than block, since a
     * literal private IP is still caught directly above and a name that *does*
     * resolve into a private range is still rejected.
     *
     * @return array<int, string>
     */
    protected static function resolveIps(string $host): array
    {
        $host = trim($host, '[]'); // strip IPv6 brackets

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $ips = [];
        foreach ($records as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));

        if ($ips === []) {
            // Fall back to gethostbyname (IPv4 only); if it can't resolve it
            // returns the host unchanged, which won't validate as an IP below.
            $resolved = @gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }

    /** Reject loopback, link-local, private and other non-public ranges. */
    protected static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
