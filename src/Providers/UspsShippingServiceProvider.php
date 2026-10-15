<?php

namespace Ashrafic\UspsShipping\Providers;

use Ashrafic\UspsShipping\Client\UspsClient;
use Ashrafic\UspsShipping\Client\UspsOAuth;
use Ashrafic\UspsShipping\Console\Commands\TestUspsCommand;
use Ashrafic\UspsShipping\Services\RateService;
use Illuminate\Support\ServiceProvider;

class UspsShippingServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'usps-shipping');
    }

    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->registerConfig();

        $this->app->bind(RateService::class, function () {
            return new RateService(
                new UspsClient(new UspsOAuth)
            );
        });

        $this->commands([TestUspsCommand::class]);
    }

    /**
     * Register package configuration.
     */
    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/carriers.php', 'carriers'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/system.php', 'core'
        );
    }
}
