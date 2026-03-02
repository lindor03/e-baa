<?php

namespace Webkul\Widgets\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;

class WidgetsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../Routes/admin-routes.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/shop-routes.php');

        // Load translations
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'widgets');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'widgets');

        // Publish JS / CSS assets to public/vendor/widgets
        $this->publishes([
            __DIR__ . '/../Resources/assets' => public_path('vendor/widgets'),
        ], 'widgets-assets');

        // Inject custom admin styles
        Event::listen('bagisto.admin.layout.head', function($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('widgets::admin.layouts.style');
        });
    }

    /**
     * Register package config.
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
