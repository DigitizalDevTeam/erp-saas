<?php

use Illuminate\Database\Migrations\Migration;
use App\Services\PosRestaurantRoleService;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add "manage pos production" permission (via service) and ensure Kitchen/Bar
     * roles exist for all companies with only POS Production access.
     */
    public function up(): void
    {
        PosRestaurantRoleService::createForAllExistingCompanies();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Leave permission and roles in place to avoid breaking assigned users.
    }
};
