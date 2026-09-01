<?php

declare(strict_types=1);

namespace App\Infrastructure\CoreList;

use App\Application\CoreList\InvalidPaginationCursor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use InvalidArgumentException;
use JsonException;

final readonly class LaravelCoreListCursorCodec
{
    public function __construct(private Encrypter $encrypter) {}

    public function encode(CoreListCursor $cursor): string
    {
        return $this->encrypter->encryptString(json_encode([
            'v' => 1,
            'resource' => $cursor->resource->value,
            'after' => $cursor->afterKey,
            'upper' => $cursor->upperKey,
        ], JSON_THROW_ON_ERROR));
    }

    public function decode(string $cursor, CoreListResource $resource): CoreListCursor
    {
        try {
            $payload = json_decode($this->encrypter->decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)
                || ($payload['v'] ?? null) !== 1
                || ($payload['resource'] ?? null) !== $resource->value
                || ! array_key_exists('after', $payload)
                || ! array_key_exists('upper', $payload)) {
                throw new InvalidPaginationCursor('Pagination cursor payload is invalid.');
            }

            return CoreListCursor::fromStorage($resource, $payload['after'], $payload['upper']);
        } catch (DecryptException|JsonException|InvalidArgumentException) {
            throw new InvalidPaginationCursor('Pagination cursor is invalid.');
        }
    }
}
