<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Delivery\DnsResolver;
use App\Application\Delivery\UnsafeWebhookTarget;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;

final class PhpWebhookTargetResolver implements WebhookTargetResolver
{
    public function __construct(
        private readonly DnsResolver $dns,
    ) {}

    public function resolve(string $targetUrl): WebhookTarget
    {
        $parts = parse_url($targetUrl);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_array($parts) || ! is_string($scheme) || ! is_string($host)
            || isset($parts['user']) || isset($parts['pass'])
            || ! in_array(strtolower($scheme), ['http', 'https'], true)
            || $host === '' || $this->isLocalHost($host)) {
            throw new UnsafeWebhookTarget('Target URL is not safe.');
        }

        $host = trim($host, '[]');

        $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $ips = $isIpLiteral ? [$host] : $this->dns->resolve($host);

        if ($ips === [] || array_filter($ips, fn (string $ip): bool => ! $this->isPublicIp($ip)) !== []) {
            throw new UnsafeWebhookTarget('Target resolved to an unsafe address.');
        }

        sort($ips, SORT_STRING);

        return new WebhookTarget(
            $targetUrl,
            $host,
            isset($parts['port']) ? (int) $parts['port'] : (strtolower($scheme) === 'https' ? 443 : 80),
            $ips[0],
            $isIpLiteral,
        );
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        return $host === 'localhost' || str_ends_with($host, '.localhost');
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== $ip) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            // PHP's global-range flag does not exclude IPv4 multicast. Webhook
            // targets only permit globally routable unicast addresses.
            return ! $this->isIpv4InRange($ip, '224.0.0.0', 4);
        }

        $normalised = strtolower($ip);

        // These are explicit defence-in-depth exclusions for IPv6 special-use
        // ranges. FILTER_FLAG_GLOBAL_RANGE is the allow-list baseline.
        return ! str_starts_with($normalised, 'fc')
            && ! str_starts_with($normalised, 'fd')
            && ! str_starts_with($normalised, 'fe8')
            && ! str_starts_with($normalised, 'fe9')
            && ! str_starts_with($normalised, 'fea')
            && ! str_starts_with($normalised, 'feb')
            && ! str_starts_with($normalised, 'ff')
            && ! str_starts_with($normalised, '2001:db8:')
            && ! str_starts_with($normalised, '::ffff:');
    }

    private function isIpv4InRange(string $ip, string $network, int $prefixLength): bool
    {
        $packedIp = inet_pton($ip);
        $packedNetwork = inet_pton($network);

        if ($packedIp === false || $packedNetwork === false) {
            return true;
        }

        $ipValue = unpack('N', $packedIp);
        $networkValue = unpack('N', $packedNetwork);

        if ($ipValue === false || $networkValue === false) {
            return true;
        }

        $mask = $prefixLength === 0 ? 0 : (-1 << (32 - $prefixLength));

        return ($ipValue[1] & $mask) === ($networkValue[1] & $mask);
    }
}
