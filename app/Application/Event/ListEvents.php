<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;

final readonly class ListEvents
{
    public function __construct(
        private EventPageRepository $events,
    ) {}

    public function handle(CoreListPageRequest $request): CoreListPage
    {
        return $this->events->page($request);
    }
}
