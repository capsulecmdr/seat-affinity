<?php

namespace CapsuleCmdr\Affinity;

use Illuminate\Support\ServiceProvider;
use Seat\Services\AbstractSeatPlugin;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;

class AffinityServiceProvider extends AbstractSeatPlugin
{

    public function boot(Router $router): void
    {

        $this->add_routes();
        $this->add_views();
        
        $this->addPublications();
        
        $this->addMigrations();
        

    }
    private function addPublications(): void
    {
        // $this->publishes([
        //     __DIR__.'/resources/assets' => public_path('vendor/capsulecmdr/seat-osmm'),
        // ], ['public', 'osmm-assets', 'seat']);
    }

    private function add_routes(): void
    {
        if (! $this->app->routesAreCached()) {
            include __DIR__.'/Http/Routes.php';
        }
    }

    private function add_commands(): void
    {
        // $this->commands([
        //     UpgradeFits::class,
        // ]);
    }

    private function add_translations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/Resources/Lang', 'affinity');
    }

    private function add_views(): void
    {
        $this->loadViewsFrom(__DIR__.'/Resources/Views', 'affinity');
    }

    /**
     * Register bindings and configuration.
     */
    public function register(): void
    {
        //merge config
        $this->mergeConfigFrom(__DIR__.'/Config/Config.php','affinity');

        //merge sidebar
        $this->mergeConfigFrom(__DIR__.'/Config/Sidebar.php','package.sidebar');

        //merge notification alerts
        $this->mergeConfigFrom(__DIR__ . '/Config/Notifications.alerts.php','notifications.alerts');

        $this->add_translations();

        //register permissions
        $this->registerPermissions(__DIR__ . '/Config/Permissions/Permissions.php','affinity');

        //register settings helper
        $this->app->singleton('affinity.settings', function () {
            return new \CapsuleCmdr\Affinity\Support\AffinitySettings();
        });

        //register commands
        $this->commands([
            \CapsuleCmdr\Affinity\Console\Commands\PurgeEntities::class,
            \CapsuleCmdr\Affinity\Console\Commands\SyncEntities::class,
        ]);
    }

    private function addMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations/');
    }



    /**
     * Required metadata for SeAT Plugin Loader.
     */
    public function getName(): string
    {
        return 'SeAT-Affinity';
    }

    public function getPackagistVendorName(): string
    {
        return 'capsulecmdr';
    }

    public function getPackagistPackageName(): string
    {
        return 'seat-affinity';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/capsulecmdr/seat-affinity';
    }
}