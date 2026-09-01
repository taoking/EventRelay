<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;

interface EndpointPageRepository
{
    public function page(CoreListPageRequest $request): CoreListPage;
}
