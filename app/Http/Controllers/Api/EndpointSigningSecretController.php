<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Endpoint\EndpointNotFound;
use App\Application\EndpointSigningSecret\RotateEndpointSigningSecret;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class EndpointSigningSecretController extends Controller
{
    public function store(string $id, RotateEndpointSigningSecret $rotate): JsonResponse
    {
        try {
            $secret = $rotate->handle($id);
        } catch (EndpointNotFound) {
            return response()->json(['message' => 'Endpoint not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => [
            'key_id' => $secret->keyId,
            'version' => $secret->version,
            'secret' => $secret->secret,
            'created_at' => $secret->createdAt,
        ]], Response::HTTP_CREATED);
    }
}
