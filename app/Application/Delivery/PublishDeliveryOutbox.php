<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Clock\Clock;
use DateInterval;
use InvalidArgumentException;

final readonly class PublishDeliveryOutbox
{
    public const int DefaultLimit = 100;

    public const int MaximumLimit = 1000;

    public const int ClaimLeaseSeconds = 60;

    public function __construct(
        private DeliveryOutboxPublisherRepository $outbox,
        private DeliveryTransport $transport,
        private Clock $clock,
    ) {}

    public function handle(int $limit = self::DefaultLimit): PublishDeliveryOutboxResult
    {
        if ($limit < 1 || $limit > self::MaximumLimit) {
            throw new InvalidArgumentException(sprintf('The outbox publication limit must be between 1 and %d.', self::MaximumLimit));
        }

        $now = $this->clock->now();
        $messages = $this->outbox->claim(
            $limit,
            $now,
            $now->add(new DateInterval('PT'.self::ClaimLeaseSeconds.'S')),
        );
        $published = 0;
        $failed = 0;
        $lostLease = 0;

        foreach ($messages as $message) {
            try {
                $this->transport->publish($message->intent->deliveryId);
            } catch (DeliveryTransportUnavailable $exception) {
                if ($this->outbox->releaseAfterKnownPublicationFailure(
                    $message->publicId,
                    $message->claimToken,
                    $exception->transport,
                    $this->clock->now(),
                )) {
                    $failed++;
                } else {
                    $lostLease++;
                }

                continue;
            }

            if ($this->outbox->markPublished($message->publicId, $message->claimToken, $this->clock->now())) {
                $published++;
            } else {
                $lostLease++;
            }
        }

        return new PublishDeliveryOutboxResult($published, $failed, $lostLease);
    }
}
