<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use RuntimeException;

final class DeliveryNotReplayable extends RuntimeException {}
