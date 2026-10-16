<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\FiscalYearClosingService;
use App\Services\Accounting\JournalEntryPoster;
use App\Services\Accounting\JournalRoundingRepairService;
use App\Services\Accounting\LedgerAccountBalanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: the year-end closing ran twice (double-click / retry), so two
 * whole journals shared the same FY-*-CLOSE* reference. Each journal had a
 * single clean set of ledger lines, which made the per-line dedupe blind —
 * "Duplicate lines removed: 0" — while the Trial Balance showed every
 * closing side at exactly 2x.
 *
 * The fix: FiscalYearClosingService::run() posts inside a transaction with a
 * locking existence check plus a per-reference skip, and the repair command
 * removes whole duplicate closing journals whose items are identical.
 */
class ClosingDoubleRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->integer('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('chart_of_account_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });
        Schema::create('chart_of_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->nullable();
            $t->string('name')->nullable();
            $t->integer('type')->nullable();
            $t->integer('parent')->nullable();
            $t->integer('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->integer('journal_id')->nullable();
            $t->date('date');
            $t->string('reference')->nullable();
            $t->text('description')->nullable();
            $t->unsignedTinyInteger('status')->default(0);
            $t->integer('created_by');
            $t->integer('accounting_period')->nullable();
            $t->timestamps();
        });
        Schema::create('journal_items', function (Blueprint $t) {
            $t->id();
            $t->integer('journal');
            $t->integer('account');
            $t->decimal('debit', 15, 2)->default(0);
            $t->decimal('credit', 15, 2)->default(0);
            $t->text('description')->nullable();
            $t->timestamps();
        });
        Schema::create('transaction_lines', function (Blueprint $t) {
            $t->id();
            $t->integer('account_id');
            $t->decimal('debit', 15, 2)->default(0);
            $t->decimal('credit', 15, 2)->default(0);
            $t->date('date');
            $t->string('reference')->nullable();
            $t->integer('reference_id')->nullable();
            $t->integer('reference_sub_id')->nullable();
            $t->integer('created_by');
            $t->timestamps();
        });
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->integer('chart_account_id')->nullable();
            $t->decimal('opening_balance', 15, 2)->default(0);
            $t->timestamps();
        });

        DB::table('users')->insert(['id' => 1, 'type' => 'super admin']);
        DB::table('chart_of_accounts')->insert([
            ['id' => 10, 'code' => '621', 'name' => 'Personnel Expenses', 'created_by' => 1],
            ['id' => 20, 'code' => '81', 'name' => 'Operating Results', 'created_by' => 1],
            ['id' => 21, 'code' => '82', 'name' => 'Financial Results', 'created_by' => 1],
            ['id' => 22, 'code' => '83', 'name' => 'Current Results', 'created_by' => 1],
            ['id' => 23, 'code' => '88', 'name' => 'Net Profits', 'created_by' => 1],
        ]);
    }

    private function seedOperationalExpense(float $amount = 100.0): void
    {
        DB::table('journal_entries')->insert([
            'id' => 1, 'journal_id' => 1, 'date' => '2025-03-01',
            'reference' => 'OP', 'created_by' => 1, 'accounting_period' => null,
        ]);
        DB::table('journal_items')->insert([
            'id' => 1, 'journal' => 1, 'account' => 10, 'debit' => $amount, 'credit' => 0,
        ]);
        DB::table('transaction_lines')->insert([
            'account_id' => 10, 'debit' => $amount, 'credit' => 0, 'date' => '2025-03-01',
            'reference' => 'Journal', 'reference_id' => 1, 'reference_sub_id' => 1, 'created_by' => 1,
        ]);
    }

    private function postClosingJournal(): int
    {
        $journal = app(JournalEntryPoster::class)->post(1, '2025-12-31', 'FY-2025-CLOSE-OP-EXP', 'close', [
            ['account' => 10, 'debit' => 0, 'credit' => 100],
            ['account' => 20, 'debit' => 100, 'credit' => 0],
        ], 15);

        return (int) $journal->id;
    }

    private function expenseBalances(): array
    {
        $balances = app(LedgerAccountBalanceService::class)
            ->balancesByAccountId(1, '2025-01-01', '2025-12-31', 0, 15, 2025);

        return $balances[10] ?? ['totalDebit' => 0.0, 'totalCredit' => 0.0];
    }

    private function closingJournalCount(): int
    {
        return (int) DB::table('journal_entries')
            ->where('created_by', 1)
            ->where('reference', 'like', 'FY-%-CLOSE%')
            ->count();
    }

    public function test_repair_removes_second_of_two_identical_closing_journals(): void
    {
        $this->seedOperationalExpense();
        $this->postClosingJournal();
        // Simulate the double run: a second journal, same reference, same lines.
        $this->postClosingJournal();

        $this->assertSame(2, $this->closingJournalCount());
        $doubled = $this->expenseBalances();
        $this->assertSame(200.0, $doubled['totalCredit'], 'Closing side is doubled before repair');

        $result = app(JournalRoundingRepairService::class)->repair(1);

        $this->assertSame(1, $result['duplicateJournalsRemoved']);
        $closingWarnings = array_values(array_filter(
            $result['warnings'],
            static fn ($w) => str_contains($w, 'FY-2025-CLOSE-OP-EXP')
        ));
        $this->assertSame([], $closingWarnings, 'No warnings about the duplicate closing journals');
        $this->assertSame(1, $this->closingJournalCount());

        // The surviving journal keeps its items and its single set of lines.
        $keptId = (int) DB::table('journal_entries')
            ->where('created_by', 1)->where('reference', 'like', 'FY-%-CLOSE%')->value('id');
        $this->assertSame(2, (int) DB::table('journal_items')->where('journal', $keptId)->count());
        $this->assertSame(2, (int) DB::table('transaction_lines')
            ->where('reference', 'Journal')->where('reference_id', $keptId)->count());

        $this->assertSame(['totalDebit' => 100.0, 'totalCredit' => 100.0], $this->expenseBalances());

        // Idempotent: a second repair changes nothing.
        $again = app(JournalRoundingRepairService::class)->repair(1);
        $this->assertSame(0, $again['duplicateJournalsRemoved']);
        $this->assertSame(0, $again['deduped']);
    }

    public function test_repair_skips_same_reference_journals_with_different_lines(): void
    {
        $this->seedOperationalExpense();
        $first = $this->postClosingJournal();

        // Same reference but genuinely different content: must be kept.
        $second = app(JournalEntryPoster::class)->post(1, '2025-12-31', 'FY-2025-CLOSE-OP-EXP', 'close', [
            ['account' => 10, 'debit' => 0, 'credit' => 50],
            ['account' => 20, 'debit' => 50, 'credit' => 0],
        ], 15);

        $this->assertNotSame($first, (int) $second->id);

        $result = app(JournalRoundingRepairService::class)->repair(1);

        $this->assertSame(0, $result['duplicateJournalsRemoved']);
        $this->assertSame(2, $this->closingJournalCount(), 'Non-identical journals are left untouched');
        $this->assertNotSame([], $result['warnings']);
    }

    public function test_second_closing_run_refuses_when_first_completed(): void
    {
        $this->seedOperationalExpense();

        $service = app(FiscalYearClosingService::class);

        $first = $service->run(1, 2025, '2025-12-31', 15);
        $this->assertTrue($first['success']);
        $this->assertNotSame([], $first['journals']);
        $journalsAfterFirst = $this->closingJournalCount();
        $this->assertGreaterThan(0, $journalsAfterFirst);

        $second = $service->run(1, 2025, '2025-12-31', 15);
        $this->assertFalse($second['success']);
        $this->assertSame([], $second['journals']);

        $this->assertSame(
            $journalsAfterFirst,
            $this->closingJournalCount(),
            'The refused second run must not post any additional closing journal'
        );
    }

    public function test_allow_duplicate_replaces_previous_closing_cleanly(): void
    {
        $this->seedOperationalExpense();

        $service = app(FiscalYearClosingService::class);

        $first = $service->run(1, 2025, '2025-12-31', 15);
        $this->assertTrue($first['success']);
        $countAfterFirst = $this->closingJournalCount();

        // "Allow duplicate" deletes the previous set inside the same
        // transaction, then re-posts — never two live sets at once.
        $second = $service->run(1, 2025, '2025-12-31', 15, false);
        $this->assertTrue($second['success']);

        $this->assertSame($countAfterFirst, $this->closingJournalCount());
        $this->assertSame(['totalDebit' => 100.0, 'totalCredit' => 100.0], $this->expenseBalances());
    }
}
