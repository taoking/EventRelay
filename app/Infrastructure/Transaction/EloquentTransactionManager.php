<?php

declare(strict_types=1);

namespace App\Infrastructure\Transaction;

use App\Application\Transaction\TransactionManager;
use Closure;
use Illuminate\Support\Facades\DB;

final class EloquentTransactionManager implements TransactionManager
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }
}
