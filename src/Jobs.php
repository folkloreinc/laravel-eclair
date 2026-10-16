<?php

namespace Folklore\Eclair;

use DateTimeInterface;
use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Data\JobPage;
use Folklore\Eclair\Data\JobStatus;
use Folklore\Eclair\Data\OutputFile;
use Illuminate\Support\LazyCollection;

final class Jobs
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?string $webhook = null,
    ) {}

    /**
     * Creates a job from a request of the protocol (`input`, `output`, `preset`, `tasks`,
     * `metadata`…). With an idempotency key, a retry of the same request returns the first job,
     * so it's safe to retry after an error.
     *
     * @param  array<string, mixed>  $request
     */
    public function create(array $request, ?string $idempotencyKey = null): Job
    {
        if (! isset($request['webhook']) && $this->webhook !== null && $this->webhook !== '') {
            $request['webhook'] = $this->webhook;
        }

        return Job::fromArray($this->client->json(
            'POST',
            '/v1/jobs',
            json: $request,
            headers: $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey],
        ));
    }

    public function find(string $id): Job
    {
        return Job::fromArray($this->client->json('GET', '/v1/jobs/'.Client::segment($id)));
    }

    /**
     * A page of jobs, the newest first.
     *
     * @param  array{status?: JobStatus|string|list<JobStatus|string>, createdAfter?: DateTimeInterface|string, metadata?: array<string, scalar>, cursor?: string, limit?: int}  $filters
     */
    public function list(array $filters = []): JobPage
    {
        $query = [];
        if (isset($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $query['status'] = implode(',', array_map(
                fn (JobStatus|string $status): string => $status instanceof JobStatus ? $status->value : $status,
                $statuses,
            ));
        }
        if (isset($filters['createdAfter'])) {
            $date = $filters['createdAfter'];
            $query['createdAfter'] = $date instanceof DateTimeInterface ? $date->format(DateTimeInterface::RFC3339_EXTENDED) : $date;
        }
        foreach ($filters['metadata'] ?? [] as $key => $value) {
            $query['metadata['.$key.']'] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        if (isset($filters['cursor'])) {
            $query['cursor'] = $filters['cursor'];
        }
        if (isset($filters['limit'])) {
            $query['limit'] = $filters['limit'];
        }
        $page = $this->client->json('GET', '/v1/jobs', $query);
        $jobs = is_array($page['data'] ?? null) ? $page['data'] : [];

        return new JobPage(
            array_values(array_map(fn (mixed $job): Job => Job::fromArray(is_array($job) ? $job : []), $jobs)),
            is_string($page['nextCursor'] ?? null) ? $page['nextCursor'] : null,
        );
    }

    /**
     * Every job that matches the filters, fetching the pages as they are read.
     *
     * @param  array{status?: JobStatus|string|list<JobStatus|string>, createdAfter?: DateTimeInterface|string, metadata?: array<string, scalar>, limit?: int}  $filters
     * @return LazyCollection<int, Job>
     */
    public function all(array $filters = []): LazyCollection
    {
        return LazyCollection::make(function () use ($filters) {
            $cursor = null;
            do {
                $page = $this->list($cursor === null ? $filters : [...$filters, 'cursor' => $cursor]);
                // Not `yield from`: the keys of each page would overwrite the previous ones.
                foreach ($page->jobs as $job) {
                    yield $job;
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
        });
    }

    /** Cancels the job's pending and running tasks. */
    public function cancel(string $id): Job
    {
        return Job::fromArray($this->client->json('POST', '/v1/jobs/'.Client::segment($id).'/cancel'));
    }

    /** Runs the job's failed and canceled tasks again. */
    public function retry(string $id): Job
    {
        return Job::fromArray($this->client->json('POST', '/v1/jobs/'.Client::segment($id).'/retry'));
    }

    /**
     * Every file of the job, segments of groups included, with fresh signed URLs for the files of
     * the temporary storage.
     *
     * @return LazyCollection<int, OutputFile>
     */
    public function files(string $id, ?string $task = null): LazyCollection
    {
        return LazyCollection::make(function () use ($id, $task) {
            $cursor = null;
            do {
                $query = array_filter(['task' => $task, 'cursor' => $cursor], fn ($value) => $value !== null);
                $page = $this->client->json('GET', '/v1/jobs/'.Client::segment($id).'/files', $query);
                foreach (is_array($page['data'] ?? null) ? $page['data'] : [] as $file) {
                    yield OutputFile::fromArray(is_array($file) ? $file : []);
                }
                $cursor = is_string($page['nextCursor'] ?? null) ? $page['nextCursor'] : null;
            } while ($cursor !== null);
        });
    }
}
