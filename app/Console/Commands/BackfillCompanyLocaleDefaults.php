<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Utility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCompanyLocaleDefaults extends Command
{
    protected $signature = 'companies:backfill-locale-defaults
                            {--dry-run : Show what would change without writing}
                            {--company= : Limit to a single company id}';

    protected $description = 'Backfill missing or legacy English/US locale settings (currency, language, document prefixes) for companies without overwriting custom values';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyOption = $this->option('company');

        $query = User::query()->where('type', 'company');
        if ($companyOption !== null && $companyOption !== '') {
            $query->where('id', (int) $companyOption);
        }

        $companies = $query->orderBy('id')->get(['id', 'name', 'lang']);
        if ($companies->isEmpty()) {
            $this->warn('No companies found.');
            return self::SUCCESS;
        }

        $defaults = Utility::companyLocaleDefaultSettings();
        $legacy = Utility::companyLocaleLegacyDefaultValues();
        $now = date('Y-m-d H:i:s');

        $settingsUpdated = 0;
        $langUpdated = 0;
        $companiesTouched = 0;

        foreach ($companies as $company) {
            $companyId = (int) $company->id;
            $existing = DB::table('settings')
                ->where('created_by', $companyId)
                ->whereIn('name', array_keys($defaults))
                ->pluck('value', 'name')
                ->toArray();

            $changes = [];
            foreach ($defaults as $name => $value) {
                $current = $existing[$name] ?? null;
                $shouldWrite = false;

                if ($current === null || $current === '') {
                    $shouldWrite = true;
                } elseif (isset($legacy[$name]) && in_array($current, $legacy[$name], true)) {
                    $shouldWrite = true;
                }

                if ($shouldWrite) {
                    $changes[$name] = [
                        'from' => $current,
                        'to' => $value,
                    ];
                }
            }

            $shouldUpdateLang = in_array($company->lang, [null, '', 'en'], true)
                && (!array_key_exists('default_language', $existing)
                    || in_array($existing['default_language'] ?? null, [null, '', 'en'], true));

            if (empty($changes) && !$shouldUpdateLang) {
                continue;
            }

            $companiesTouched++;
            $this->line("Company #{$companyId} ({$company->name}):");

            foreach ($changes as $name => $change) {
                $from = $change['from'] === null ? '(missing)' : $change['from'];
                $this->line("  - {$name}: {$from} => {$change['to']}");
                if (!$dryRun) {
                    DB::insert(
                        'insert into settings (`value`, `name`, `created_by`, `created_at`, `updated_at`) values (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)',
                        [$change['to'], $name, $companyId, $now, $now]
                    );
                }
                $settingsUpdated++;
            }

            if ($shouldUpdateLang) {
                $this->line("  - users.lang: " . ($company->lang ?: '(empty)') . " => pt");
                if (!$dryRun) {
                    User::where('id', $companyId)->update(['lang' => 'pt']);
                }
                $langUpdated++;
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Companies touched: {$companiesTouched}");
        $this->info("{$prefix}Settings rows written: {$settingsUpdated}");
        $this->info("{$prefix}Company lang updates: {$langUpdated}");

        return self::SUCCESS;
    }
}
