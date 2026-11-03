<?php

namespace Folklore\Eclair\MediathequeV1;

use Folklore\Eclair\Data\Job as EclairJob;
use Folklore\Eclair\Data\OutputFile;
use Folklore\Eclair\Data\Task;
use Folklore\Eclair\Eclair;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Metadata\Value;
use Folklore\Mediatheque\Support\PipelineJob;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * A laravel-mediatheque 1.1 job that runs on Eclair, in place of the mediatheque's own: same
 * options, same files, same handles. It works like the mediatheque's `Video\MediaConvert` job, in
 * the pipeline's queued job: it sends one Eclair job, waits for it to end without using the
 * worker's CPU, then moves the files Eclair wrote next to the original, as the mediatheque's
 * jobs store theirs.
 *
 * The source of the file must be a `filesystem` source whose disk is mapped to an Eclair storage
 * (`eclair.storages`): Eclair reads the original and writes the files there.
 */
abstract class Job extends PipelineJob
{
    /**
     * The Eclair tasks, by name, as the protocol's `tasks` of a job.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function tasks(): array;

    /**
     * The mediatheque's files, from the Eclair job that completed: one file, or files by handle
     * or by index, as the mediatheque's job returns them.
     *
     * @return FileContract|array<array-key, FileContract>|null
     */
    abstract protected function files(EclairJob $job): FileContract|array|null;

    /**
     * @return FileContract|array<array-key, FileContract>|null
     */
    public function handle()
    {
        return $this->files($this->run($this->tasks()));
    }

    /**
     * Sends the tasks as one Eclair job and waits for it to end.
     *
     * @param  array<string, array<string, mixed>>  $tasks
     */
    protected function run(array $tasks): EclairJob
    {
        $eclair = app(Eclair::class);
        [$disk, $path] = $this->location();
        $job = $eclair->jobs()->create([
            'input' => $eclair->input($disk, $path),
            'output' => $eclair->output($disk, $this->outputPrefix()),
            'tasks' => $tasks,
            'metadata' => [
                'mediatheque' => [
                    'version' => 1,
                    'file' => $this->file->getKey(),
                    'job' => static::class,
                ],
            ],
        ]);

        $interval = max(1, (int) config('eclair.mediatheque_v1.poll_interval', 3));
        $timeout = (int) config('eclair.mediatheque_v1.timeout', 3600);
        $deadline = time() + $timeout;
        while (! $job->ended()) {
            if (time() >= $deadline) {
                $eclair->jobs()->cancel($job->id);

                throw JobFailed::timedOut($job, $timeout);
            }
            Sleep::for($interval)->seconds();
            $job = $eclair->jobs()->find($job->id);
        }
        if ($job->status->value !== 'completed') {
            throw JobFailed::fromJob($job);
        }

        return $job;
    }

    /**
     * Moves a file Eclair wrote to the path the mediatheque gives its files, and records it.
     *
     * @param  array<string, array{0: mixed, 1: string}>  $metadata  By name: the value and its type (`float`, `integer`, `string`)
     */
    protected function makeFile(OutputFile $output, string $type, array $metadata = []): FileContract
    {
        $values = [];
        foreach ($metadata as $name => [$value, $valueType]) {
            if ($value !== null) {
                $values[$name] = new Value($name, $value, $valueType);
            }
        }

        $file = app(FileContract::class);
        $file->setFileFromSource($this->sourcePath($output->path), [
            'source' => $this->file->source,
            'type' => $type,
            'mime' => $output->mime,
            'size' => $output->size,
            'extension' => pathinfo($output->path, PATHINFO_EXTENSION),
            'metadata' => $values,
        ]);
        $file->save();

        return $file;
    }

    /**
     * The files of a task, in order.
     *
     * @return list<OutputFile>
     */
    protected function outputs(EclairJob $job, string $task): array
    {
        $found = $job->task($task);

        return $found === null ? [] : $found->files;
    }

    /**
     * Width, height and duration of a task's output, from its metadata.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    protected function dimensions(?Task $task): array
    {
        $metadata = $task === null ? [] : ($task->metadata ?? []);

        return [
            'width' => [is_numeric($metadata['width'] ?? null) ? (int) $metadata['width'] : null, 'integer'],
            'height' => [is_numeric($metadata['height'] ?? null) ? (int) $metadata['height'] : null, 'integer'],
            'duration' => [is_numeric($metadata['duration'] ?? null) ? (float) $metadata['duration'] : null, 'float'],
        ];
    }

    protected function option(string $name, mixed $default = null): mixed
    {
        return data_get($this->options, $name, $default);
    }

    /**
     * The disk of the file's source and the file's path on it.
     *
     * @return array{0: string, 1: string}
     */
    private function location(): array
    {
        $config = config('mediatheque.sources.'.$this->file->source);
        if (! is_array($config) || ($config['driver'] ?? null) !== 'filesystem' || ! isset($config['disk'])) {
            throw new InvalidArgumentException(sprintf(
                'Eclair reads the files of `filesystem` sources only: the source "%s" of the file %s is not one.',
                $this->file->source,
                $this->file->getKey(),
            ));
        }
        $disk = (string) $config['disk'];
        // As the mediatheque's FilesystemSource: `cloud` is the default cloud disk
        if ($disk === 'cloud') {
            $disk = (string) config('filesystems.cloud');
        }

        return [$disk, $this->diskPath((string) $this->file->path)];
    }

    /** A path of the source, as a path of its disk. */
    private function diskPath(string $path): string
    {
        return ltrim(rtrim($this->sourcePrefix(), '/').'/'.ltrim($path, '/'), '/');
    }

    /** A path of the disk, as a path of the source. */
    private function sourcePath(string $path): string
    {
        $prefix = trim($this->sourcePrefix(), '/');

        return $prefix !== '' && str_starts_with($path, $prefix.'/') ? substr($path, strlen($prefix) + 1) : $path;
    }

    private function sourcePrefix(): string
    {
        return (string) config('mediatheque.sources.'.$this->file->source.'.path', '/');
    }

    /** Where Eclair writes, on the disk, before the files are moved to their own paths. */
    private function outputPrefix(): string
    {
        $base = trim((string) config('eclair.mediatheque_v1.output_path', 'eclair'), '/');
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', class_basename(static::class)));

        return $this->diskPath($base.'/'.$this->file->getKey().'-'.$name.'-'.bin2hex(random_bytes(4))).'/';
    }
}
