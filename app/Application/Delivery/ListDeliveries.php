<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;

final readonly class ListDeliveries
{
    public function __construct(
        private DeliveryPageRepository $deliveries,
    ) {}

    public function handle(CoreListPageRequest $request): CoreListPage
    {
        return $this->deliveries->page($request);
    }
}
