<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Endpoint\EndpointNotFound;
use App\Application\Subscription\GetEndpointSubscriptions;
use App\Application\Subscription\ReplaceEndpointSubscriptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplaceEndpointSubscriptionsRequest;
use App\Http\Resources\EndpointSubscriptionsResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class EndpointSubscriptionController extends Controller
{
    public function index(string $id, GetEndpointSubscriptions $getEndpointSubscriptions): EndpointSubscriptionsResource|JsonResponse
    {
        try {
            return new EndpointSubscriptionsResource($getEndpointSubscriptions->handle($id));
        } catch (EndpointNotFound) {
            return $this->notFound();
        }
    }

    public function replace(
        string $id,
        ReplaceEndpointSubscriptionsRequest $request,
        ReplaceEndpointSubscriptions $replaceEndpointSubscriptions,
    ): EndpointSubscriptionsResource|JsonResponse {
        try {
            return new EndpointSubscriptionsResource(
                $replaceEndpointSubscriptions->handle($id, $request->types()),
            );
        } catch (EndpointNotFound) {
            return $this->notFound();
        }
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Endpoint not found.',
        ], Response::HTTP_NOT_FOUND);
    }
}
