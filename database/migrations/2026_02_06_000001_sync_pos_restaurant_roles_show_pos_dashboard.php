<?php

use Illuminate\Database\Migrations\Migration;
use App\Services\PosRestaurantRoleService;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Sync POS Restaurant roles so Cashier/Waiter/Manager get "show pos dashboard"
     * (fixes 500 on login and dashboard/menu visibility).
     */
    public function up(): void
    {
        PosRestaurantRoleService::createForAllExistingCompanies();
    }

    public function down(): void
    {
        // No-op
    }
};
