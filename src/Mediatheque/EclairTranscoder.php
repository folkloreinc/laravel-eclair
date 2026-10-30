<?php

namespace Folklore\Eclair\Mediatheque;

use Folklore\Eclair\Eclair;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Enums\ProgressReporting;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Capabilities;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The `eclair` transcoder of laravel-mediatheque 2: a pipeline run is one Eclair job, whose tasks
 * are named after the renditions. Eclair writes the files under the run's path prefix, on the
 * Eclair storage of the run's output source, and its webhooks report them (ReconcileWebhook).
 *
 * - A rendition whose `when` doesn't match the probed media is skipped here, with those that read
 *   from it: the media is always probed before its pipeline runs, and Eclair only checks
 *   durations against a probe of its own.
 * - The job's idempotency key is the run's key, so submitting a run twice gives one job.
 */
class EclairTranscoder implements Transcoder
{
    /** Eclair's built-in operations. `eclair.mediatheque.operations` adds the server's others. */
    public const array OPERATIONS = [
        'probe',
        'video.transcode',
        'video.thumbnails',
        'video.poster',
        'video.hls',
        'audio.transcode',
        'audio.waveform',
        'image.convert',
        'image.thumbnail',
        'image.palette',
        'document.thumbnails',
        'font.convert',
    ];

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Recorder $recorder,
        private readonly Reconciler $reconciler,
    ) {}

    public function supports(string $operation): bool
    {
        $operation = explode('@', $operation)[0];

        return in_array($operation, [...self::OPERATIONS, ...(array) $this->config->get('eclair.mediatheque.operations', [])], true);
    }

    public function capabilities(): Capabilities
    {
        // Progress comes with the `task.progress` webhooks, for projects that subscribe to them
        $progress = $this->config->get('eclair.mediatheque.progress') === true ? ProgressReporting::Pushed : ProgressReporting::None;

        return new Capabilities(progress: $progress, cancel: true, refresh: true, probe: true);
    }

    public function submit(PipelineRun $run): ?string
    {
        $media = $run->media();
        $metadata = $media->metadata();
        $duration = $metadata[MetadataKey::Duration->value] ?? null;
        $duration = is_int($duration) || is_float($duration) ? (float) $duration : null;

        $skipped = $this->skipped($run->renditions(), $metadata);
        $tasks = [];
        foreach ($run->renditions() as $name => $rendition) {
            if (! isset($skipped[$name])) {
                $tasks[$name] = $this->task($rendition, $duration);
            }
        }

        $externalId = null;
        if ($tasks !== []) {
            $eclair = $this->eclair();
            $job = $eclair->jobs()->create([
                'input' => $this->input($eclair, $run->input()),
                'output' => $eclair->output($run->outputSource(), rtrim($run->pathPrefix(), '/').'/'),
                'tasks' => $tasks,
                'metadata' => [
                    'mediatheque' => ['run' => $run->key(), 'media' => $media->uid(), 'pipeline' => $run->name()],
                ],
            ], 'mediatheque-'.$run->key());
            $externalId = $job->id;
        }

        foreach ($skipped as $name => $reason) {
            $job = $run->job($name);
            if ($job !== null) {
                $this->recorder->skipped($job, $reason);
            }
        }

        return $externalId;
    }

    public function cancel(PipelineRun $run): void
    {
        if ($run->externalId() !== null) {
            $this->reconciler->reconcile($run, $this->eclair()->jobs()->cancel($run->externalId()));
        }
    }

    public function refresh(PipelineRun $run): void
    {
        if ($run->externalId() !== null) {
            $this->reconciler->reconcile($run, $this->eclair()->jobs()->find($run->externalId()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function task(Rendition $rendition, ?float $duration): array
    {
        $task = ['op' => $rendition->operation];
        $options = TaskOptions::for($rendition, $duration);
        if ($options !== []) {
            $task['options'] = $options;
        }
        if ($rendition->from !== null) {
            $task['from'] = $rendition->from;
        }
        if (! $rendition->keep) {
            $task['keep'] = false;
        }

        return $task;
    }

    /**
     * The renditions that don't run, with why: their condition doesn't match, or the rendition they
     * read from doesn't run.
     *
     * @param  array<string, Rendition>  $renditions
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    private function skipped(array $renditions, array $metadata): array
    {
        $skipped = [];
        foreach ($renditions as $name => $rendition) {
            if (! $rendition->when->matches($metadata)) {
                $skipped[$name] = 'The media doesn\'t match its condition (`when`).';
            }
        }
        do {
            $added = false;
            foreach ($renditions as $name => $rendition) {
                if (! isset($skipped[$name]) && $rendition->from !== null && isset($skipped[$rendition->from])) {
                    $skipped[$name] = sprintf('The rendition "%s" it reads from was skipped.', $rendition->from);
                    $added = true;
                }
            }
        } while ($added);

        return $skipped;
    }

    /**
     * @return array<string, string>
     */
    private function input(Eclair $eclair, File $file): array
    {
        $source = $file->source();
        $path = $file->path();
        if ($source !== null && $path !== null) {
            return $eclair->input($source, $path);
        }
        $url = $file->url();
        if ($url === null) {
            throw new InvalidArgumentException('The original of the run has neither a source nor a URL.');
        }

        return ['url' => $url];
    }

    private function eclair(): Eclair
    {
        return $this->container->make(Eclair::class);
    }
}
