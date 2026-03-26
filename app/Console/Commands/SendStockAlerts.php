<?php

namespace App\Console\Commands;

use App\Mail\StockAlertsMail;
use App\Models\ProductService;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendStockAlerts extends Command
{
    protected $signature = 'stock:alerts {--company_id=} {--force : Send even if already alerted today}';
    protected $description = 'Send min/max stock alerts by email';

    public function handle(): int
    {
        $companyId = $this->option('company_id');

        $companies = User::query()
            ->where('type', 'company')
            ->when($companyId, fn ($q) => $q->where('id', (int) $companyId))
            ->get();

        foreach ($companies as $company) {
            $settings = Utility::settingsById($company->id);

            if (($settings['stock_alerts_enabled'] ?? 'off') !== 'on') {
                continue;
            }

            $recipientsRaw = (string) ($settings['stock_alerts_recipients'] ?? '');
            $recipients = array_values(array_filter(array_map('trim', preg_split('/[,\s;]+/', $recipientsRaw))));
            if (count($recipients) === 0) {
                continue;
            }

            $sendMin = ($settings['stock_alerts_send_min'] ?? 'on') === 'on';
            $sendMax = ($settings['stock_alerts_send_max'] ?? 'on') === 'on';

            $globalMin = (int) ($settings['pos_low_stock_threshold'] ?? 10);
            $globalMax = (int) ($settings['max_stock_threshold_global'] ?? 0);

            $minRows = [];
            $maxRows = [];

            $products = ProductService::where('created_by', $company->id)
                ->whereIn('type', ['product', 'kit'])
                ->get();

            $force = (bool) $this->option('force');
            $today = now()->toDateString();

            foreach ($products as $p) {
                $qty = (float) ($p->quantity ?? 0);

                if ($sendMin) {
                    $min = $p->low_stock_threshold !== null ? (int) $p->low_stock_threshold : $globalMin;
                    if ($min > 0 && $qty < $min) {
                        $last = $p->last_min_stock_alert_at ? $p->last_min_stock_alert_at->toDateString() : null;
                        if ($force || $last !== $today) {
                            $minRows[] = [
                                'name' => (string) $p->name,
                                'sku' => (string) ($p->sku ?: '—'),
                                'qty' => $qty,
                                'min' => $min,
                                'id' => (int) $p->id,
                            ];
                        }
                    }
                }

                if ($sendMax) {
                    $max = $p->max_stock_threshold !== null ? (int) $p->max_stock_threshold : $globalMax;
                    if ($max > 0 && $qty > $max) {
                        $last = $p->last_max_stock_alert_at ? $p->last_max_stock_alert_at->toDateString() : null;
                        if ($force || $last !== $today) {
                            $maxRows[] = [
                                'name' => (string) $p->name,
                                'sku' => (string) ($p->sku ?: '—'),
                                'qty' => $qty,
                                'max' => $max,
                                'id' => (int) $p->id,
                            ];
                        }
                    }
                }
            }

            if (count($minRows) === 0 && count($maxRows) === 0) {
                continue;
            }

            Mail::to($recipients)->send(new StockAlertsMail(['min' => $minRows, 'max' => $maxRows], $settings));

            // Mark alerted today for included products
            if (!$force) {
                if (count($minRows) > 0) {
                    ProductService::whereIn('id', array_column($minRows, 'id'))->update(['last_min_stock_alert_at' => now()]);
                }
                if (count($maxRows) > 0) {
                    ProductService::whereIn('id', array_column($maxRows, 'id'))->update(['last_max_stock_alert_at' => now()]);
                }
            }
        }

        return self::SUCCESS;
    }
}

