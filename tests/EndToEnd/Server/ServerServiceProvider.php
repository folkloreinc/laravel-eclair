<?php

namespace Folklore\Eclair\Tests\EndToEnd\Server;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

/**
 * Configures the application of `server.php`: the storage endpoint serves the `app` storage from a
 * local disk at `ECLAIR_E2E_DISK`, signed with `ECLAIR_E2E_WEBHOOK_SECRET`.
 */
final class ServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(Repository::class);
        $config->set('filesystems.disks.e2e', ['driver' => 'local', 'root' => (string) getenv('ECLAIR_E2E_DISK')]);
        $config->set('eclair.webhook.secret', (string) getenv('ECLAIR_E2E_WEBHOOK_SECRET'));
        $config->set('eclair.storages', ['app' => ['disk' => 'e2e', 'serve' => true]]);
        $config->set('eclair.storage_endpoint.route', true);
    }
}
