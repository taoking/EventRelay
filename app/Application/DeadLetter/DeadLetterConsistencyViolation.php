<?php

declare(strict_types=1);

namespace App\Application\DeadLetter;

use LogicException;

final class DeadLetterConsistencyViolation extends LogicException {}
