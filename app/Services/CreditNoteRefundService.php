<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\CreditNote;
use App\Models\CreditNoteRefund;
use App\Models\TreasuryCashSession;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOperation;
use App\Models\TreasuryPettyCashFund;
use App\Models\User;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit Notes Phase 2 — Treasury Reembolso (client answers 2026-11-19).
 *
 * A refund pays a credit note's FULL amount back to the customer,
 * once, from any company bank account (transfer) or from petty cash /
 * a cash register (cash, optionally recorded against an open till
 * session). Rules enforced here (service layer, not only the UI):
 * - the note must be a credit note with disposition 'refund' and
 *   status approved (when the approval switch is on, a pending note
 *   cannot be refunded — approval first, like journals);
 * - one refund per note, full amount only — "cannot exceed the note
 *   amount, cannot be refunded twice";
 * - the source account must be active, belong to the company, and use
 *   the base currency (a rate is never guessed — D2);
 * - a transfer needs its transfer receipt attached;
 * - the account's negative-balance policy is respected (block, or
 *   needs-approval, both refuse — refunds have no approval workflow);
 * - the fiscal year must be open (CA17).
 *
 * Posting mirrors TreasuryReconciliationService::confirmMissingMovement
 * and the petty-cash expense execution: a confirmed TYPE_PAYMENT
 * operation (source credit_note) + an outflow TreasuryEntry + a
 * journal Dr accounts receivable 411 / Cr source-account chart
 * (the mirror of the receipt confirm, which Cr 411). The Treasury
 * posted balance is the source of truth; the poster's legacy
 * BankAccount.opening_balance adjustment follows the known inverted
 * convention (Riz 2026-11-08: leave old accounting untouched).
 */
class CreditNoteRefundService
{
    protected TreasuryAccountService $accounts;
    protected JournalEntryPoster $poster;
    protected ChartAccountResolver $resolver;

    public function __construct(
        ?TreasuryAccountService $accounts = null,
        ?JournalEntryPoster $poster = null,
        ?ChartAccountResolver $resolver = null
    ) {
        $this->accounts = $accounts ?? new TreasuryAccountService();
        $this->poster = $poster ?? new JournalEntryPoster();
        $this->resolver = $resolver ?? new ChartAccountResolver();
    }

    /**
     * Pay a credit note back in full.
     *
     * @param array{
     *   method: string,
     *   financial_account_id?: int,
     *   petty_cash_fund_id?: int,
     *   cash_session_id?: int,
     *   transfer_receipt?: string|null,
     *   signed_receipt?: string|null,
     * } $data
     */
    public function refundNote(int $creatorId, int $noteId, int $actorId, array $data): CreditNoteRefund
    {
        return DB::transaction(function () use ($creatorId, $noteId, $actorId, $data) {
            $note = CreditNote::lockForUpdate()->findOrFail($noteId);
            if ((int) $note->created_by !== $creatorId) {
                throw new TreasuryException('This credit note does not belong to this company.');
            }
            if ((int) $note->is_debit === 1) {
                throw new TreasuryException('A debit note cannot be refunded.');
            }
            if ((string) ($note->disposition ?? '') !== 'refund') {
                throw new TreasuryException('Only a note kept as a refund can be paid back. Keep as customer credit notes are not refunded.');
            }
            if ((int) ($note->status ?? CreditNote::STATUS_APPROVED) !== CreditNote::STATUS_APPROVED) {
                throw new TreasuryException('Only an approved credit note can be refunded.');
            }
            if (CreditNoteRefund::where('credit_note_id', $note->id)->exists()) {
                throw new TreasuryException('This credit note has already been refunded. One refund per note.');
            }

            $amount = $this->decimalString($note->amount, 'Credit note amount');
            if (bccomp($amount, '0', 4) <= 0) {
                throw new TreasuryException('The credit note amount must be greater than zero.');
            }

            $method = (string) ($data['method'] ?? '');
            if (! in_array($method, CreditNoteRefund::METHODS, true)) {
                throw new TreasuryException('Unknown refund method.');
            }

            $transferReceipt = $this->nullableString($data['transfer_receipt'] ?? null, 255);
            $signedReceipt = $this->nullableString($data['signed_receipt'] ?? null, 255);
            if ($method === CreditNoteRefund::METHOD_TRANSFER && $transferReceipt === null) {
                throw new TreasuryException('A bank transfer refund needs its transfer receipt attached.');
            }

            [$account, $fundId, $sessionId] = $this->resolveSource($creatorId, $method, $data);

            $baseCurrency = TreasurySettings::baseCurrency($creatorId);
            if (strtoupper((string) $account->currency) !== $baseCurrency) {
                throw new TreasuryException('The refund account must use the base currency (' . $baseCurrency . ') — never guess a rate (D2).');
            }

            // Negative-balance policy: block, or needs-approval, both
            // refuse — refunds have no approval workflow in Phase 2.
            $projected = bcsub($this->accounts->postedBalance($account), $amount, 4);
            $balanceCheck = $this->accounts->checkNegativeBalance($account, $projected);
            if ($balanceCheck === 'blocked') {
                throw new TreasuryException('This refund would take ' . $account->code . ' negative, and its policy blocks that.');
            }
            if ($balanceCheck === 'needs_approval') {
                throw new TreasuryException('This refund would take ' . $account->code . ' negative, and its policy needs an approval that refunds do not have yet.');
            }

            $postingDate = date('Y-m-d');
            if (! $this->isPeriodOpen($creatorId, Carbon::parse($postingDate))) {
                throw new TreasuryException('Fiscal year ' . Carbon::parse($postingDate)->year . ' is closed. Treasury postings into a closed period are blocked (CA17).');
            }

            $bankChartId = $this->chartAccountId($account);
            $receivableChartId = $this->resolveReceivableAccount($creatorId);
            $amount2 = $this->round2($amount);
            if (bccomp($amount2, '0', 2) <= 0) {
                throw new TreasuryException('The refund amount is too small to post (it rounds to zero).');
            }

            $reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_PAYMENT, $postingDate);
            $label = 'Credit note refund ' . $reference . ' — note #' . $note->uid;

            $journal = $this->poster->post($creatorId, $postingDate, $reference, $label, [
                [
                    'account' => $receivableChartId,
                    'debit' => (float) $amount2,
                    'description' => $label . ' — accounts receivable',
                ],
                [
                    'account' => $bankChartId,
                    'credit' => (float) $amount2,
                    'description' => $label . ' — ' . $account->code,
                ],
            ]);

            $operation = TreasuryOperation::create([
                'reference' => $reference,
                'type' => TreasuryOperation::TYPE_PAYMENT,
                'company_id' => $creatorId,
                'branch_id' => $account->branch_id ? (int) $account->branch_id : null,
                'financial_account_id' => $account->id,
                'counterparty_type' => 'customer',
                'counterparty_id' => (int) $note->customer,
                'currency' => $baseCurrency,
                'amount_original' => $amount,
                'exchange_rate' => '1.0000000000',
                'rate_source' => null,
                'rate_date' => null,
                'amount_base' => $amount,
                'posting_date' => $postingDate,
                'effective_date' => $postingDate,
                'value_date' => $postingDate,
                'state' => TreasuryOperation::STATE_CONFIRMED,
                'source_type' => 'credit_note',
                'source_id' => $note->id,
                'created_by' => $creatorId,
            ]);

            $this->recordEntry($operation, $account, TreasuryEntry::DIRECTION_OUTFLOW, $amount, $amount, '1.0000000000', (int) $journal->id, $creatorId);

            return CreditNoteRefund::create([
                'credit_note_id' => $note->id,
                'invoice_id' => (int) $note->invoice,
                'customer_id' => (int) $note->customer,
                'treasury_operation_id' => $operation->id,
                'journal_id' => $journal->id,
                'method' => $method,
                'financial_account_id' => $account->id,
                'petty_cash_fund_id' => $fundId,
                'cash_session_id' => $sessionId,
                'amount' => $amount2,
                'currency' => $baseCurrency,
                'transfer_receipt' => $transferReceipt,
                'signed_receipt' => $signedReceipt,
                'reference' => $reference,
                'created_by' => $creatorId,
            ]);
        });
    }

    /** Scope a refund to the company (controller guard). */
    public function refundForCompany(int $creatorId, int $refundId): CreditNoteRefund
    {
        $refund = CreditNoteRefund::findOrFail($refundId);
        if ((int) $refund->created_by !== $creatorId) {
            throw new TreasuryException('This refund does not belong to this company.');
        }

        return $refund;
    }

    /**
     * Resolve the source account for the refund.
     *
     * Transfer (Q4: any bank): any active bank-type financial account.
     * Cash: a cash-register account, or an active petty-cash fund
     * (the fund's own financial account is used). An open till session
     * pins the source to its register account (Q3: recorded at the till).
     *
     * @return array{0: TreasuryFinancialAccount, 1: int|null, 2: int|null}
     */
    protected function resolveSource(int $creatorId, string $method, array $data): array
    {
        $fundId = null;
        $sessionId = null;

        if ($method === CreditNoteRefund::METHOD_TRANSFER) {
            $account = $this->accounts->accountForCompany($creatorId, (int) ($data['financial_account_id'] ?? 0));
            if (! $account->isActive()) {
                throw new TreasuryException('Refunds cannot use an inactive account.');
            }
            if ($account->type !== TreasuryFinancialAccount::TYPE_BANK) {
                throw new TreasuryException('A bank transfer refund needs a bank account.');
            }

            return [$account, null, null];
        }

        // Cash.
        if (! empty($data['petty_cash_fund_id'])) {
            $fund = TreasuryPettyCashFund::lockForUpdate()->findOrFail((int) $data['petty_cash_fund_id']);
            if ((int) $fund->created_by !== $creatorId) {
                throw new TreasuryException('This petty cash fund does not belong to this company.');
            }
            if (! $fund->isActive()) {
                throw new TreasuryException('The petty cash fund is inactive.');
            }
            $fundId = (int) $fund->id;
            $account = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $fund->financial_account_id);
            if (! $account->isActive()) {
                throw new TreasuryException('The petty cash fund account is inactive.');
            }

            return [$account, $fundId, null];
        }

        $account = $this->accounts->accountForCompany($creatorId, (int) ($data['financial_account_id'] ?? 0));
        if (! $account->isActive()) {
            throw new TreasuryException('Refunds cannot use an inactive account.');
        }
        if ($account->type !== TreasuryFinancialAccount::TYPE_CASH_REGISTER) {
            throw new TreasuryException('A cash refund needs a cash account or a petty cash fund.');
        }

        if (! empty($data['cash_session_id'])) {
            $session = TreasuryCashSession::lockForUpdate()->findOrFail((int) $data['cash_session_id']);
            if ((int) $session->created_by !== $creatorId) {
                throw new TreasuryException('This cash session does not belong to this company.');
            }
            if ($session->state !== TreasuryCashSession::STATE_OPEN) {
                throw new TreasuryException('Refunds at the till need an open cash session.');
            }
            if ((int) $session->register_id !== (int) $account->id) {
                throw new TreasuryException('The cash session belongs to a different till than the chosen cash account.');
            }
            $sessionId = (int) $session->id;
        }

        return [$account, null, $sessionId];
    }

    // ------------------------------------------------------------------
    // Posting helpers (Treasury Phase 1/2 pattern)
    // ------------------------------------------------------------------

    protected function recordEntry(TreasuryOperation $operation, TreasuryFinancialAccount $account, string $direction, string $amountOriginal, string $amountBase, string $rate, int $journalId, int $creatorId): TreasuryEntry
    {
        return TreasuryEntry::create([
            'operation_id' => $operation->id,
            'financial_account_id' => $account->id,
            'direction' => $direction,
            'amount_original' => $amountOriginal,
            'amount_base' => $amountBase,
            'exchange_rate' => $rate,
            'confirmed_at' => now(),
            'journal_id' => $journalId,
            'created_by' => $creatorId,
        ]);
    }

    protected function chartAccountId(?TreasuryFinancialAccount $account): int
    {
        $id = $account && $account->bankAccount ? (int) $account->bankAccount->chart_account_id : 0;
        if ($id <= 0) {
            throw new TreasuryException('The financial account has no chart account. Set one on the account before confirming.');
        }

        return $id;
    }

    protected function resolveReceivableAccount(int $creatorId): int
    {
        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, '411');
        if ($id === null) {
            throw new TreasuryException('No chart account found for accounts receivable (411). Set one in Treasury settings (code or prefix).');
        }

        return $id;
    }

    protected function isPeriodOpen(int $creatorId, Carbon $date): bool
    {
        if (! Schema::hasTable('journal_entries')) {
            return true;
        }

        return ! DB::table('journal_entries')
            ->where('created_by', $creatorId)
            ->where('reference', 'like', 'FY-' . $date->year . '-CLOSE%')
            ->exists();
    }

    /** Next reference for the company + type + year, serialised (Phase 1/2 pattern). */
    protected function nextReference(int $creatorId, string $type, string $date): string
    {
        $year = Carbon::parse($date)->year;
        $code = TreasuryOperation::TYPE_CODES[$type] ?? 'OPR';
        $prefix = $this->companyReferencePrefix($creatorId);

        $head = $prefix . '-' . $code . '-' . $year . '-';

        $max = 0;
        $existing = TreasuryOperation::where('created_by', $creatorId)
            ->where('type', $type)
            ->where('reference', 'like', $head . '%')
            ->lockForUpdate()
            ->pluck('reference');

        foreach ($existing as $reference) {
            $suffix = substr((string) $reference, strlen($head));
            if (ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }

        return $head . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Company code for references: configured prefix, else the company name. */
    protected function companyReferencePrefix(int $creatorId): string
    {
        $configured = strtoupper(trim((string) (TreasurySettings::get($creatorId, TreasurySettings::KEY_REFERENCE_PREFIX) ?? '')));

        if ($configured !== '') {
            return preg_replace('/[^A-Z0-9]/', '', $configured) ?: 'CO';
        }

        $user = User::find($creatorId);
        $letters = $user ? preg_replace('/[^A-Za-z]/', '', (string) $user->name) : '';

        return $letters !== '' ? strtoupper(substr($letters, 0, 4)) : 'CO';
    }

    protected function decimalString($value, string $label): string
    {
        if ($value === null || trim((string) $value) === '' || ! is_numeric($value)) {
            throw new TreasuryException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function round2(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    protected function nullableString($value, int $max): ?string
    {
        $value = $value !== null ? trim((string) $value) : '';
        if ($value === '') {
            return null;
        }

        return substr($value, 0, $max);
    }
}
