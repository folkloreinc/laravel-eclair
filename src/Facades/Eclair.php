<?php

namespace Folklore\Eclair\Facades;

use Folklore\Eclair\Eclair as EclairClient;
use Folklore\Eclair\Testing\EclairFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Folklore\Eclair\Jobs jobs()
 * @method static \Folklore\Eclair\Presets presets()
 * @method static \Folklore\Eclair\Uploads uploads()
 * @method static \Folklore\Eclair\Data\ProbeResult probe(array<string, mixed> $input, ?int $timeout = null, bool $checksum = false, bool $thumbhash = false)
 * @method static \Folklore\Eclair\Data\ProbeResult probeFile(mixed $file, ?int $timeout = null, bool $checksum = false, bool $thumbhash = false)
 * @method static list<array<string, mixed>> operations()
 * @method static string storageFor(string $disk)
 * @method static array{storage: string, path: string} input(string $disk, string $path)
 * @method static array{storage: string, prefix: string, path?: string} output(string $disk, string $prefix, ?string $path = null)
 * @method static string download(\Folklore\Eclair\Data\OutputFile $file, string $disk, ?string $path = null)
 * @method static list<string> downloadFiles(\Folklore\Eclair\Data\Job|string $job, string $disk, ?string $task = null)
 *
 * @see EclairClient
 * @see EclairFake
 */
final class Eclair extends Facade
{
    /**
     * Replaces Eclair with an in-memory server, for tests. The client sends its requests as usual,
     * through Laravel's HTTP client, and the fake answers them.
     */
    public static function fake(): EclairFake
    {
        $fake = EclairFake::make(self::getFacadeApplication() ?? app());
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return EclairClient::class;
    }
}
