<?php

namespace Database\Seeders;

use App\Models\ManagementCostCentre;
use App\Models\ManagementRule;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed the Management Rules catalogue (the file's Rules tab) and the
 * two example cost centres (101 Restaurante, 102 Group) for every
 * company that has none yet. Each company keeps its OWN list and can
 * change it afterwards — this only fills the starting point.
 *
 * Companies are users with type "company" in this app. Existing
 * companies are covered lazily too (ensureSeeded() runs on first use),
 * so this seeder is only the fresh-install path.
 */
class ManagementRulesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (User::where('type', 'company')->pluck('id') as $creatorId) {
            ManagementRule::ensureSeeded((int) $creatorId);
            ManagementCostCentre::ensureSeeded((int) $creatorId);
        }
    }
}
