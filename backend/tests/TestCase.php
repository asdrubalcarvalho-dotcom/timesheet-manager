<?php

namespace Tests;

use App\Http\Middleware\AllowCentralDomainFallback;
use App\Http\Middleware\EnsureTenantDomainRegistered;
use App\Http\Middleware\InitializeTenancyByDomainWithFallback;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Allow tests to rely on header-based tenant resolution without DNS setup.
        $this->withoutMiddleware([
            AllowCentralDomainFallback::class,
            EnsureTenantDomainRegistered::class,
            InitializeTenancyByDomainWithFallback::class,
        ]);
    }
}
