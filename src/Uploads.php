<?php

namespace Folklore\Eclair;

use Folklore\Eclair\Data\Upload;
use Folklore\Eclair\Support\Body;

/**
 * Files sent to Eclair's temporary storage, for applications whose files Eclair can't read.
 */
final class Uploads
{
    /**
     * @internal
     */
    public function __construct(private readonly Client $client) {}

    /**
     * Sends a file at once, within the project's direct upload limit. With `probe`, the upload
     * has the file's metadata.
     *
     * @param  mixed  $file  Its contents, a stream, or a file object
     */
    public function create(mixed $file, bool $probe = false): Upload
    {
        return Upload::fromArray($this->client->json(
            'POST',
            '/v1/uploads',
            $probe ? ['probe' => '1'] : [],
            body: Body::from($file),
            headers: ['Content-Type' => 'application/octet-stream'],
        ));
    }

    public function find(string $id): Upload
    {
        return Upload::fromArray($this->client->json('GET', '/v1/uploads/'.Client::segment($id)));
    }

    public function delete(string $id): void
    {
        $this->client->send('DELETE', '/v1/uploads/'.Client::segment($id));
    }
}
