<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Webhook;

use App\Application\Delivery\DnsResolver;
use App\Application\Delivery\UnsafeWebhookTarget;
use App\Infrastructure\Webhook\PhpWebhookTargetResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpWebhookTargetResolverTest extends TestCase
{
    #[Test]
    public function it_allows_only_global_unicast_dns_results_and_pins_a_deterministic_safe_address(): void
    {
        $resolver = new PhpWebhookTargetResolver(new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8', '1.1.1.1'];
            }
        });

        $target = $resolver->resolve('https://receiver.example/webhook');

        self::assertSame('1.1.1.1', $target->ip);
        self::assertSame('receiver.example', $target->host);
        self::assertSame(443, $target->port);
        self::assertFalse($target->isIpLiteral);
    }

    #[Test]
    public function it_allows_a_global_ipv6_address_from_dns_without_requiring_real_network_access(): void
    {
        $resolver = new PhpWebhookTargetResolver(new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['2606:4700:4700::1111'];
            }
        });

        $target = $resolver->resolve('https://receiver.example/webhook');

        self::assertSame('2606:4700:4700::1111', $target->ip);
        self::assertFalse($target->isIpLiteral);
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function mixedUnsafeDnsResults(): iterable
    {
        yield 'private ipv4' => [['1.1.1.1', '10.0.0.1']];
        yield 'ipv4 multicast' => [['1.1.1.1', '224.0.0.1']];
        yield 'ipv6 ula' => [['2606:4700:4700::1111', 'fc00::1']];
    }

    #[Test]
    #[DataProvider('mixedUnsafeDnsResults')]
    public function it_fails_closed_when_any_dns_result_is_not_global_unicast(array $ips): void
    {
        $resolver = new PhpWebhookTargetResolver(new class($ips) implements DnsResolver
        {
            /** @param list<string> $ips */
            public function __construct(private readonly array $ips) {}

            public function resolve(string $host): array
            {
                return $this->ips;
            }
        });

        $this->expectException(UnsafeWebhookTarget::class);
        $resolver->resolve('https://receiver.example/webhook');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unsafeIpLiterals(): iterable
    {
        yield 'ipv4 unspecified' => ['0.0.0.0'];
        yield 'ipv4 private 10' => ['10.0.0.1'];
        yield 'ipv4 private 172 start' => ['172.16.0.1'];
        yield 'ipv4 private 172 end' => ['172.31.255.255'];
        yield 'ipv4 private 192' => ['192.168.0.1'];
        yield 'ipv4 loopback' => ['127.0.0.1'];
        yield 'ipv4 link local' => ['169.254.169.254'];
        yield 'ipv4 shared start' => ['100.64.0.1'];
        yield 'ipv4 shared end' => ['100.127.255.254'];
        yield 'ipv4 documentation 192' => ['192.0.2.1'];
        yield 'ipv4 documentation 198' => ['198.51.100.1'];
        yield 'ipv4 documentation 203' => ['203.0.113.1'];
        yield 'ipv4 benchmarking start' => ['198.18.0.1'];
        yield 'ipv4 benchmarking end' => ['198.19.255.254'];
        yield 'ipv4 multicast start' => ['224.0.0.1'];
        yield 'ipv4 multicast end' => ['239.255.255.255'];
        yield 'ipv4 reserved' => ['240.0.0.1'];
        yield 'ipv4 broadcast' => ['255.255.255.255'];
        yield 'ipv6 unspecified' => ['::'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 ula fc' => ['fc00::1'];
        yield 'ipv6 ula fd' => ['fd00::1'];
        yield 'ipv6 link local' => ['fe80::1'];
        yield 'ipv6 multicast link local' => ['ff02::1'];
        yield 'ipv6 multicast site local' => ['ff05::1'];
        yield 'ipv6 documentation' => ['2001:db8::1'];
    }

    #[Test]
    #[DataProvider('unsafeIpLiterals')]
    public function it_rejects_non_global_unicast_ip_literals_with_the_same_policy_as_dns_results(string $ip): void
    {
        $resolver = new PhpWebhookTargetResolver(new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                self::fail('IP literals must not be resolved through DNS.');
            }
        });

        $url = str_contains($ip, ':') ? "http://[{$ip}]/webhook" : "http://{$ip}/webhook";

        $this->expectException(UnsafeWebhookTarget::class);
        $resolver->resolve($url);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'localhost' => ['http://localhost/webhook'];
        yield 'localhost suffix' => ['http://api.localhost/webhook'];
        yield 'userinfo' => ['https://user:password@receiver.example/webhook'];
        yield 'unsupported scheme' => ['ftp://receiver.example/webhook'];
    }

    #[Test]
    #[DataProvider('unsafeUrls')]
    public function it_rejects_unsafe_url_forms_before_transport(string $url): void
    {
        $resolver = new PhpWebhookTargetResolver(new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['1.1.1.1'];
            }
        });

        $this->expectException(UnsafeWebhookTarget::class);
        $resolver->resolve($url);
    }
}
