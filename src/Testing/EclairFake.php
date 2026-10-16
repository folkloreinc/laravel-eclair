<?php

namespace Folklore\Eclair\Testing;

use Carbon\CarbonImmutable;
use Closure;
use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Data\Upload;
use Folklore\Eclair\Eclair;
use Folklore\Eclair\Support\Signature;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use LogicException;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * An in-memory Eclair, for tests: `Eclair::fake()`. The client sends its requests through Laravel's
 * HTTP client, and the fake answers them as the server would, so `Http::assertSent()` works too.
 * Jobs stay pending until the test completes or fails them.
 */
class EclairFake extends Eclair
{
    public const URL = 'https://eclair.test';

    private const OPERATIONS = [
        'probe' => 'probe',
        'video.transcode' => 'video',
        'video.thumbnails' => 'video',
        'video.poster' => 'video',
        'video.hls' => 'video',
        'audio.transcode' => 'cpu',
        'audio.waveform' => 'cpu',
        'image.convert' => 'cpu',
        'image.thumbnail' => 'cpu',
        'image.palette' => 'cpu',
        'document.thumbnails' => 'cpu',
        'font.convert' => 'cpu',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $jobs = [];

    /** @var list<array{request: array<string, mixed>, job: string}> */
    private array $created = [];

    /** @var array<string, array{hash: string, job: string}> */
    private array $idempotencyKeys = [];

    /** @var list<string> */
    private array $canceled = [];

    /** @var list<array<string, mixed>> */
    private array $probes = [];

    /** @var array<string, mixed> */
    private array $probeMetadata = ['kind' => 'other', 'mime' => 'application/octet-stream'];

    private bool $probePending = false;

    /** @var array<string, array{upload: array<string, mixed>, contents: string}> */
    private array $uploads = [];

    /** @var array<string, array<string, mixed>> */
    private array $presets = [];

    /** @var list<array{name: string, tasks: array<string, mixed>}> */
    private array $savedPresets = [];

    /** @var array<string, string> */
    private array $files = [];

    /** The key the fake accepts: the config's when it was made. */
    private string $key = '';

    /**
     * Makes a fake and answers the requests to its URL. Without a URL, a key or a webhook secret
     * in the config, the fake sets them.
     */
    public static function make(Container $app): self
    {
        $config = $app->make(Config::class);
        foreach (['url' => self::URL, 'key' => 'ek_fake', 'webhook.secret' => 'whsec_fake'] as $key => $value) {
            if ((string) $config->get('eclair.'.$key) === '') {
                $config->set('eclair.'.$key, $value);
            }
        }
        $http = $app->make(Factory::class);
        $fake = new self($http, $app->make(Filesystems::class), $config);
        $fake->key = (string) $config->get('eclair.key');
        $http->fake([rtrim((string) $config->get('eclair.url'), '/').'/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /**
     * The metadata that probes return. With `pending`, a probe answers with a job instead, as when
     * it takes longer than its timeout.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function probeReturns(array $metadata, bool $pending = false): static
    {
        $this->probeMetadata = $metadata;
        $this->probePending = $pending;

        return $this;
    }

    /**
     * Adds a global preset, as the server's built-in ones.
     *
     * @param  array<string, array<string, mixed>>  $tasks
     */
    public function preset(string $name, array $tasks): static
    {
        $this->presets[$name] = ['name' => $name, 'scope' => 'global', 'version' => 1, 'tasks' => $tasks];

        return $this;
    }

    /**
     * Completes the job's unfinished tasks, with their outputs by task name:
     * `['h264' => ['files' => [[name, path, mime, size]], 'metadata' => […]]]`. A file with
     * `contents` is in the temporary storage: it gets a URL, which `Eclair::download()` reads.
     *
     * @param  array<string, array<string, mixed>>  $outputs
     */
    public function completeJob(Job|string $job, array $outputs = []): Job
    {
        $id = $this->id($job);
        $now = self::now();
        foreach ($this->jobs[$id]['tasks'] as $name => $task) {
            if (! in_array($task['status'], ['completed', 'failed', 'canceled', 'skipped'], true)) {
                $task = ['startedAt' => $now, ...$task, 'status' => 'completed', 'endedAt' => $now];
                if (isset($outputs[$name])) {
                    $task['outputs'] = $this->outputs($outputs[$name]);
                }
                $this->jobs[$id]['tasks'][$name] = $task;
            }
        }
        $this->end($id, 'completed');

        return $this->job($id);
    }

    /**
     * Fails a task of the job: the tasks that depend on it fail with `dependency_failed`, and the
     * other unfinished tasks complete.
     */
    public function failJob(Job|string $job, string $task, string $code = 'operation_failed', string $message = 'The operation failed.'): Job
    {
        $id = $this->id($job);
        if (! isset($this->jobs[$id]['tasks'][$task])) {
            throw new LogicException(sprintf('The job %s has no task "%s".', $id, $task));
        }
        $now = self::now();
        $failed = [$task => true];
        do {
            $added = false;
            foreach ($this->jobs[$id]['tasks'] as $name => $other) {
                $dependencies = [...(array) ($other['after'] ?? []), ...(isset($other['from']) ? [explode(':', (string) $other['from'])[0]] : [])];
                if (! isset($failed[$name]) && array_intersect($dependencies, array_keys($failed)) !== []) {
                    $failed[$name] = true;
                    $added = true;
                }
            }
        } while ($added);

        foreach ($this->jobs[$id]['tasks'] as $name => $other) {
            if (in_array($other['status'], ['completed', 'failed', 'canceled', 'skipped'], true)) {
                continue;
            }
            $other = ['startedAt' => $now, ...$other, 'endedAt' => $now];
            if ($name === $task) {
                $other = [...$other, 'status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];
            } elseif (isset($failed[$name])) {
                unset($other['startedAt']);
                $other = [...$other, 'status' => 'failed', 'error' => ['code' => 'dependency_failed', 'message' => sprintf('The task "%s" failed.', $task)]];
            } else {
                $other['status'] = 'completed';
            }
            $this->jobs[$id]['tasks'][$name] = $other;
        }
        $this->end($id, 'failed');

        return $this->job($id);
    }

    /**
     * The body and headers of a signed webhook for the job, as Eclair sends it. Without an event,
     * it is the job's end: `job.completed`, `job.failed` or `job.canceled`.
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function webhook(Job|string $job, ?string $event = null, ?string $task = null, ?string $delivery = null): array
    {
        $job = $job instanceof Job ? $job : $this->job($job);
        $event ??= match ($job->status->value) {
            'completed' => 'job.completed',
            'failed' => 'job.failed',
            'canceled' => 'job.canceled',
            default => throw new LogicException(sprintf('The job %s has not ended: complete or fail it first.', $job->id)),
        };
        $body = (string) json_encode($this->jobs[$job->id] ?? $job->toArray(), JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type' => 'application/json',
            'Eclair-Event' => $event,
            'Eclair-Delivery' => $delivery ?? self::newId('whd'),
            'Eclair-Signature' => Signature::sign((string) $this->config->get('eclair.webhook.secret'), $body),
        ];
        if ($task !== null) {
            $headers['Eclair-Task'] = $task;
        }

        return ['body' => $body, 'headers' => $headers];
    }

    public function job(string $id): Job
    {
        if (! isset($this->jobs[$id])) {
            throw new LogicException(sprintf('There is no job %s.', $id));
        }

        return Job::fromArray($this->jobs[$id]);
    }

    /**
     * The jobs created by the application, in order.
     *
     * @return list<Job>
     */
    public function createdJobs(): array
    {
        return array_map(fn (array $created): Job => $this->job($created['job']), $this->created);
    }

    public function lastJob(): ?Job
    {
        $created = end($this->created);

        return $created === false ? null : $this->job($created['job']);
    }

    /**
     * Asserts that a job was created. The callback receives the request and the job.
     *
     * @param  (Closure(array<string, mixed>, Job): bool)|null  $callback
     */
    public function assertJobCreated(?Closure $callback = null): void
    {
        Assert::assertNotEmpty($this->matching($callback), 'The expected job was not created.');
    }

    /**
     * @param  (Closure(array<string, mixed>, Job): bool)|null  $callback
     */
    public function assertJobNotCreated(?Closure $callback = null): void
    {
        Assert::assertEmpty($this->matching($callback), 'An unexpected job was created.');
    }

    public function assertNothingCreated(): void
    {
        Assert::assertSame([], $this->created, sprintf('%d jobs were created.', count($this->created)));
    }

    public function assertJobCount(int $count): void
    {
        Assert::assertCount($count, $this->created, sprintf('%d jobs were created, not %d.', count($this->created), $count));
    }

    public function assertJobCanceled(Job|string $job): void
    {
        $id = $job instanceof Job ? $job->id : $job;
        Assert::assertContains($id, $this->canceled, sprintf('The job %s was not canceled.', $id));
    }

    /**
     * Asserts that a file was probed. The callback receives the request: `['input' => …]`, or
     * `['file' => contents]` for a file sent in the request.
     *
     * @param  (Closure(array<string, mixed>): bool)|null  $callback
     */
    public function assertProbed(?Closure $callback = null): void
    {
        $probes = $callback === null ? $this->probes : array_filter($this->probes, $callback);
        Assert::assertNotEmpty($probes, 'The expected probe was not sent.');
    }

    /**
     * @param  (Closure(string, Upload): bool)|null  $callback  Receives the contents and the upload
     */
    public function assertUploaded(?Closure $callback = null): void
    {
        $uploads = array_filter(
            $this->uploads,
            fn (array $upload): bool => $callback === null || $callback($upload['contents'], Upload::fromArray($upload['upload'])),
        );
        Assert::assertNotEmpty($uploads, 'The expected file was not uploaded.');
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $callback  Receives the preset's tasks
     */
    public function assertPresetSaved(string $name, ?Closure $callback = null): void
    {
        $saved = array_filter(
            $this->savedPresets,
            fn (array $preset): bool => $preset['name'] === $name && ($callback === null || $callback($preset['tasks'])),
        );
        Assert::assertNotEmpty($saved, sprintf('The preset "%s" was not saved.', $name));
    }

    /**
     * Answers a request to the fake's URL.
     *
     * @internal
     */
    public function handle(Request $request): PromiseInterface
    {
        if ($request->header('Authorization') !== ['Bearer '.$this->key]) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            // Downloads of the temporary storage are signed by their token.
            if (! str_starts_with($path, '/v1/files/')) {
                return self::problem(401, 'Unauthorized');
            }
        }
        $method = $request->method();
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $json = json_decode($request->body(), true);
        $json = is_array($json) ? $json : [];

        return match (true) {
            $method === 'POST' && $path === '/v1/jobs' => $this->createJob($json, $request->header('Idempotency-Key')[0] ?? null),
            $method === 'GET' && $path === '/v1/jobs' => $this->listJobs($query),
            $method === 'GET' && preg_match('#^/v1/jobs/([^/]+)$#', $path, $match) === 1 => $this->showJob(rawurldecode($match[1])),
            $method === 'POST' && preg_match('#^/v1/jobs/([^/]+)/cancel$#', $path, $match) === 1 => $this->cancelJob(rawurldecode($match[1])),
            $method === 'POST' && preg_match('#^/v1/jobs/([^/]+)/retry$#', $path, $match) === 1 => $this->retryJob(rawurldecode($match[1])),
            $method === 'GET' && preg_match('#^/v1/jobs/([^/]+)/files$#', $path, $match) === 1 => $this->jobFiles(rawurldecode($match[1]), $query),
            $method === 'POST' && $path === '/v1/probe' => $this->probeRequest($request, $json),
            $method === 'POST' && $path === '/v1/uploads' => $this->createUpload($request, $query),
            $method === 'GET' && preg_match('#^/v1/uploads/([^/]+)$#', $path, $match) === 1 => $this->showUpload(rawurldecode($match[1])),
            $method === 'DELETE' && preg_match('#^/v1/uploads/([^/]+)$#', $path, $match) === 1 => $this->deleteUpload(rawurldecode($match[1])),
            $method === 'GET' && $path === '/v1/presets' => Factory::response(['data' => array_values($this->presets)]),
            $method === 'GET' && preg_match('#^/v1/presets/(.+)$#', $path, $match) === 1 => $this->showPreset(rawurldecode($match[1])),
            $method === 'PUT' && preg_match('#^/v1/presets/(.+)$#', $path, $match) === 1 => $this->putPreset(rawurldecode($match[1]), $json),
            $method === 'DELETE' && preg_match('#^/v1/presets/(.+)$#', $path, $match) === 1 => $this->deletePreset(rawurldecode($match[1])),
            $method === 'GET' && $path === '/v1/operations' => Factory::response(['data' => $this->operationList()]),
            $method === 'GET' && preg_match('#^/v1/files/([^/]+)$#', $path, $match) === 1 => isset($this->files[$match[1]])
                ? Factory::response($this->files[$match[1]], 200, ['Content-Type' => 'application/octet-stream'])
                : self::problem(404, 'Not Found'),
            default => self::problem(404, 'Not Found', sprintf('The fake has no %s %s.', $method, $path)),
        };
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function createJob(array $request, ?string $idempotencyKey): PromiseInterface
    {
        $hash = hash('sha256', (string) json_encode(self::canonical($request)));
        if ($idempotencyKey !== null && isset($this->idempotencyKeys[$idempotencyKey])) {
            $replay = $this->idempotencyKeys[$idempotencyKey];

            return $replay['hash'] === $hash
                ? Factory::response($this->jobs[$replay['job']], 202)
                : self::problem(409, 'Conflict', 'The Idempotency-Key was used for another request.');
        }

        $errors = [];
        $preset = null;
        if (isset($request['preset'])) {
            $preset = $this->presets[(string) $request['preset']] ?? null;
            if ($preset === null) {
                $errors['/preset'] = [sprintf('there is no preset "%s"', $request['preset'])];
            }
        }
        $tasks = [...(array) ($preset['tasks'] ?? []), ...(array) ($request['tasks'] ?? [])];
        if ($tasks === []) {
            $errors['/tasks'] = ['the job has no task'];
        }
        if (! isset($request['input'])) {
            $errors[''] = ['must have required property \'input\''];
        }
        if ($errors !== []) {
            return self::problem(422, 'Unprocessable Content', null, $errors);
        }

        $id = self::newId('job');
        $job = [
            'id' => $id,
            'status' => 'pending',
            'createdAt' => self::now(),
            'input' => $request['input'],
            'priority' => $request['priority'] ?? 'normal',
            'failFast' => $request['failFast'] ?? false,
            'metadata' => ($request['metadata'] ?? []) === [] ? new stdClass : $request['metadata'],
            'tasks' => [],
        ];
        if (isset($request['output'])) {
            $job['output'] = $request['output'];
        }
        if ($preset !== null) {
            $job['preset'] = ['name' => $preset['name'], 'version' => $preset['version']];
        }
        foreach ($tasks as $name => $task) {
            $task = (array) $task;
            $job['tasks'][$name] = array_filter([
                'status' => isset($task['from']) || isset($task['after']) ? 'pending' : 'queued',
                'op' => (string) ($task['op'] ?? ''),
                'from' => $task['from'] ?? null,
                'after' => $task['after'] ?? null,
            ], fn ($value) => $value !== null);
        }
        $this->jobs[$id] = $job;
        $this->created[] = ['request' => $request, 'job' => $id];
        if ($idempotencyKey !== null) {
            $this->idempotencyKeys[$idempotencyKey] = ['hash' => $hash, 'job' => $id];
        }

        return Factory::response($job, 202);
    }

    /**
     * @param  array<mixed>  $query
     */
    private function listJobs(array $query): PromiseInterface
    {
        $statuses = isset($query['status']) ? explode(',', (string) $query['status']) : null;
        $metadata = is_array($query['metadata'] ?? null) ? $query['metadata'] : [];
        $after = isset($query['createdAfter']) ? CarbonImmutable::parse((string) $query['createdAfter']) : null;
        $jobs = array_values(array_filter(array_reverse($this->jobs), function (array $job) use ($statuses, $metadata, $after): bool {
            if ($statuses !== null && ! in_array($job['status'], $statuses, true)) {
                return false;
            }
            if ($after !== null && CarbonImmutable::parse($job['createdAt'])->lessThanOrEqualTo($after)) {
                return false;
            }
            foreach ($metadata as $key => $value) {
                $actual = ((array) $job['metadata'])[$key] ?? null;
                if (! is_scalar($actual) || (is_bool($actual) ? ($actual ? 'true' : 'false') : (string) $actual) !== (string) $value) {
                    return false;
                }
            }

            return true;
        }));
        $offset = (int) ($query['cursor'] ?? 0);
        $limit = (int) ($query['limit'] ?? 20);

        return Factory::response([
            'data' => array_slice($jobs, $offset, $limit),
            'nextCursor' => $offset + $limit < count($jobs) ? (string) ($offset + $limit) : null,
        ]);
    }

    private function showJob(string $id): PromiseInterface
    {
        return isset($this->jobs[$id]) ? Factory::response($this->jobs[$id]) : self::problem(404, 'Not Found');
    }

    private function cancelJob(string $id): PromiseInterface
    {
        if (! isset($this->jobs[$id])) {
            return self::problem(404, 'Not Found');
        }
        $this->canceled[] = $id;
        if (! in_array($this->jobs[$id]['status'], ['completed', 'failed', 'canceled'], true)) {
            foreach ($this->jobs[$id]['tasks'] as $name => $task) {
                if (in_array($task['status'], ['pending', 'queued', 'running'], true)) {
                    $this->jobs[$id]['tasks'][$name] = [...$task, 'status' => 'canceled', 'endedAt' => self::now()];
                }
            }
            $this->end($id, 'canceled');
        }

        return Factory::response($this->jobs[$id]);
    }

    private function retryJob(string $id): PromiseInterface
    {
        if (! isset($this->jobs[$id])) {
            return self::problem(404, 'Not Found');
        }
        foreach ($this->jobs[$id]['tasks'] as $name => $task) {
            if (in_array($task['status'], ['failed', 'canceled'], true)) {
                unset($task['error'], $task['startedAt'], $task['endedAt']);
                $this->jobs[$id]['tasks'][$name] = [...$task, 'status' => 'queued'];
            }
        }
        unset($this->jobs[$id]['endedAt']);
        $this->jobs[$id]['status'] = 'running';

        return Factory::response($this->jobs[$id]);
    }

    /**
     * @param  array<mixed>  $query
     */
    private function jobFiles(string $id, array $query): PromiseInterface
    {
        if (! isset($this->jobs[$id])) {
            return self::problem(404, 'Not Found');
        }
        $files = [];
        foreach ($this->jobs[$id]['tasks'] as $name => $task) {
            if (! isset($query['task']) || $query['task'] === $name) {
                foreach ($task['outputs']['files'] ?? [] as $file) {
                    $files[] = [...$file, 'task' => $name];
                }
            }
        }

        return Factory::response(['data' => $files, 'nextCursor' => null]);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function probeRequest(Request $request, array $json): PromiseInterface
    {
        $raw = ! $request->isJson();
        $this->probes[] = $raw ? ['file' => $request->body()] : $json;
        if ($this->probePending) {
            $id = self::newId('job');
            $this->jobs[$id] = [
                'id' => $id,
                'status' => 'running',
                'createdAt' => self::now(),
                'startedAt' => self::now(),
                'input' => $json['input'] ?? ['upload' => self::newId('upl')],
                'metadata' => new stdClass,
                'tasks' => ['probe' => ['status' => 'running', 'op' => 'probe@1', 'startedAt' => self::now()]],
            ];

            return Factory::response($this->jobs[$id], 202);
        }
        $metadata = $this->probeMetadata;
        if ($raw && ! isset($metadata['size'])) {
            $metadata['size'] = strlen($request->body());
        }

        return Factory::response(['metadata' => $metadata]);
    }

    /**
     * @param  array<mixed>  $query
     */
    private function createUpload(Request $request, array $query): PromiseInterface
    {
        $contents = $request->body();
        $upload = [
            'id' => self::newId('upl'),
            'size' => strlen($contents),
            'complete' => true,
            'createdAt' => self::now(),
            'expiresAt' => self::now(CarbonImmutable::now()->addDay()),
        ];
        if (($query['probe'] ?? null) === '1') {
            $upload['metadata'] = [...$this->probeMetadata, 'size' => strlen($contents)];
        }
        $this->uploads[$upload['id']] = ['upload' => $upload, 'contents' => $contents];

        return Factory::response($upload, 201);
    }

    private function showUpload(string $id): PromiseInterface
    {
        return isset($this->uploads[$id]) ? Factory::response($this->uploads[$id]['upload']) : self::problem(404, 'Not Found');
    }

    private function deleteUpload(string $id): PromiseInterface
    {
        if (! isset($this->uploads[$id])) {
            return self::problem(404, 'Not Found');
        }
        unset($this->uploads[$id]);

        return Factory::response('', 204);
    }

    private function showPreset(string $name): PromiseInterface
    {
        return isset($this->presets[$name]) ? Factory::response($this->presets[$name]) : self::problem(404, 'Not Found');
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function putPreset(string $name, array $json): PromiseInterface
    {
        $tasks = is_array($json['tasks'] ?? null) ? $json['tasks'] : [];
        if ($tasks === []) {
            return self::problem(422, 'Unprocessable Content', null, ['/tasks' => ['the preset has no task']]);
        }
        $version = ($this->presets[$name]['scope'] ?? null) === 'project' ? $this->presets[$name]['version'] + 1 : 1;
        $this->presets[$name] = ['name' => $name, 'scope' => 'project', 'version' => $version, 'tasks' => $tasks];
        $this->savedPresets[] = ['name' => $name, 'tasks' => $tasks];

        return Factory::response($this->presets[$name]);
    }

    private function deletePreset(string $name): PromiseInterface
    {
        if (($this->presets[$name]['scope'] ?? null) !== 'project') {
            return self::problem(404, 'Not Found');
        }
        unset($this->presets[$name]);

        return Factory::response('', 204);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function operationList(): array
    {
        $operations = [];
        foreach (self::OPERATIONS as $name => $capability) {
            $operations[] = [
                'name' => $name,
                'version' => 1,
                'accepts' => new stdClass,
                'capability' => $capability,
                'outputs' => ['files' => $name !== 'probe' && $name !== 'image.palette', 'metadata' => true],
                'options' => ['type' => 'object'],
            ];
        }

        return $operations;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return array<string, mixed>
     */
    private function outputs(array $outputs): array
    {
        $result = [];
        if (isset($outputs['metadata'])) {
            $result['metadata'] = $outputs['metadata'];
        }
        if (isset($outputs['files'])) {
            $result['files'] = [];
            foreach ((array) $outputs['files'] as $file) {
                $file = (array) $file;
                if (array_key_exists('contents', $file)) {
                    $token = bin2hex(random_bytes(16));
                    $this->files[$token] = (string) $file['contents'];
                    $file['size'] ??= strlen((string) $file['contents']);
                    $file['url'] = rtrim((string) $this->config->get('eclair.url', self::URL), '/').'/v1/files/'.$token;
                    $file['expiresAt'] = self::now(CarbonImmutable::now()->addDay());
                    unset($file['contents']);
                }
                $result['files'][] = [
                    'name' => $file['name'] ?? basename((string) ($file['path'] ?? 'file')),
                    'mime' => 'application/octet-stream',
                    'size' => 0,
                    ...$file,
                ];
            }
        }

        return $result;
    }

    /**
     * @param  (Closure(array<string, mixed>, Job): bool)|null  $callback
     * @return list<string>
     */
    private function matching(?Closure $callback): array
    {
        $ids = [];
        foreach ($this->created as $created) {
            if ($callback === null || $callback($created['request'], $this->job($created['job']))) {
                $ids[] = $created['job'];
            }
        }

        return $ids;
    }

    private function end(string $id, string $status): void
    {
        $now = self::now();
        $this->jobs[$id] = ['startedAt' => $now, ...$this->jobs[$id], 'status' => $status, 'endedAt' => $now];
    }

    private function id(Job|string $job): string
    {
        $id = $job instanceof Job ? $job->id : $job;
        if (! isset($this->jobs[$id])) {
            throw new LogicException(sprintf('There is no job %s.', $id));
        }

        return $id;
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     */
    private static function problem(int $status, string $title, ?string $detail = null, ?array $errors = null): PromiseInterface
    {
        return Factory::response(
            array_filter(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail, 'errors' => $errors]),
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    private static function now(?CarbonImmutable $date = null): string
    {
        return ($date ?? CarbonImmutable::now())->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** A prefixed ULID: `job_01JA2Y8C6W4N5V7TQ3H9XK0B2M`. */
    private static function newId(string $prefix): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = (int) (microtime(true) * 1000);
        $id = '';
        for ($i = 9; $i >= 0; $i--) {
            $id .= $alphabet[intdiv($time, 32 ** $i) % 32];
        }
        foreach (str_split(random_bytes(16)) as $index => $byte) {
            if ($index < 16) {
                $id .= $alphabet[ord($byte) % 32];
            }
        }

        return $prefix.'_'.$id;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
