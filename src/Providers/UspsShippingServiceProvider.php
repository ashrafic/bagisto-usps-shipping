<?php

namespace Ashrafic\UspsShipping\Providers;

use Ashrafic\UspsShipping\Client\UspsClient;
use Ashrafic\UspsShipping\Client\UspsOAuth;
use Ashrafic\UspsShipping\Console\Commands\TestUspsCommand;
use Ashrafic\UspsShipping\Listeners\OrderViewButton;
use Ashrafic\UspsShipping\Services\RateService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class UspsShippingServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'usps-shipping');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'usps-shipping');

        $this->loadRoutesFrom(__DIR__.'/../Routes/admin-routes.php');

        $this->listenForViewRenderEvents();
    }

    /**
     * Inject the USPS label button into the admin order view.
     */
    protected function listenForViewRenderEvents(): void
    {
        Event::listen(
            'bagisto.admin.sales.order.page_action.after',
            OrderViewButton::class
        );
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

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/menu.php', 'menu.admin'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/acl.php', 'acl'
        );
    }
}
