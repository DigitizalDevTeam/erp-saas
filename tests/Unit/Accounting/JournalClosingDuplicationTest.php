<?php

namespace Tests\Unit\Accounting;

use App\Models\JournalEntry;
use App\Models\Utility;
use App\Services\Accounting\JournalEntryPoster;
use App\Services\Accounting\JournalRoundingRepairService;
use App\Services\Accounting\LedgerAccountBalanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: year-end closing journals used to be counted twice in the
 * Trial Balance. JournalEntryPoster wrote the ledger lines immediately but
 * left the journal "pending"; a later manual Approve inserted a second set
 * of transaction_lines, so Classes 6/7 kept their original balances instead
 * of zeroing after closing.
 */
class JournalClosingDuplicationTest extends TestCase
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

    private function postClosingJournal(): JournalEntry
    {
        return app(JournalEntryPoster::class)->post(1, '2025-12-31', 'FY-2025-CLOSE-OP-EXP', 'close', [
            ['account' => 10, 'debit' => 0, 'credit' => 100],
            ['account' => 20, 'debit' => 100, 'credit' => 0],
        ], 15);
    }

    private function expenseBalances(): array
    {
        $balances = app(LedgerAccountBalanceService::class)
            ->balancesByAccountId(1, '2025-01-01', '2025-12-31', 0, 15, 2025);

        return $balances[10] ?? ['totalDebit' => 0.0, 'totalCredit' => 0.0];
    }

    public function test_poster_journal_is_already_approved_and_written_once(): void
    {
        $this->seedOperationalExpense();
        $journal = $this->postClosingJournal();

        $this->assertSame(JournalEntry::STATUS_APPROVED, (int) $journal->status);

        $itemCount = DB::table('journal_items')->where('journal', $journal->id)->count();
        $lineCount = DB::table('transaction_lines')
            ->where('reference', 'Journal')->where('reference_id', $journal->id)->count();
        $this->assertSame(2, $itemCount);
        $this->assertSame($itemCount, $lineCount, 'One ledger line per journal item');

        // After closing, the expense account nets to zero.
        $this->assertSame(['totalDebit' => 100.0, 'totalCredit' => 100.0], $this->expenseBalances());
    }

    public function test_approve_no_longer_inserts_a_second_set_of_lines(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/JournalEntryController.php'));

        $this->assertStringNotContainsString("Utility::addTransactionLines(\$data, 'create');", $source);
        $this->assertStringContainsString("Utility::addTransactionLines(\$data, 'edit');", $source);
    }

    public function test_edit_mode_line_write_is_idempotent(): void
    {
        Auth::loginUsingId(1);

        $data = [
            'account_id' => 10,
            'transaction_type' => 'Credit',
            'transaction_amount' => 100,
            'reference' => 'Journal',
            'reference_id' => 7,
            'reference_sub_id' => 9,
            'date' => '2025-12-31',
        ];

        Utility::addTransactionLines($data, 'edit');
        Utility::addTransactionLines($data, 'edit');

        $this->assertSame(1, DB::table('transaction_lines')->count());
    }

    public function test_repair_removes_duplicate_generations_so_closing_zeroes(): void
    {
        $this->seedOperationalExpense();
        $journal = $this->postClosingJournal();

        // Simulate lines written twice by the old approve flow.
        $lines = DB::table('transaction_lines')
            ->where('reference', 'Journal')->where('reference_id', $journal->id)->get();
        foreach ($lines as $line) {
            DB::table('transaction_lines')->insert([
                'account_id' => $line->account_id, 'debit' => $line->debit, 'credit' => $line->credit,
                'date' => $line->date, 'reference' => 'Journal', 'reference_id' => $journal->id,
                'reference_sub_id' => $line->reference_sub_id, 'created_by' => 1,
            ]);
        }

        $duplicated = $this->expenseBalances();
        $this->assertSame(200.0, $duplicated['totalCredit']);

        $result = app(JournalRoundingRepairService::class)->repair(1);
        $this->assertSame(2, $result['deduped']);

        $this->assertSame(['totalDebit' => 100.0, 'totalCredit' => 100.0], $this->expenseBalances());
    }
}
