<?php

namespace Modules\HotelManagement\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class HotelManagementDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguard();

        $this->call(HotelDummyDataSeeder::class);

        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            $this->call(HotelDemoRolesSeeder::class);
        }
    }
}
