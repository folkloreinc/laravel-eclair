<?php

namespace Folklore\Eclair\Http\Controllers;

use Folklore\Eclair\Http\Problem;
use Folklore\Eclair\Support\Signature;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The endpoint of the `http` storages served by the application: Eclair reads inputs with `GET`
 * and writes outputs with `PUT`, signed with the project's webhook secret over the path and the
 * SHA-256 of the body.
 */
final class StorageController
{
    private const CHUNK = 1024 * 1024;

    public function __construct(
        private readonly Config $config,
        private readonly Filesystems $filesystems,
    ) {}

    public function show(Request $request, string $storage, string $path): SymfonyResponse
    {
        $disk = $this->disk($storage);
        if ($disk === null || ! self::validPath($path)) {
            return Problem::response(404, 'Not found');
        }
        if (! $this->verify($request, $path, Signature::EMPTY_SHA256)) {
            return Problem::response(401, 'Invalid signature');
        }
        if (! $disk->exists($path)) {
            return Problem::response(404, 'Not found');
        }
        $stream = $disk->readStream($path);
        if ($stream === null) {
            return Problem::response(404, 'Not found');
        }

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $disk->size($path),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, string $storage, string $path): SymfonyResponse
    {
        $disk = $this->disk($storage);
        if ($disk === null || ! self::validPath($path)) {
            return Problem::response(404, 'Not found');
        }
        $maxSize = $this->config->get('eclair.storage_endpoint.max_size');
        $maxSize = is_numeric($maxSize) ? (int) $maxSize : null;
        $length = $request->header('Content-Length');
        if ($maxSize !== null && is_numeric($length) && (int) $length > $maxSize) {
            return Problem::response(413, 'File too large');
        }

        // The body goes to a temporary file first: it reaches the disk only once it is verified.
        $input = $request->getContent(true);
        $file = tmpfile();
        if ($file === false) {
            return Problem::response(500, 'Could not store the file');
        }
        try {
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK);
                if ($chunk === false) {
                    break;
                }
                $size += strlen($chunk);
                if ($maxSize !== null && $size > $maxSize) {
                    return Problem::response(413, 'File too large');
                }
                hash_update($hash, $chunk);
                fwrite($file, $chunk);
            }
            if (! $this->verify($request, $path, hash_final($hash))) {
                return Problem::response(401, 'Invalid signature');
            }
            rewind($file);
            $options = [];
            $mime = $request->header('Content-Type');
            if (is_string($mime) && $mime !== '') {
                $options['mimetype'] = $mime;
            }
            if ($disk->writeStream($path, $file, $options) === false) {
                return Problem::response(500, 'Could not store the file');
            }
        } finally {
            fclose($file);
        }

        return new Response(null, 204);
    }

    private function disk(string $storage): ?Filesystem
    {
        $config = $this->config->get('eclair.storages.'.$storage);
        if (! is_array($config) || ($config['serve'] ?? false) !== true || ! isset($config['disk'])) {
            return null;
        }

        return $this->filesystems->disk((string) $config['disk']);
    }

    private function verify(Request $request, string $path, string $sha256): bool
    {
        return Signature::verify(
            $request->header('Eclair-Signature'),
            (string) $this->config->get('eclair.webhook.secret'),
            Signature::storagePayload($path, $sha256),
            (int) $this->config->get('eclair.webhook.tolerance', Signature::TOLERANCE),
        );
    }

    /** A relative path without empty, `.` or `..` segments. */
    private static function validPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 1024 || str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
