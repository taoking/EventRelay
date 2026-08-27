<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Endpoint\CreateEndpoint;
use App\Application\Endpoint\DeleteEndpoint;
use App\Application\Endpoint\EndpointNotFound;
use App\Application\Endpoint\FindEndpoint;
use App\Application\Endpoint\ListEndpoints;
use App\Application\Endpoint\UpdateEndpoint;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEndpointRequest;
use App\Http\Requests\UpdateEndpointRequest;
use App\Http\Resources\EndpointResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class EndpointController extends Controller
{
    public function index(ListEndpoints $listEndpoints): AnonymousResourceCollection
    {
        return EndpointResource::collection($listEndpoints->handle());
    }

    public function store(StoreEndpointRequest $request, CreateEndpoint $createEndpoint): JsonResponse
    {
        $endpoint = $createEndpoint->handle(
            $request->string('name')->toString(),
            $request->string('url')->toString(),
            $request->input('status'),
        );

        return (new EndpointResource($endpoint))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id, FindEndpoint $findEndpoint): EndpointResource|JsonResponse
    {
        try {
            return new EndpointResource($findEndpoint->handle($id));
        } catch (EndpointNotFound) {
            return $this->notFound();
        }
    }

    public function update(string $id, UpdateEndpointRequest $request, UpdateEndpoint $updateEndpoint): EndpointResource|JsonResponse
    {
        try {
            return new EndpointResource($updateEndpoint->handle(
                $id,
                $request->has('name') ? $request->string('name')->toString() : null,
                $request->has('url') ? $request->string('url')->toString() : null,
                $request->input('status'),
            ));
        } catch (EndpointNotFound) {
            return $this->notFound();
        }
    }

    public function destroy(string $id, DeleteEndpoint $deleteEndpoint): JsonResponse
    {
        try {
            $deleteEndpoint->handle($id);
        } catch (EndpointNotFound) {
            return $this->notFound();
        }

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Endpoint not found.',
        ], Response::HTTP_NOT_FOUND);
    }
}
