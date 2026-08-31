<?php

declare(strict_types=1);

namespace App\Application\Event;

use InvalidArgumentException;

final class InvalidEventIngressIdempotencyKey extends InvalidArgumentException {}
