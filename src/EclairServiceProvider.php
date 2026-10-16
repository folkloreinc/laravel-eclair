<?php

namespace Folklore\Eclair;

use Folklore\Eclair\Console\StatusCommand;
use Folklore\Eclair\Http\Controllers\StorageController;
use Folklore\Eclair\Http\Controllers\WebhookController;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class EclairServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eclair.php', 'eclair');

        $this->app->singleton(Eclair::class, fn (Container $app): Eclair => new Eclair(
            $app->make(Factory::class),
            $app->make(Filesystems::class),
            $app->make(Config::class),
        ));
        $this->app->alias(Eclair::class, 'eclair');
    }

    public function boot(Config $config, Router $router): void
    {
        $this->publishes([__DIR__.'/../config/eclair.php' => $this->app->configPath('eclair.php')], 'eclair-config');

        if ($this->app->runningInConsole()) {
            $this->commands([StatusCommand::class]);
        }

        if (! $this->app->routesAreCached()) {
            $this->routes($config, $router);
        }
    }

    private function routes(Config $config, Router $router): void
    {
        if ($config->get('eclair.webhook.route', true) === true) {
            $router->post(trim((string) $config->get('eclair.webhook.path'), '/'), WebhookController::class)
                ->middleware((array) $config->get('eclair.webhook.middleware', []))
                ->name('eclair.webhook');
        }

        if ($config->get('eclair.storage_endpoint.route', false) === true) {
            $prefix = trim((string) $config->get('eclair.storage_endpoint.path'), '/');
            $middleware = (array) $config->get('eclair.storage_endpoint.middleware', []);
            $router->get($prefix.'/{storage}/{path}', [StorageController::class, 'show'])
                ->where('path', '.+')
                ->middleware($middleware)
                ->name('eclair.storage.read');
            $router->put($prefix.'/{storage}/{path}', [StorageController::class, 'store'])
                ->where('path', '.+')
                ->middleware($middleware)
                ->name('eclair.storage.write');
        }
    }
}
