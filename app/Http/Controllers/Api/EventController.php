<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\CoreList\CoreListPageRequest;
use App\Application\CoreList\InvalidPaginationCursor;
use App\Application\CoreList\InvalidPaginationLimit;
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
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class EventController extends Controller
{
    public function index(Request $request, ListEvents $listEvents): AnonymousResourceCollection|JsonResponse
    {
        try {
            /** @var array<string, mixed> $query */
            $query = $request->query();
            $page = $listEvents->handle(CoreListPageRequest::fromQuery($query));
        } catch (InvalidPaginationLimit) {
            return response()->json([
                'code' => 'invalid_pagination_limit',
                'message' => 'Pagination limit is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidPaginationCursor) {
            return response()->json([
                'code' => 'invalid_pagination_cursor',
                'message' => 'Pagination cursor is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return EventResource::collection($page->items)->additional([
            'meta' => ['limit' => $page->limit, 'next_cursor' => $page->nextCursor],
        ]);
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
