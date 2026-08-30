<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Delivery\DnsResolver;
use App\Application\Delivery\HmacWebhookSigner;
use App\Application\Delivery\WebhookSigner;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Infrastructure\Webhook\CurlTransportDriver;
use App\Infrastructure\Webhook\CurlWebhookTransport;
use App\Infrastructure\Webhook\NativeCurlTransportDriver;
use App\Infrastructure\Webhook\PhpDnsResolver;
use App\Infrastructure\Webhook\PhpWebhookTargetResolver;
use Illuminate\Support\ServiceProvider;

final class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CurlTransportDriver::class, NativeCurlTransportDriver::class);
        $this->app->bind(DnsResolver::class, PhpDnsResolver::class);
        $this->app->bind(WebhookTargetResolver::class, PhpWebhookTargetResolver::class);
        $this->app->bind(WebhookTransport::class, CurlWebhookTransport::class);
        $this->app->bind(WebhookSigner::class, HmacWebhookSigner::class);
    }
}
