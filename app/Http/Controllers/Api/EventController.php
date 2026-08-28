<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Event\CreateEvent;
use App\Application\Event\EventNotFound;
use App\Application\Event\FindEvent;
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
        $event = $createEvent->handle(
            $request->string('type')->toString(),
            $request->payload(),
        );

        return (new EventResource($event))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
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
