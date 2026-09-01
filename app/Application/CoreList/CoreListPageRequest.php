<?php

declare(strict_types=1);

namespace App\Application\CoreList;

final readonly class CoreListPageRequest
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 100;

    public function __construct(
        public int $limit,
        public ?string $cursor,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidPaginationLimit('Pagination limit is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $limit = self::limitFromQuery($query);

        if (! array_key_exists('cursor', $query)) {
            return new self($limit, null);
        }
        if (! is_string($query['cursor'])) {
            throw new InvalidPaginationCursor('Pagination cursor is invalid.');
        }

        return new self($limit, $query['cursor']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function limitFromQuery(array $query): int
    {
        if (! array_key_exists('limit', $query)) {
            return self::DEFAULT_LIMIT;
        }

        $value = $query['limit'];
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new InvalidPaginationLimit('Pagination limit is invalid.');
        }

        $limit = (int) $value;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidPaginationLimit('Pagination limit is invalid.');
        }

        return $limit;
    }
}
