<?php

namespace Modules\HotelManagement\Providers;

use App\Models\Utility;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\HotelManagement\Entities\HotelBookingSite;
use Modules\HotelManagement\Http\Middleware\ResolveHotelBookingTenant;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Console\HotelE2eDemoCommand;
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
        $this->registerHotelGates();
        $this->registerPublicBookingRoutes();
        $this->registerPublicBookingViewComposers();
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
                HotelE2eDemoCommand::class,
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

    protected function registerPublicBookingViewComposers(): void
    {
        View::composer('hotelmanagement::public.*', function ($view) {
            $site = $view->getData()['site'] ?? null;
            if (! $site instanceof HotelBookingSite) {
                return;
            }

            $accent = $site->accent_color ?? '#0d9488';
            $tenantSettings = Utility::settingsById((int) $site->created_by);
            $currencySymbol = $tenantSettings['site_currency_symbol'] ?? '$';
            $currencyPos = $tenantSettings['site_currency_symbol_position'] ?? 'pre';
            $decimals = (int) ($tenantSettings['decimal_number'] ?? 2);

            $hbPrice = static function ($amount) use ($currencySymbol, $currencyPos, $decimals) {
                $formatted = number_format((float) $amount, $decimals);
                $space = ' ';

                return $currencyPos === 'post'
                    ? $formatted . $space . $currencySymbol
                    : $currencySymbol . $formatted;
            };

            $view->with(compact('accent', 'hbPrice'));
        });
    }

    protected function registerPublicBookingRoutes(): void
    {
        Route::aliasMiddleware('hotel.booking.tenant', ResolveHotelBookingTenant::class);

        $this->app->booted(function () {
            Route::middleware('web')
                ->namespace('Modules\\HotelManagement\\Http\\Controllers')
                ->group(module_path('HotelManagement', 'Routes/public_booking.php'));

            Route::post('webhooks/hotel-stripe', [
                \Modules\HotelManagement\Http\Controllers\HotelStripeWebhookController::class,
                'handle',
            ])->name('hotel.stripe.webhook');
        });
    }

    protected function registerHotelGates(): void
    {
        $gates = [
            'hotel.menu.module' => 'canAccessModule',
            'hotel.menu.dashboard' => 'menuDashboard',
            'hotel.menu.reservations' => 'menuReservations',
            'hotel.menu.frontdesk' => 'menuFrontdesk',
            'hotel.menu.guest_map' => 'menuGuestMap',
            'hotel.menu.housekeeping' => 'menuHousekeeping',
            'hotel.menu.night_audit' => 'menuNightAudit',
            'hotel.menu.accounting' => 'menuAccounting',
            'hotel.menu.reports' => 'menuReports',
            'hotel.menu.calendar' => 'menuCalendar',
            'hotel.menu.groups' => 'menuGroups',
            'hotel.menu.ota' => 'menuOta',
            'hotel.menu.booking_site' => 'menuBookingSite',
            'hotel.menu.rooms_setup' => 'menuRoomsSetup',
            'hotel.manage.reservations' => 'manageReservations',
            'hotel.manage.rooms' => 'manageRooms',
            'hotel.manage.frontdesk' => 'manageFrontdesk',
            'hotel.manage.folio' => 'manageFolio',
            'hotel.view.folio' => 'viewFolio',
        ];

        foreach ($gates as $name => $method) {
            Gate::define($name, fn ($user) => HotelAuthorization::{$method}($user));
        }
    }
}
