<?php

/*
 * The application that serves the `app` storage to Eclair in the end-to-end tests, run by
 * StorageTest with PHP's built-in server: `php -S 0.0.0.0:8099 tests/EndToEnd/Server/server.php`.
 */

use Folklore\Eclair\EclairServiceProvider;
use Folklore\Eclair\Tests\EndToEnd\Server\ServerServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../../vendor/autoload.php';

$app = Application::create(options: [
    'extra' => ['providers' => [EclairServiceProvider::class, ServerServiceProvider::class]],
]);

$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
