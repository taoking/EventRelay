<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DeadLetter\DeadLetterFilter;
use App\Application\DeadLetter\InvalidDeadLetterCursor;
use App\Application\DeadLetter\InvalidDeadLetterFilter;
use App\Application\DeadLetter\ListDeadLetters;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeadLetterResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class DeadLetterController extends Controller
{
    public function index(Request $request, ListDeadLetters $deadLetters): AnonymousResourceCollection|JsonResponse
    {
        try {
            /** @var array<string, mixed> $query */
            $query = $request->query();
            $filter = DeadLetterFilter::fromQuery($query);
            $cursor = array_key_exists('cursor', $query) && is_string($query['cursor']) ? $query['cursor'] : null;
            if (array_key_exists('cursor', $query) && ! is_string($query['cursor'])) {
                throw new InvalidDeadLetterCursor('Cursor is invalid.');
            }
            $page = $deadLetters->handle($filter, $cursor);
        } catch (InvalidDeadLetterFilter) {
            return response()->json([
                'code' => 'invalid_dead_letter_filter',
                'message' => 'Dead-letter filters are invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidDeadLetterCursor) {
            return response()->json([
                'code' => 'invalid_dead_letter_cursor',
                'message' => 'Dead-letter cursor is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return DeadLetterResource::collection($page->items)->additional([
            'meta' => ['next_cursor' => $page->nextCursor],
        ]);
    }
}
