<?php

namespace Folklore\Eclair\Exceptions;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Eclair answered with an error. `problem` holds the problem details (RFC 9457), with `errors` by
 * JSON pointer for a 422.
 */
class RequestFailed extends EclairException
{
    /**
     * @param  array<string, mixed>  $problem
     */
    public function __construct(
        public readonly int $status,
        public readonly array $problem,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        $title = is_string($problem['title'] ?? null) ? $problem['title'] : 'Error';
        $detail = is_string($problem['detail'] ?? null) ? ': '.$problem['detail'] : '';
        parent::__construct(sprintf('Eclair answered %d %s%s', $status, $title, $detail), $status, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $problem = $response->json();
        if (! is_array($problem)) {
            $problem = [
                'type' => 'about:blank',
                'title' => $response->reason() !== '' ? $response->reason() : 'Error',
                'status' => $response->status(),
            ];
        }
        $retryAfter = $response->header('Retry-After');

        return new self(
            $response->status(),
            $problem,
            is_numeric($retryAfter) ? (int) $retryAfter : null,
            $response->toException(),
        );
    }

    /**
     * The validation errors of a 422, by JSON pointer: `['/tasks/h264/op' => ['…']]`.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $errors = $this->problem['errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }
}
