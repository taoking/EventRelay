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
    public function it_fails_closed_when_any_dns_result_is_unsafe_and_pins_a_deterministic_safe_address(): void
    {
        $safe = new PhpWebhookTargetResolver(new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8', '1.1.1.1'];
            }
        });

        $target = $safe->resolve('https://receiver.example/webhook');
        self::assertSame('1.1.1.1', $target->ip);
        self::assertSame('receiver.example', $target->host);
        self::assertSame(443, $target->port);

        $mixed = new PhpWebhookTargetResolver(new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['1.1.1.1', '10.0.0.1'];
            }
        });

        $this->expectException(UnsafeWebhookTarget::class);
        $mixed->resolve('https://receiver.example/webhook');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'localhost' => ['http://localhost/webhook'];
        yield 'ipv4 loopback' => ['http://127.0.0.1/webhook'];
        yield 'private ipv4' => ['http://10.0.0.1/webhook'];
        yield 'link local ipv4' => ['http://169.254.169.254/webhook'];
        yield 'ipv6 loopback' => ['http://[::1]/webhook'];
        yield 'userinfo' => ['https://user:password@receiver.example/webhook'];
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
