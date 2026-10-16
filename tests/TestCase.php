<?php

namespace Folklore\Eclair\Tests;

use Folklore\Eclair\EclairServiceProvider;
use Folklore\Eclair\Facades\Eclair;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [EclairServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Eclair' => Eclair::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('eclair.url', 'https://eclair.example.com');
            $config->set('eclair.key', 'ek_test');
            $config->set('eclair.webhook.secret', 'whsec_test');
            $config->set('cache.default', 'array');
        });
    }
}
