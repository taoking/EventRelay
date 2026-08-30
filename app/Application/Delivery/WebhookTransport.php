<?php

declare(strict_types=1);

namespace App\Application\Delivery;

interface WebhookTransport
{
    public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse;
}
