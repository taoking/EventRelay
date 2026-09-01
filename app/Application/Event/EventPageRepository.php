<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;

interface EventPageRepository
{
    public function page(CoreListPageRequest $request): CoreListPage;
}
