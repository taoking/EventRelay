<?php

declare(strict_types=1);

namespace App\Infrastructure\EndpointSigningSecret;

use App\Application\EndpointSigningSecret\SecretCipher;
use Illuminate\Contracts\Encryption\Encrypter;

final readonly class LaravelSecretCipher implements SecretCipher
{
    public function __construct(
        private Encrypter $encrypter,
    ) {}

    public function encrypt(string $plaintext): string
    {
        return $this->encrypter->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        return $this->encrypter->decryptString($ciphertext);
    }
}
