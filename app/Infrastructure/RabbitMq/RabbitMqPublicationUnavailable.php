<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

use RuntimeException;

final class RabbitMqPublicationUnavailable extends RuntimeException {}
