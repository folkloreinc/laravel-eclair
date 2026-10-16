<?php

namespace Folklore\Eclair;

use Folklore\Eclair\Exceptions\ConnectionFailed;
use Folklore\Eclair\Exceptions\EclairException;
use Folklore\Eclair\Exceptions\RequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;

/**
 * Sends the requests of the API, with the key, and turns errors into exceptions.
 *
 * @internal
 */
final class Client
{
    public function __construct(
        private readonly Factory $http,
        private readonly ?string $url,
        private readonly ?string $key,
        private readonly int $timeout = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    public function send(
        string $method,
        string $path,
        array $query = [],
        mixed $json = null,
        StreamInterface|string|null $body = null,
        array $headers = [],
    ): Response {
        if ($this->url === null || $this->url === '' || $this->key === null || $this->key === '') {
            throw new EclairException('Eclair is not configured: set ECLAIR_URL and ECLAIR_KEY.');
        }
        $request = $this->http
            ->baseUrl(rtrim($this->url, '/'))
            ->withToken($this->key)
            ->acceptJson()
            ->timeout($this->timeout)
            ->withHeaders($headers);
        $options = $query === [] ? [] : ['query' => $query];
        if ($body !== null) {
            $request = $request->withBody($body, $headers['Content-Type'] ?? 'application/octet-stream');
        } elseif ($json !== null) {
            $options['json'] = $json;
        }
        try {
            $response = $request->send($method, $path, $options);
        } catch (ConnectionException $exception) {
            throw new ConnectionFailed('Could not reach Eclair: '.$exception->getMessage(), 0, $exception);
        }
        if ($response->failed()) {
            throw RequestFailed::fromResponse($response);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function json(
        string $method,
        string $path,
        array $query = [],
        mixed $json = null,
        StreamInterface|string|null $body = null,
        array $headers = [],
    ): array {
        $data = $this->send($method, $path, $query, $json, $body, $headers)->json();
        if (! is_array($data)) {
            throw new EclairException(sprintf('Eclair answered %s %s with a body that is not a JSON object.', $method, $path));
        }

        return $data;
    }

    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
