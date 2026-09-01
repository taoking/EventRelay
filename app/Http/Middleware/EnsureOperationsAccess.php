<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Operations\OperationsEndpointAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureOperationsAccess
{
    public function __construct(private OperationsEndpointAccess $configuration) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->configuration->isEnabled()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if (! $this->configuration->accepts($request->bearerToken())) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
