<?php

declare(strict_types=1);

namespace App\Application\DeadLetter;

use InvalidArgumentException;

final class InvalidDeadLetterCursor extends InvalidArgumentException {}
