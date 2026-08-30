<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Webhook;

use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookTarget;
use App\Infrastructure\Webhook\CurlTransportDriver;
use App\Infrastructure\Webhook\CurlWebhookTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurlWebhookTransportTest extends TestCase
{
    #[Test]
    public function it_installs_ipv6_pinning_and_all_security_critical_options_before_executing(): void
    {
        $driver = new RecordingCurlTransportDriver(302);
        $transport = new CurlWebhookTransport($driver);

        $response = $transport->send(
            new WebhookTarget('https://receiver.example/webhook', 'receiver.example', 443, '2606:4700:4700::1111'),
            new WebhookRequest('{}', ['Content-Type' => 'application/json']),
        );

        self::assertSame(302, $response->statusCode);
        self::assertSame(['receiver.example:443:[2606:4700:4700::1111]'], $driver->options[CURLOPT_RESOLVE]);
        self::assertFalse($driver->options[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($driver->options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $driver->options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame('', $driver->options[CURLOPT_PROXY]);
        self::assertSame('*', $driver->options[CURLOPT_NOPROXY]);
        self::assertSame(2, $driver->options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(5, $driver->options[CURLOPT_TIMEOUT]);
        self::assertArrayHasKey(CURLOPT_WRITEFUNCTION, $driver->options);
        self::assertSame(1, $driver->executeCalls);
    }

    #[Test]
    public function it_uses_an_already_validated_ip_literal_directly_without_a_second_dns_override(): void
    {
        $driver = new RecordingCurlTransportDriver(204);
        $transport = new CurlWebhookTransport($driver);

        $transport->send(
            new WebhookTarget('https://[2606:4700:4700::1111]/webhook', '2606:4700:4700::1111', 443, '2606:4700:4700::1111', true),
            new WebhookRequest('{}', []),
        );

        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $driver->options);
        self::assertSame(1, $driver->executeCalls);
    }

    #[Test]
    public function it_fails_closed_without_executing_when_secure_option_installation_fails(): void
    {
        $driver = new RecordingCurlTransportDriver(200, false);
        $transport = new CurlWebhookTransport($driver);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unable to install secure webhook cURL options.');

        try {
            $transport->send(
                new WebhookTarget('https://receiver.example/webhook', 'receiver.example', 443, '1.1.1.1'),
                new WebhookRequest('{}', []),
            );
        } finally {
            self::assertSame(0, $driver->executeCalls);
        }
    }

    #[Test]
    public function it_disables_environment_proxies_in_the_installed_transport_contract(): void
    {
        $originalHttpProxy = getenv('HTTP_PROXY');
        $originalHttpsProxy = getenv('HTTPS_PROXY');
        $originalAllProxy = getenv('ALL_PROXY');
        putenv('HTTP_PROXY=http://proxy.invalid:8080');
        putenv('HTTPS_PROXY=http://proxy.invalid:8080');
        putenv('ALL_PROXY=http://proxy.invalid:8080');

        try {
            $driver = new RecordingCurlTransportDriver(204);
            (new CurlWebhookTransport($driver))->send(
                new WebhookTarget('https://receiver.example/webhook', 'receiver.example', 443, '1.1.1.1'),
                new WebhookRequest('{}', []),
            );

            self::assertSame('', $driver->options[CURLOPT_PROXY]);
            self::assertSame('*', $driver->options[CURLOPT_NOPROXY]);
        } finally {
            self::restoreEnvironment('HTTP_PROXY', $originalHttpProxy);
            self::restoreEnvironment('HTTPS_PROXY', $originalHttpsProxy);
            self::restoreEnvironment('ALL_PROXY', $originalAllProxy);
        }
    }

    private static function restoreEnvironment(string $name, string|false $value): void
    {
        putenv($value === false ? $name : "{$name}={$value}");
    }
}

final class RecordingCurlTransportDriver implements CurlTransportDriver
{
    /** @var array<int, mixed> */
    public array $options = [];

    public int $executeCalls = 0;

    private object $handle;

    public function __construct(
        private readonly int $statusCode,
        private readonly bool $setOptionsResult = true,
    ) {
        $this->handle = new \stdClass;
    }

    public function init(string $url): object|false
    {
        return $this->handle;
    }

    public function setOptions(object $handle, array $options): bool
    {
        $this->options = $options;

        return $this->setOptionsResult;
    }

    public function execute(object $handle): string|bool
    {
        $this->executeCalls++;

        return true;
    }

    public function errno(object $handle): int
    {
        return 0;
    }

    public function error(object $handle): string
    {
        return '';
    }

    public function responseCode(object $handle): int
    {
        return $this->statusCode;
    }
}
