<?php

namespace Folklore\Eclair\Console;

use Folklore\Eclair\Eclair;
use Folklore\Eclair\Exceptions\EclairException;
use Folklore\Eclair\Exceptions\RequestFailed;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Router;

/**
 * Checks the connection to Eclair, the key, the webhook and the storages.
 */
final class StatusCommand extends Command
{
    protected $signature = 'eclair:status';

    protected $description = 'Check the connection to Eclair, the API key, the webhook and the storages';

    private bool $failed = false;

    public function handle(Eclair $eclair, Config $config, Factory $http, Router $router, UrlGenerator $url): int
    {
        $server = (string) $config->get('eclair.url');
        if ($server === '' || (string) $config->get('eclair.key') === '') {
            $this->fail('Set ECLAIR_URL and ECLAIR_KEY.');
        }

        $this->components->info('Eclair at '.$server);
        try {
            $ready = $http->timeout(10)->get(rtrim($server, '/').'/ready');
            $this->check($ready->successful(), 'The server is ready', 'The server is not ready: it answered '.$ready->status());
        } catch (ConnectionException $exception) {
            $this->check(false, '', 'Could not reach the server: '.$exception->getMessage());
        }
        try {
            $operations = $eclair->operations();
            $this->check(true, sprintf('The key works: the project may use %d operations', count($operations)), '');
        } catch (RequestFailed $exception) {
            $this->check(false, '', in_array($exception->status, [401, 403], true)
                ? 'The key is refused (ECLAIR_KEY)'
                : $exception->getMessage());
        } catch (EclairException $exception) {
            $this->check(false, '', $exception->getMessage());
        }

        $route = $router->getRoutes()->getByName('eclair.webhook');
        if ($route === null) {
            $this->components->twoColumnDetail('Webhook route', 'off (eclair.webhook.route)');
        } else {
            $this->components->twoColumnDetail('Webhook route', 'POST '.$url->route('eclair.webhook'));
            $this->check(
                (string) $config->get('eclair.webhook.secret') !== '',
                'The webhook secret is set',
                'ECLAIR_WEBHOOK_SECRET is not set: every webhook is refused',
            );
        }

        $storages = $eclair->storages();
        $endpoint = $router->getRoutes()->getByName('eclair.storage.write') !== null;
        if ($storages === []) {
            $this->components->warn('No storage in eclair.storages: jobs can only use URLs, uploads and the temporary storage.');
        }
        foreach ($storages as $name => $storage) {
            $disk = (string) ($storage['disk'] ?? '');
            $driver = $config->get('filesystems.disks.'.$disk.'.driver');
            if (! is_string($driver)) {
                $this->check(false, '', sprintf('Storage "%s": the disk "%s" does not exist', $name, $disk));

                continue;
            }
            if (($storage['serve'] ?? false) === true) {
                $this->check(
                    $endpoint,
                    sprintf('Storage "%s": http, served from the disk "%s" at %s', $name, $disk, $url->to(trim((string) $config->get('eclair.storage_endpoint.path'), '/').'/'.$name)),
                    sprintf('Storage "%s" is served, but the endpoint is off (eclair.storage_endpoint.route)', $name),
                );
            } elseif ($driver === 's3') {
                $this->components->twoColumnDetail(
                    sprintf('Storage "%s"', $name),
                    sprintf('s3, on the bucket of the disk "%s"', $disk),
                );
            } else {
                $this->check(false, '', sprintf(
                    'Storage "%s": Eclair can\'t read the %s disk "%s". Serve it (serve: true) or use the temporary storage',
                    $name,
                    $driver,
                    $disk,
                ));
            }
        }

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function check(bool $passed, string $success, string $failure): void
    {
        if ($passed) {
            $this->components->twoColumnDetail($success, '<fg=green;options=bold>OK</>');
        } else {
            $this->failed = true;
            $this->components->error($failure);
        }
    }
}
