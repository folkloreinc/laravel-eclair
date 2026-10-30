<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server
    |--------------------------------------------------------------------------
    |
    | The URL of the Eclair server and the project's API key (`ek_…`).
    | `timeout` is in seconds, for each request to the API.
    |
    */

    'url' => env('ECLAIR_URL'),

    'key' => env('ECLAIR_KEY'),

    'timeout' => (int) env('ECLAIR_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Eclair calls `POST {path}` when a job ends, signed with the project's
    | webhook secret. The route checks the signature, ignores deliveries it
    | already handled and dispatches events (see the README).
    |
    | `url` is sent with every job. Leave it empty to use the project's webhook.
    | `cache` is the cache store that remembers the deliveries: it must
    | support locks and be shared by every server of the application.
    |
    */

    'webhook' => [
        'secret' => env('ECLAIR_WEBHOOK_SECRET'),
        'route' => true,
        'path' => 'eclair/webhook',
        'middleware' => [],
        'url' => env('ECLAIR_WEBHOOK_URL'),
        'tolerance' => 300,
        'cache' => env('ECLAIR_WEBHOOK_CACHE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storages
    |--------------------------------------------------------------------------
    |
    | The project's storages in Eclair, by name, and the Laravel disk each one
    | points to. Paths are the same on both sides: an `s3` storage has the
    | prefix of the disk's root.
    |
    | A storage with `serve` is an `http` storage served by this application
    | (see `storage_endpoint`): register it in Eclair with the URL
    | `{APP_URL}/{path}/{name}`.
    |
    |   'media' => ['disk' => 's3'],
    |   'files' => ['disk' => 'local', 'serve' => true],
    |
    */

    'storages' => [],

    /*
    |--------------------------------------------------------------------------
    | Endpoint of http storages
    |--------------------------------------------------------------------------
    |
    | `GET {path}/{storage}/{file}` serves inputs and `PUT` receives outputs,
    | for the storages with `serve`. Every request is signed with the webhook
    | secret. `max_size` limits a received file, in bytes.
    |
    */

    'storage_endpoint' => [
        'route' => false,
        'path' => 'eclair/storages',
        'middleware' => [],
        'max_size' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | laravel-mediatheque 2
    |--------------------------------------------------------------------------
    |
    | With laravel-mediatheque 2 installed, pipelines can name the `eclair`
    | transcoder. Its runs are reconciled from the webhooks, so the webhook
    | route must be on, with the project's `taskWebhooks` setting to record
    | each rendition as soon as it completes.
    |
    | `progress` says that the project has the `progressWebhooks` setting: its
    | `task.progress` webhooks report how far each rendition is while it runs,
    | and the transcoder tells the mediatheque that progress is pushed.
    |
    | `prober` makes Eclair probe the media too, instead of the server's own
    | binaries. A probe longer than `probe_timeout` seconds (30 at most)
    | completes with its job's webhook.
    |
    | `operations` adds operations of this Eclair server that pipelines may
    | use, besides the built-in ones (plugins, AI operations).
    |
    */

    'mediatheque' => [
        'progress' => (bool) env('ECLAIR_MEDIATHEQUE_PROGRESS', false),
        'prober' => (bool) env('ECLAIR_MEDIATHEQUE_PROBER', false),
        'probe_timeout' => 10,
        'operations' => [],
    ],
];
