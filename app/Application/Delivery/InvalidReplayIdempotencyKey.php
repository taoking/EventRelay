<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use InvalidArgumentException;

final class InvalidReplayIdempotencyKey extends InvalidArgumentException {}
