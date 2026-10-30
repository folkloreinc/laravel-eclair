<?php

namespace Folklore\Eclair;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Data\OutputFile;
use Folklore\Eclair\Data\ProbeResult;
use Folklore\Eclair\Exceptions\EclairException;
use Folklore\Eclair\Exceptions\StorageNotConfigured;
use Folklore\Eclair\Support\Body;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

/**
 * The client of Eclair's `/v1` API. Every method throws an `EclairException`: `RequestFailed`
 * when Eclair answers with an error, `ConnectionFailed` when it can't be reached.
 */
class Eclair
{
    public function __construct(
        protected readonly Factory $http,
        protected readonly Filesystems $filesystems,
        protected readonly Config $config,
    ) {}

    public function jobs(): Jobs
    {
        $webhook = $this->config->get('eclair.webhook.url');

        return new Jobs($this->client(), is_string($webhook) ? $webhook : null);
    }

    public function presets(): Presets
    {
        return new Presets($this->client());
    }

    public function uploads(): Uploads
    {
        return new Uploads($this->client());
    }

    /**
     * The metadata of a file, in the response (`POST /v1/probe`). When the probe takes longer
     * than `timeout` seconds, the result is pending and has the job that brings it.
     *
     * @param  array<string, mixed>  $input  An input of the protocol: `['storage' => …, 'path' => …]`, `['url' => …]` or `['upload' => …]`
     */
    public function probe(array $input, ?int $timeout = null, bool $checksum = false, bool $thumbhash = false): ProbeResult
    {
        $request = ['input' => $input];
        if ($timeout !== null) {
            $request['timeout'] = $timeout;
        }
        if ($checksum) {
            $request['checksum'] = true;
        }
        if ($thumbhash) {
            $request['thumbhash'] = true;
        }

        return $this->probeResult($this->client()->send('POST', '/v1/probe', json: $request));
    }

    /**
     * The metadata of a file sent in the request, within the project's direct upload limit.
     *
     * @param  mixed  $file  Its contents, a stream, or a file object
     */
    public function probeFile(mixed $file, ?int $timeout = null, bool $checksum = false, bool $thumbhash = false): ProbeResult
    {
        $query = array_filter([
            'timeout' => $timeout,
            'checksum' => $checksum ? 'true' : null,
            'thumbhash' => $thumbhash ? 'true' : null,
        ], fn ($value) => $value !== null);

        return $this->probeResult($this->client()->send(
            'POST',
            '/v1/probe',
            $query,
            body: Body::from($file),
            headers: ['Content-Type' => 'application/octet-stream'],
        ));
    }

    /**
     * The operations the project may use, with their version, inputs, outputs and options schema.
     *
     * @return list<array<string, mixed>>
     */
    public function operations(): array
    {
        $operations = $this->client()->json('GET', '/v1/operations')['data'] ?? [];

        return is_array($operations) ? array_values($operations) : [];
    }

    /**
     * The name of the Eclair storage that points to a Laravel disk.
     */
    public function storageFor(string $disk): string
    {
        foreach ($this->storages() as $name => $storage) {
            if (($storage['disk'] ?? null) === $disk) {
                return (string) $name;
            }
        }

        throw StorageNotConfigured::forDisk($disk);
    }

    /**
     * The input of a job or a probe that reads a file of a Laravel disk.
     *
     * @return array{storage: string, path: string}
     */
    public function input(string $disk, string $path): array
    {
        return ['storage' => $this->storageFor($disk), 'path' => ltrim($path, '/')];
    }

    /**
     * The output of a job that writes its files to a Laravel disk, under a prefix. `$path` is the
     * protocol's path pattern.
     *
     * @return array{storage: string, prefix: string, path?: string}
     */
    public function output(string $disk, string $prefix, ?string $path = null): array
    {
        $output = ['storage' => $this->storageFor($disk), 'prefix' => ltrim($prefix, '/')];
        if ($path !== null) {
            $output['path'] = $path;
        }

        return $output;
    }

    /**
     * Copies a file of Eclair's temporary storage to a Laravel disk, at `$path` or at the file's
     * own path. Returns the path on the disk.
     */
    public function download(OutputFile $file, string $disk, ?string $path = null): string
    {
        if ($file->url === null) {
            throw new EclairException(sprintf(
                '%s has no URL: it is not in the temporary storage, or it has expired.',
                $file->path,
            ));
        }
        $path ??= $file->path;
        try {
            // The URL is signed: it needs no key.
            $response = $this->http
                ->withOptions(['stream' => true])
                ->timeout((int) $this->config->get('eclair.timeout', 30))
                ->get($file->url);
        } catch (ConnectionException $exception) {
            throw new EclairException(sprintf('Could not download %s: %s', $file->path, $exception->getMessage()), 0, $exception);
        }
        if (! $response->successful()) {
            throw new EclairException(sprintf('Could not download %s: Eclair answered %d.', $file->path, $response->status()));
        }
        $body = $response->toPsrResponse()->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $stream = StreamWrapper::getResource($body);
        try {
            if ($this->filesystems->disk($disk)->writeStream($path, $stream) === false) {
                throw new EclairException(sprintf('Could not write %s on the disk "%s".', $path, $disk));
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    /**
     * Copies every file of a job (or of one of its tasks) from the temporary storage to a Laravel
     * disk, at their own paths, segments included. Returns the paths on the disk.
     *
     * @return list<string>
     */
    public function downloadFiles(Job|string $job, string $disk, ?string $task = null): array
    {
        $paths = [];
        foreach ($this->jobs()->files($job instanceof Job ? $job->id : $job, $task) as $file) {
            $paths[] = $this->download($file, $disk);
        }

        return $paths;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function storages(): array
    {
        $storages = $this->config->get('eclair.storages', []);

        return is_array($storages) ? $storages : [];
    }

    protected function client(): Client
    {
        $url = $this->config->get('eclair.url');
        $key = $this->config->get('eclair.key');

        return new Client(
            $this->http,
            is_string($url) ? $url : null,
            is_string($key) ? $key : null,
            (int) $this->config->get('eclair.timeout', 30),
        );
    }

    private function probeResult(Response $response): ProbeResult
    {
        $data = $response->json();
        if (! is_array($data)) {
            throw new EclairException('Eclair answered the probe with a body that is not a JSON object.');
        }
        if ($response->status() === 202) {
            return new ProbeResult(null, Job::fromArray($data));
        }

        return new ProbeResult(is_array($data['metadata'] ?? null) ? $data['metadata'] : []);
    }
}
