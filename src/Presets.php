<?php

namespace Folklore\Eclair;

/**
 * The presets the project can use: its own, and the global ones. Presets are arrays of the
 * protocol: `name`, `scope`, `version` and `tasks`.
 */
final class Presets
{
    /**
     * @internal
     */
    public function __construct(private readonly Client $client) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $presets = $this->client->json('GET', '/v1/presets')['data'] ?? [];

        return is_array($presets) ? array_values($presets) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(string $name): array
    {
        return $this->client->json('GET', '/v1/presets/'.Client::segment($name));
    }

    /**
     * Creates or replaces a preset of the project. Each change gives it a new version.
     *
     * @param  array<string, array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    public function put(string $name, array $tasks): array
    {
        return $this->client->json('PUT', '/v1/presets/'.Client::segment($name), json: ['tasks' => $tasks]);
    }

    public function delete(string $name): void
    {
        $this->client->send('DELETE', '/v1/presets/'.Client::segment($name));
    }
}
