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

        $ips = filter_var($host, FILTER_VALIDATE_IP) === false ? $this->dns->resolve($host) : [$host];

        if ($ips === [] || array_filter($ips, fn (string $ip): bool => ! $this->isPublicIp($ip)) !== []) {
            throw new UnsafeWebhookTarget('Target resolved to an unsafe address.');
        }

        sort($ips, SORT_STRING);

        return new WebhookTarget($targetUrl, $host, isset($parts['port']) ? (int) $parts['port'] : (strtolower($scheme) === 'https' ? 443 : 80), $ips[0]);
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        return $host === 'localhost' || str_ends_with($host, '.localhost');
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $normalised = strtolower($ip);

        return $normalised !== '::'
            && $normalised !== '::1'
            && ! str_starts_with($normalised, 'fe80:')
            && ! str_starts_with($normalised, 'fc')
            && ! str_starts_with($normalised, 'fd')
            && ! str_starts_with($normalised, 'ff')
            && ! str_starts_with($normalised, '2001:db8:');
    }
}
