<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Subscription\EndpointSubscriptions;

final readonly class EndpointSubscriptionData
{
    /**
     * @param  list<string>  $types
     */
    public function __construct(
        public string $endpointId,
        public array $types,
    ) {}

    public static function fromDomain(EndpointSubscriptions $subscriptions): self
    {
        $types = [];

        foreach ($subscriptions->types() as $type) {
            $types[] = $type->toString();
        }

        return new self(
            $subscriptions->endpointId()->toString(),
            $types,
        );
    }
}
