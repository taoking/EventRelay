<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Application\Operations\CheckOperationalReadiness;
use App\Application\Operations\CollectOperationalSnapshot;
use App\Application\Operations\OperationalDataUnavailable;
use App\Application\Operations\OperationalMetricsRenderer;
use App\Application\Operations\OperationalSnapshotConsistencyViolation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class OperationsController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'alive']);
    }

    public function ready(CheckOperationalReadiness $readiness): JsonResponse
    {
        if ($readiness->mysqlIsAvailable()) {
            return response()->json([
                'status' => 'ready',
                'checks' => ['mysql' => 'up'],
            ]);
        }

        return response()->json([
            'status' => 'not_ready',
            'checks' => ['mysql' => 'down'],
        ], HttpResponse::HTTP_SERVICE_UNAVAILABLE);
    }

    public function metrics(
        CollectOperationalSnapshot $snapshots,
        OperationalMetricsRenderer $renderer,
    ): Response {
        try {
            $body = $renderer->render($snapshots->handle(), (string) config('delivery.transport'));
        } catch (OperationalDataUnavailable|OperationalSnapshotConsistencyViolation) {
            return response('Service unavailable.\n', HttpResponse::HTTP_SERVICE_UNAVAILABLE, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        return response($body, HttpResponse::HTTP_OK, [
            'Content-Type' => $renderer->contentType(),
        ]);
    }
}
