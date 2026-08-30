<?php

declare(strict_types=1);

namespace App\Application\Delivery;

interface DnsResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
