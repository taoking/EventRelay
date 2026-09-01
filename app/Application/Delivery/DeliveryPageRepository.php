<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;

interface DeliveryPageRepository
{
    public function page(CoreListPageRequest $request): CoreListPage;
}
