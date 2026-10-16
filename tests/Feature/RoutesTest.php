<?php

namespace Folklore\Eclair\Tests\Feature;

use Folklore\Eclair\Tests\TestCase;
use Illuminate\Support\Facades\Route;

final class RoutesTest extends TestCase
{
    public function test_the_storage_endpoint_is_off_by_default(): void
    {
        $this->assertTrue(Route::has('eclair.webhook'));
        $this->assertFalse(Route::has('eclair.storage.read'));
        $this->assertFalse(Route::has('eclair.storage.write'));
    }
}
