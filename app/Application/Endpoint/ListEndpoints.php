<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;

final readonly class ListEndpoints
{
    public function __construct(
        private EndpointPageRepository $endpoints,
    ) {}

    public function handle(CoreListPageRequest $request): CoreListPage
    {
        return $this->endpoints->page($request);
    }
}
