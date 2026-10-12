<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The live site runs with a cached configuration (php artisan config:cache), which holds the real database
     * settings. phpunit.xml points tests at a separate cache file so that one is never loaded; if that line is
     * ever removed, stop before the application boots — otherwise the tests would wipe the real database.
     */
    protected function setUp(): void
    {
        if (! str_contains((string) getenv('APP_CONFIG_CACHE'), 'phpunit')) {
            throw new RuntimeException('Tests must not load the cached live configuration. Keep APP_CONFIG_CACHE in phpunit.xml.');
        }

        parent::setUp();
    }
}
