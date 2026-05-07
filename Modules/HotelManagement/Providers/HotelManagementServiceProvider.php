<?php

namespace Modules\HotelManagement\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\HotelManagement\Console\DailyOwnerSummaryCommand;
use Modules\HotelManagement\Console\LowOccupancyAlertCommand;
use Modules\HotelManagement\Console\OtaSyncCommand;
use Modules\HotelManagement\Console\SendBirthdayPromotionsCommand;

class HotelManagementServiceProvider extends ServiceProvider
{
    /**
     * @var string
     */
    protected $moduleName = 'HotelManagement';

    /**
     * @var string
     */
    protected $moduleNameLower = 'hotelmanagement';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                SendBirthdayPromotionsCommand::class,
                OtaSyncCommand::class,
                DailyOwnerSummaryCommand::class,
                LowOccupancyAlertCommand::class,
            ]);

            $this->app->booted(function () {
                /** @var Schedule $schedule */
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('hotelmanagement:send-birthday-promotions')->dailyAt('08:00');
                $schedule->command('hotelmanagement:ota-sync')->everyFifteenMinutes();
                $schedule->command('hotelmanagement:daily-owner-summary')->dailyAt('22:00');
                $schedule->command('hotelmanagement:low-occupancy-alert')->dailyAt('09:30');
            });
        }
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'Config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');

        $this->mergeConfigFrom(
            module_path($this->moduleName, 'Config/config.php'),
            $this->moduleNameLower
        );
    }

    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'Resources/views');

        $this->publishes([
            $sourcePath => $viewPath,
        ], ['views', $this->moduleNameLower . '-module-views']);

        $this->loadViewsFrom(
            array_merge($this->getPublishableViewPaths(), [$sourcePath]),
            $this->moduleNameLower
        );
    }

    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
            $this->loadJsonTranslationsFrom($langPath, $this->moduleNameLower);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'Resources/lang'), $this->moduleNameLower);
            $this->loadJsonTranslationsFrom(module_path($this->moduleName, 'Resources/lang'), $this->moduleNameLower);
        }
    }

    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (\Config::get('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $this->moduleNameLower)) {
                $paths[] = $path . '/modules/' . $this->moduleNameLower;
            }
        }

        return $paths;
    }
}
