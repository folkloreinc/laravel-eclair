<?php

namespace Folklore\Eclair;

use Folklore\Eclair\Console\StatusCommand;
use Folklore\Eclair\Events\WebhookReceived;
use Folklore\Eclair\Http\Controllers\StorageController;
use Folklore\Eclair\Http\Controllers\WebhookController;
use Folklore\Eclair\Mediatheque\EclairProber;
use Folklore\Eclair\Mediatheque\EclairTranscoder;
use Folklore\Eclair\Mediatheque\ReconcileWebhook;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
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

        $this->registerMediatheque();
    }

    public function boot(Config $config, Router $router, Dispatcher $events): void
    {
        // Registered by its own provider, when the mediatheque is in use, not only installed
        if (self::hasMediatheque() && $this->app->bound(Recorder::class)) {
            $events->listen(WebhookReceived::class, ReconcileWebhook::class);
        }

        $this->publishes([__DIR__.'/../config/eclair.php' => $this->app->configPath('eclair.php')], 'eclair-config');

        if ($this->app->runningInConsole()) {
            $this->commands([StatusCommand::class]);
        }

        if (! $this->app->routesAreCached()) {
            $this->routes($config, $router);
        }
    }

    /**
     * The `eclair` transcoder of laravel-mediatheque 2, and its prober when
     * `eclair.mediatheque.prober` is on. Nothing without the mediatheque.
     */
    private function registerMediatheque(): void
    {
        if (! self::hasMediatheque()) {
            return;
        }

        $this->callAfterResolving(TranscoderManager::class, function (TranscoderManager $transcoders): void {
            $transcoders->extend('eclair', fn (Container $app) => $app->make(EclairTranscoder::class));
        });

        // Whatever order the providers register in, the mediatheque's prober is replaced when resolved
        $this->app->extend(Prober::class, fn (Prober $prober, Container $app): Prober => $app->make(Config::class)->get('eclair.mediatheque.prober') === true
            ? $app->make(EclairProber::class)
            : $prober);
    }

    private static function hasMediatheque(): bool
    {
        return interface_exists(Transcoder::class);
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
