<?php

declare(strict_types=1);

namespace App\Infrastructure\DeadLetter;

use App\Application\DeadLetter\DeadLetterCursor;
use App\Application\DeadLetter\DeadLetterCursorCodec;
use App\Application\DeadLetter\DeadLetterFilter;
use App\Application\DeadLetter\InvalidDeadLetterCursor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use InvalidArgumentException;
use JsonException;

final readonly class LaravelDeadLetterCursorCodec implements DeadLetterCursorCodec
{
    public function __construct(private Encrypter $encrypter) {}

    public function encode(DeadLetterCursor $cursor, DeadLetterFilter $filter): string
    {
        return $this->encrypter->encryptString(json_encode([
            'v' => 1,
            'failed_at' => $cursor->toStorage(),
            'delivery_id' => $cursor->deliveryId,
            'filter_fingerprint' => $filter->fingerprint(),
        ], JSON_THROW_ON_ERROR));
    }

    public function decode(string $cursor, DeadLetterFilter $filter): DeadLetterCursor
    {
        try {
            $payload = json_decode($this->encrypter->decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)
                || (($payload['v'] ?? null) !== 1)
                || ! is_string($payload['failed_at'] ?? null)
                || ! is_string($payload['delivery_id'] ?? null)
                || ! is_string($payload['filter_fingerprint'] ?? null)
                || ! hash_equals($filter->fingerprint(), $payload['filter_fingerprint'])) {
                throw new InvalidDeadLetterCursor('Cursor payload is invalid.');
            }

            return DeadLetterCursor::fromStorage($payload['failed_at'], $payload['delivery_id']);
        } catch (DecryptException|JsonException|InvalidArgumentException) {
            throw new InvalidDeadLetterCursor('Cursor is invalid.');
        }
    }
}
