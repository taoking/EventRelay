<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Delivery\DeliveryData;
use App\Application\Delivery\DeliveryNotFound;
use App\Application\Delivery\DeliveryNotReplayable;
use App\Application\Delivery\FindDelivery;
use App\Application\Delivery\InvalidReplayIdempotencyKey;
use App\Application\Delivery\ListDeliveries;
use App\Application\Delivery\ListDeliveryAttempts;
use App\Application\Delivery\ReplayEndpointUnavailable;
use App\Application\Delivery\ReplayFailedDelivery;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryAttemptResource;
use App\Http\Resources\DeliveryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class DeliveryController extends Controller
{
    public function index(ListDeliveries $listDeliveries): AnonymousResourceCollection
    {
        return DeliveryResource::collection($listDeliveries->handle());
    }

    public function show(string $id, FindDelivery $findDelivery): DeliveryResource|JsonResponse
    {
        try {
            return new DeliveryResource($findDelivery->handle($id));
        } catch (DeliveryNotFound) {
            return response()->json([
                'message' => 'Delivery not found.',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    public function attempts(string $id, ListDeliveryAttempts $attempts): AnonymousResourceCollection|JsonResponse
    {
        try {
            return DeliveryAttemptResource::collection($attempts->handle($id));
        } catch (DeliveryNotFound) {
            return response()->json(['message' => 'Delivery not found.'], Response::HTTP_NOT_FOUND);
        }
    }

    public function replay(string $id, ReplayFailedDelivery $replay): JsonResponse
    {
        try {
            $result = $replay->handle($id, request()->header('Idempotency-Key'));

            return (new DeliveryResource(DeliveryData::fromDomain($result->delivery)))
                ->response()
                ->setStatusCode($result->created ? Response::HTTP_CREATED : Response::HTTP_OK);
        } catch (DeliveryNotFound) {
            return response()->json(['code' => 'delivery_not_found', 'message' => 'Delivery not found.'], Response::HTTP_NOT_FOUND);
        } catch (DeliveryNotReplayable) {
            return response()->json(['code' => 'delivery_not_replayable', 'message' => 'Delivery is not replayable.'], Response::HTTP_CONFLICT);
        } catch (ReplayEndpointUnavailable) {
            return response()->json(['code' => 'replay_endpoint_unavailable', 'message' => 'Replay endpoint is unavailable.'], Response::HTTP_CONFLICT);
        } catch (InvalidReplayIdempotencyKey) {
            return response()->json(['code' => 'invalid_idempotency_key', 'message' => 'Idempotency-Key is invalid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
