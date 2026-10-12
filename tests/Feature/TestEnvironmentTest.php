<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestEnvironmentTest extends TestCase
{
    public function test_tests_never_use_the_live_configuration_or_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertFalse(app()->configurationIsCached(), 'The cached live configuration must not be loaded in tests.');
        $this->assertSame(['sqlite', ':memory:'], [config('database.default'), config('database.connections.sqlite.database')]);
        $this->assertSame(['array', 'array'], [config('cache.default'), config('session.driver')]);
    }
}
