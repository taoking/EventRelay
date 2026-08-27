<?php

declare(strict_types=1);

namespace Tests\Architecture\Fixtures\DeptracViolation\App\Domain;

use Illuminate\Support\Facades\Cache;

final class ForbiddenFrameworkDependency
{
    public function read(): mixed
    {
        return Cache::get('deptrac-negative-validation');
    }
}
