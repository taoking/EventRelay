<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\Event;

interface EventRepository
{
    public function save(Event $event): Event;

    public function find(string $id): ?Event;
}
