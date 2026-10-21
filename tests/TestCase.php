<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * What the fake Have I Been Pwned answers for the breached-password check: "SUFFIX:COUNT" lines, or empty for "not breached".
     */
    protected string $pwnedPasswordsResponse = '';

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

        // The breached-password check calls Have I Been Pwned; tests answer without the network.
        Http::fake(fn (Request $request) => str_starts_with($request->url(), 'https://api.pwnedpasswords.com/')
            ? Http::response($this->pwnedPasswordsResponse)
            : null);
    }
}
