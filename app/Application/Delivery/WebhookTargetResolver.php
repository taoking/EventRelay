<?php

declare(strict_types=1);

namespace App\Application\Delivery;

interface WebhookTargetResolver
{
    public function resolve(string $targetUrl): WebhookTarget;
}
