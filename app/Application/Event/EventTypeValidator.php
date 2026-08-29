<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\EventType;
use InvalidArgumentException;

final class EventTypeValidator
{
    public static function isValid(string $type): bool
    {
        try {
            EventType::fromString($type);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
