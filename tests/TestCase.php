<?php

namespace Ashrafic\UspsShipping\Tests;

use Ashrafic\UspsShipping\Providers\UspsShippingServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    /**
     * Load package service providers.
     *
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [UspsShippingServiceProvider::class];
    }
}
