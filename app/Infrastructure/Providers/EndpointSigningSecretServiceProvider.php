<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\EndpointSigningSecret\EndpointSigningSecretRepository;
use App\Application\EndpointSigningSecret\SecretCipher;
use App\Application\EndpointSigningSecret\SecretGenerator;
use App\Infrastructure\EndpointSigningSecret\LaravelSecretCipher;
use App\Infrastructure\EndpointSigningSecret\RandomSecretGenerator;
use App\Infrastructure\Persistence\Eloquent\EloquentEndpointSigningSecretRepository;
use Illuminate\Support\ServiceProvider;

final class EndpointSigningSecretServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SecretGenerator::class, RandomSecretGenerator::class);
        $this->app->bind(SecretCipher::class, LaravelSecretCipher::class);
        $this->app->bind(EndpointSigningSecretRepository::class, EloquentEndpointSigningSecretRepository::class);
    }
}
