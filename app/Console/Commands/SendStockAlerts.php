<?php

namespace App\Console\Commands;

use App\Mail\StockAlertsMail;
use App\Models\ProductService;
use App\Models\User;
use App\Models\Utility;
use App\Services\OperationalMessagingService;
use Illuminate\Console\Command;
use App\Support\LoggedMail;

class SendStockAlerts extends Command
{
    protected $signature = 'stock:alerts {--company_id=} {--force : Send even if already alerted today}';
    protected $description = 'Send min/max stock alerts (email / SMS / WhatsApp via Twilio when configured)';

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

            $phonesRaw = (string) ($settings['stock_alerts_sms_recipients'] ?? '');
            $phones = array_values(array_filter(array_map('trim', preg_split('/[,\s;]+/', $phonesRaw))));

            $sendEmail = ($settings['stock_alerts_send_email'] ?? 'on') === 'on';
            $sendSms = ($settings['stock_alerts_send_sms'] ?? 'off') === 'on';
            $sendWa = ($settings['stock_alerts_send_whatsapp'] ?? 'off') === 'on';

            $canEmail = $sendEmail && count($recipients) > 0;
            $canMobile = ($sendSms || $sendWa) && count($phones) > 0;
            if (!$canEmail && !$canMobile) {
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

            $payload = ['min' => $minRows, 'max' => $maxRows];

            if ($sendEmail && count($recipients) > 0) {
                LoggedMail::send($recipients, new StockAlertsMail($payload, $settings), [
                    'source' => 'stock_alerts',
                    'subject' => __('Stock Alerts'),
                    'created_by' => $company->id,
                ]);
            }

            $companyName = (string) ($settings['company_name'] ?? '');
            $lines = [trim($companyName) !== '' ? $companyName : 'ERP', __('Stock alert')];
            if (count($minRows) > 0) {
                $lines[] = __('Below minimum') . ':';
                foreach ($minRows as $r) {
                    $lines[] = $r['name'] . ' (' . $r['sku'] . ') ' . __('Qty') . ' ' . $r['qty'] . ' / ' . __('Min') . ' ' . $r['min'];
                }
            }
            if (count($maxRows) > 0) {
                $lines[] = __('Above maximum') . ':';
                foreach ($maxRows as $r) {
                    $lines[] = $r['name'] . ' (' . $r['sku'] . ') ' . __('Qty') . ' ' . $r['qty'] . ' / ' . __('Max') . ' ' . $r['max'];
                }
            }
            $plain = implode("\n", $lines);

            foreach ($phones as $phone) {
                if ($sendSms) {
                    OperationalMessagingService::send((int) $company->id, $phone, $plain, 'sms');
                }
                if ($sendWa) {
                    OperationalMessagingService::send((int) $company->id, $phone, $plain, 'whatsapp');
                }
            }

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

