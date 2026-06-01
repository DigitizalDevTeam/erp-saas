<?php

namespace Modules\HotelManagement\Services;

use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalEntryPoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAccountingSetting;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioLine;
use Modules\HotelManagement\Entities\HotelFolioPayment;

class HotelFolioAccountingService
{
    public function __construct(private readonly JournalEntryPoster $poster)
    {
    }

    public function settingsFor(int $createdBy): ?HotelAccountingSetting
    {
        return HotelAccountingSetting::where('created_by', $createdBy)->first();
    }

    /**
     * Step 4 journal: DR guest AR, CR revenue buckets, CR VAT payable. Then backfill Step 5 for existing payments.
     */
    public function finalizeGuestInvoiceIfConfigured(HotelFolio $folio, string $postingDate): void
    {
        if (! Schema::hasTable('hotel_accounting_settings') || ! Schema::hasColumn('hotel_folios', 'finalize_journal_id')) {
            return;
        }
        $folio->refresh();
        if ($folio->finalize_journal_id) {
            return;
        }

        $createdBy = (int) $folio->created_by;
        $settings = $this->settingsFor($createdBy);
        if (! $settings || ! $settings->isCompleteForPosting()) {
            return;
        }

        $G = round((float) $folio->grand_total, 2);
        if ($G <= 0) {
            return;
        }

        $folio->loadMissing('lines');
        $channelNets = $this->rawNetByChannel($folio);
        $sumChannel = round(array_sum($channelNets), 2);

        $folioTax = round((float) $folio->tax_total, 2);
        if ($folioTax > 0.001) {
            $vat = round(min($folioTax, $G), 2);
        } else {
            $vat = round(max(0, $G - $sumChannel), 2);
        }
        $targetRevenue = round($G - $vat, 2);
        if ($targetRevenue < 0) {
            $targetRevenue = 0;
            $vat = $G;
        }

        if ($sumChannel < 0.01) {
            $channelNets = ['room' => 0.0, 'restaurant' => 0.0, 'laundry' => 0.0, 'misc' => $targetRevenue];
        } else {
            $scale = $targetRevenue / $sumChannel;
            foreach ($channelNets as $k => $v) {
                $channelNets[$k] = round((float) $v * $scale, 2);
            }
            $this->fixRoundingToTarget($channelNets, $targetRevenue);
        }

        $lines = [];
        $lines[] = [
            'account' => (int) $settings->chart_guest_ar_id,
            'debit' => $G,
            'credit' => 0,
            'description' => __('Guest AR') . ' ' . $folio->folio_no,
        ];
        foreach (['room', 'restaurant', 'laundry', 'misc'] as $ch) {
            $amt = round((float) ($channelNets[$ch] ?? 0), 2);
            if ($amt < 0.01) {
                continue;
            }
            $acct = $settings->revenueAccountId($ch);
            if (! $acct) {
                continue;
            }
            $lines[] = [
                'account' => $acct,
                'debit' => 0,
                'credit' => $amt,
                'description' => $folio->folio_no . ' ' . $ch,
            ];
        }
        if ($vat >= 0.01) {
            $lines[] = [
                'account' => (int) $settings->chart_vat_payable_id,
                'debit' => 0,
                'credit' => $vat,
                'description' => __('VAT payable') . ' ' . $folio->folio_no,
            ];
        }

        $this->assertBalanced($lines);

        DB::transaction(function () use ($folio, $lines, $postingDate, $createdBy) {
            $journal = $this->poster->post(
                $createdBy,
                $postingDate,
                $folio->folio_no,
                __('Hotel guest invoice') . ' ' . $folio->folio_no,
                $lines
            );
            $journal->status = JournalEntry::STATUS_APPROVED;
            $journal->save();
            $folio->finalize_journal_id = $journal->id;
            $folio->save();
        });

        $folio->refresh();
        $settingsFresh = $this->settingsFor($createdBy);
        foreach ($folio->payments()->whereNull('accounting_payment_journal_id')->orderBy('id')->get() as $payment) {
            $this->postFolioPaymentJournalIfConfigured($payment, $settingsFresh, $postingDate);
        }
    }

    /**
     * Step 5 journal for one folio payment (after finalize exists).
     */
    public function postFolioPaymentJournalIfConfigured(HotelFolioPayment $payment, ?HotelAccountingSetting $settings = null, ?string $postingDate = null): void
    {
        if (! Schema::hasColumn('hotel_folio_payments', 'accounting_payment_journal_id')) {
            return;
        }
        $payment->refresh();
        if ($payment->accounting_payment_journal_id) {
            return;
        }
        $folio = $payment->folio;
        if (! $folio || ! $folio->finalize_journal_id) {
            return;
        }
        $createdBy = (int) $folio->created_by;
        $settings = $settings ?? $this->settingsFor($createdBy);
        if (! $settings || ! $settings->chart_guest_ar_id) {
            return;
        }
        $bankAcctId = $settings->bankAccountIdForFolioPaymentMethod((string) $payment->method);
        if (! $bankAcctId) {
            return;
        }
        $bank = BankAccount::where('id', $bankAcctId)->where('created_by', $createdBy)->first();
        if (! $bank || ! $bank->chart_account_id) {
            return;
        }
        $amt = round((float) $payment->amount, 2);
        if ($amt <= 0) {
            return;
        }
        $date = $postingDate ?? ($payment->created_at ? $payment->created_at->format('Y-m-d') : date('Y-m-d'));

        $lines = [
            [
                'account' => (int) $bank->chart_account_id,
                'debit' => $amt,
                'credit' => 0,
                'description' => __('Hotel payment') . ' ' . $folio->folio_no,
            ],
            [
                'account' => (int) $settings->chart_guest_ar_id,
                'debit' => 0,
                'credit' => $amt,
                'description' => __('Hotel payment clear AR') . ' ' . $folio->folio_no,
            ],
        ];

        DB::transaction(function () use ($payment, $lines, $date, $createdBy, $folio) {
            $journal = $this->poster->post(
                $createdBy,
                $date,
                $folio->folio_no . '-PAY-' . $payment->id,
                __('Hotel folio payment') . ' ' . $folio->folio_no,
                $lines
            );
            $journal->status = JournalEntry::STATUS_APPROVED;
            $journal->save();
            $payment->accounting_payment_journal_id = $journal->id;
            $payment->save();
        });
    }

    /**
     * @param  array<string, float>  $channelNets
     */
    private function fixRoundingToTarget(array &$channelNets, float $targetRevenue): void
    {
        $sum = round(array_sum($channelNets), 2);
        $diff = round($targetRevenue - $sum, 2);
        if (abs($diff) >= 0.01) {
            $channelNets['misc'] = round((float) ($channelNets['misc'] ?? 0) + $diff, 2);
        }
    }

    /**
     * @return array<string, float>
     */
    private function rawNetByChannel(HotelFolio $folio): array
    {
        $channels = ['room' => 0.0, 'restaurant' => 0.0, 'laundry' => 0.0, 'misc' => 0.0];
        foreach ($folio->lines as $line) {
            $ch = $this->channelForLine($line);
            if (! isset($channels[$ch])) {
                $ch = 'misc';
            }
            $net = max(0, (float) $line->line_total - (float) $line->tax_amount);
            $channels[$ch] += $net;
        }

        return $channels;
    }

    private function channelForLine(HotelFolioLine $line): string
    {
        $rc = $line->revenue_channel ? strtolower((string) $line->revenue_channel) : null;
        if ($rc && in_array($rc, ['room', 'restaurant', 'laundry', 'misc'], true)) {
            return $rc;
        }

        return match ((string) $line->line_type) {
            'room_charge' => 'room',
            'pos_charge' => 'restaurant',
            'laundry_charge', 'misc_charge' => 'laundry',
            'minibar_charge' => 'misc',
            default => 'misc',
        };
    }

    private function assertBalanced(array $lines): void
    {
        $d = 0.0;
        $c = 0.0;
        foreach ($lines as $line) {
            $d += (float) ($line['debit'] ?? 0);
            $c += (float) ($line['credit'] ?? 0);
        }
        if (round($d, 2) !== round($c, 2)) {
            throw new \InvalidArgumentException(__('Hotel guest invoice journal not balanced.'));
        }
    }
}
