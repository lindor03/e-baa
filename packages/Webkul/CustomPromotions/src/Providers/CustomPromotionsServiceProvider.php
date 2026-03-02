<?php

namespace Webkul\CustomPromotions\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Blade;

class CustomPromotionsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Webkul\CustomPromotions\Console\Commands\ApplyPromotions::class,
            ]);
        }

    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        \Log::info('✅ Provider custompromotions booted');
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__ . '/../Routes/admin-routes.php');

        $this->loadRoutesFrom(__DIR__ . '/../Routes/shop-routes.php');

        $this->loadRoutesFrom(__DIR__ . '/../Routes/api-routes.php');

        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'custompromotions');

        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'custompromotions');

        Event::listen('bagisto.admin.layout.head', function($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('custompromotions::admin.layouts.style');
        });

        Blade::anonymousComponentPath(__DIR__ . '/../Resources/views/components','custompromotions');
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/admin-menu.php', 'menu.admin'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/acl.php', 'acl'
        );
    }
}
