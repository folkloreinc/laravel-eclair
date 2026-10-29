<?php

namespace Folklore\Eclair\Mediatheque;

use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds the pipeline runs of Eclair's jobs, for webhooks. Without the application's global scopes:
 * a tenant scope that depends on the request would hide the run.
 *
 * @internal
 */
final class Runs
{
    /** The run with this key, sent in the job's `metadata`. */
    public static function find(string $key): ?PipelineRun
    {
        $run = Models::pipelineRun()::query()->withoutGlobalScopes()->where('uid', $key)->with('pipelineJobs')->first();

        return $run instanceof PipelineRun ? $run : null;
    }

    /** The probe run whose prober answered with this job. */
    public static function probe(string $jobId): ?PipelineRun
    {
        $run = Models::pipelineRun()::query()
            ->withoutGlobalScopes()
            ->where('name', PipelineDefinition::PROBE)
            ->where('external_id', $jobId)
            ->with('pipelineJobs')
            ->latest('id')
            ->first();

        return $run instanceof PipelineRun ? $run : null;
    }

    /** The run as it is now in the database, after the recorder changed it. */
    public static function fresh(PipelineRun $run): PipelineRun
    {
        return $run instanceof Model ? (self::find($run->key()) ?? $run) : $run;
    }
}
