<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Clock\Clock;
use App\Domain\Delivery\DeliveryId;

final readonly class ReplayFailedDelivery
{
    public function __construct(private DeliveryReplayCreator $replays, private Clock $clock) {}

    public function handle(string $sourceDeliveryId, ?string $idempotencyKey): ReplayDeliveryCreation
    {
        if ($idempotencyKey === null || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $idempotencyKey) !== 1) {
            throw new InvalidReplayIdempotencyKey('Idempotency-Key must be 1..128 characters from [A-Za-z0-9._:-].');
        }

        $source = DeliveryId::fromString($sourceDeliveryId);
        $creationKey = 'replay:'.hash('sha256', $source->toString()."\n".$idempotencyKey);

        return $this->replays->createReplay($source, $creationKey, $this->clock->now());
    }
}
