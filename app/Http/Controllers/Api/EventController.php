<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Event\CreateEvent;
use App\Application\Event\EventIngressIdempotencyConflict;
use App\Application\Event\EventNotFound;
use App\Application\Event\FindEvent;
use App\Application\Event\InvalidEventIngressIdempotencyKey;
use App\Application\Event\ListEvents;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Resources\EventResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class EventController extends Controller
{
    public function index(ListEvents $listEvents): AnonymousResourceCollection
    {
        return EventResource::collection($listEvents->handle());
    }

    public function store(StoreEventRequest $request, CreateEvent $createEvent): JsonResponse
    {
        try {
            $result = $createEvent->handleWithIdempotency(
                $request->string('type')->toString(),
                $request->payload(),
                $request->idempotencyKey(),
            );
        } catch (InvalidEventIngressIdempotencyKey) {
            return response()->json([
                'code' => 'invalid_idempotency_key',
                'message' => 'The Idempotency-Key header is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EventIngressIdempotencyConflict) {
            return response()->json([
                'code' => 'idempotency_key_conflict',
                'message' => 'The Idempotency-Key is already bound to a different event request.',
            ], Response::HTTP_CONFLICT);
        }

        return (new EventResource($result->event))
            ->response()
            ->setStatusCode($result->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(string $id, FindEvent $findEvent): EventResource|JsonResponse
    {
        try {
            return new EventResource($findEvent->handle($id));
        } catch (EventNotFound) {
            return response()->json([
                'message' => 'Event not found.',
            ], Response::HTTP_NOT_FOUND);
        }
    }
}
