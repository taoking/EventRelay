<?php

declare(strict_types=1);

namespace App\Application\Event;

use RuntimeException;

final class EventIngressIdempotencyAlreadyExists extends RuntimeException {}
