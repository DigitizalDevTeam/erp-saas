<?php

namespace Database\Seeders;

use App\Models\Utility;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;


class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(NotificationSeeder::class);
        Artisan::call('module:migrate LandingPage');
        Artisan::call('module:seed LandingPage');

        if(!file_exists(storage_path() . "/installed"))
        {
            $this->call(PlansTableSeeder::class);
            $this->call(UsersTableSeeder::class);
            $this->call(AiTemplateSeeder::class);
            // IRPS withholding rates: without these rows every payslip
            // computes a 0.00 IRPS, so they are part of the base seed.
            $this->call(IrpsWithholdingMoz2014Seeder::class);
            // Management rules are NOT seeded here any more: seeding is
            // opt-in from the Rules page ("Load starter categories"),
            // so a company can run with no rules or its own list.

        }else{
            Utility::languagecreate();

        }
    }
}
