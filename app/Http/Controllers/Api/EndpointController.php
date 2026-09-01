<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\CoreList\CoreListPageRequest;
use App\Application\CoreList\InvalidPaginationCursor;
use App\Application\CoreList\InvalidPaginationLimit;
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
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class EndpointController extends Controller
{
    public function index(Request $request, ListEndpoints $listEndpoints): AnonymousResourceCollection|JsonResponse
    {
        try {
            /** @var array<string, mixed> $query */
            $query = $request->query();
            $page = $listEndpoints->handle(CoreListPageRequest::fromQuery($query));
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

        return EndpointResource::collection($page->items)->additional([
            'meta' => ['limit' => $page->limit, 'next_cursor' => $page->nextCursor],
        ]);
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
