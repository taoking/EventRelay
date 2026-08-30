<?php

declare(strict_types=1);

namespace App\Application\EndpointSigningSecret;

use App\Domain\EndpointSigningSecret\EndpointSigningSecret;
use App\Domain\EndpointSigningSecret\EndpointSigningSecretId;
use DateTimeImmutable;

interface EndpointSigningSecretRepository
{
    /**
     * 在端点行锁内创建新版本、退休旧 current key，并更新 current pointer。
     */
    public function rotate(string $endpointId, EndpointSigningSecretId $keyId, string $encryptedSecret, DateTimeImmutable $now): EndpointSigningSecret;

    /**
     * @throws SigningSecretNotFound
     */
    public function plaintext(EndpointSigningSecretId $keyId): string;
}
