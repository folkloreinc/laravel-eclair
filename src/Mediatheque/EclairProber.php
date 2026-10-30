<?php

namespace Folklore\Eclair\Mediatheque;

use Folklore\Eclair\Eclair;
use Folklore\Eclair\Exceptions\RequestFailed;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Probe\ProbeOutcome;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Probes media with Eclair (`POST /v1/probe`), when `eclair.mediatheque.prober` is on, with an
 * image's ThumbHash placeholder (`thumbhash`), as the mediatheque's local prober. A probe longer than `eclair.mediatheque.probe_timeout` is pending: the mediatheque records the job's id,
 * and the job's webhook completes the probe.
 */
class EclairProber implements Prober
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    public function probe(File $file): ProbeOutcome
    {
        $eclair = $this->container->make(Eclair::class);
        $source = $file->source();
        $path = $file->path();
        if ($source !== null && $path !== null) {
            $input = $eclair->input($source, $path);
        } elseif ($file->url() !== null) {
            $input = ['url' => $file->url()];
        } else {
            throw new InvalidArgumentException('The file has neither a source nor a URL.');
        }

        try {
            $result = $eclair->probe(
                $input,
                timeout: self::timeout($this->config),
                // The mediatheque's image placeholder, as its local prober gives one
                thumbhash: true,
            );
        } catch (RequestFailed $exception) {
            // The file can't be read, or its type isn't supported: retrying changes nothing
            if ($exception->status === 422) {
                $detail = $exception->problem['detail'] ?? $exception->problem['title'] ?? null;

                return ProbeOutcome::failed(new TranscoderError(
                    TranscoderErrorCode::UnsupportedInput,
                    is_string($detail) ? $detail : 'Eclair can\'t read the file.',
                ));
            }

            throw $exception;
        }

        if ($result->pending() && $result->job !== null) {
            return ProbeOutcome::pending($result->job->id);
        }

        return ProbeOutcome::done(ProbeMetadata::canonical($result->metadata ?? []));
    }

    /**
     * The seconds Eclair waits for a probe before answering `202` with its job.
     *
     * @internal
     */
    public static function timeout(Config $config): int
    {
        return min(30, max(1, (int) $config->get('eclair.mediatheque.probe_timeout', 10)));
    }
}
