<?php

namespace Folklore\Eclair\Http;

use Illuminate\Http\JsonResponse;

/**
 * @internal
 */
final class Problem
{
    public static function response(int $status, string $title, ?string $detail = null): JsonResponse
    {
        return new JsonResponse(
            array_filter(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail]),
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
