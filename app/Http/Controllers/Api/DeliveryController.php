<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Delivery\DeliveryNotFound;
use App\Application\Delivery\FindDelivery;
use App\Application\Delivery\ListDeliveries;
use App\Http\Controllers\Controller;
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
}
