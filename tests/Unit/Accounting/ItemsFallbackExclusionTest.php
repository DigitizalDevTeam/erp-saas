<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\LedgerAccountBalanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: the Trial Balance doubled every journal that had both
 * journal_items and transaction_lines. The items fallback is only for
 * journals that never got ledger lines; when the exclusion misfires, the
 * items are added on top of the lines and the report shows 2x.
 */
class ItemsFallbackExclusionTest extends TestCase
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
            ['id' => 10, 'code' => '621', 'name' => 'Personnel', 'created_by' => 1],
            ['id' => 20, 'code' => '81', 'name' => 'Op Results', 'created_by' => 1],
        ]);
    }

    private function balances(): array
    {
        return app(LedgerAccountBalanceService::class)
            ->balancesByAccountId(1, '2025-01-01', '2025-12-31', 0, 15, 2025);
    }

    public function test_journal_with_lines_does_not_get_items_added_on_top(): void
    {
        // Journal has BOTH items and lines for the same 100: must count once.
        DB::table('journal_entries')->insert([
            'id' => 1, 'journal_id' => 1, 'date' => '2025-03-01',
            'reference' => 'OP', 'created_by' => 1, 'accounting_period' => 6,
        ]);
        DB::table('journal_items')->insert([
            ['journal' => 1, 'account' => 10, 'debit' => 100, 'credit' => 0],
            ['journal' => 1, 'account' => 20, 'debit' => 0, 'credit' => 100],
        ]);
        DB::table('transaction_lines')->insert([
            ['account_id' => 10, 'debit' => 100, 'credit' => 0, 'date' => '2025-03-01',
                'reference' => 'Journal', 'reference_id' => 1, 'reference_sub_id' => 1, 'created_by' => 1],
            ['account_id' => 20, 'debit' => 0, 'credit' => 100, 'date' => '2025-03-01',
                'reference' => 'Journal', 'reference_id' => 1, 'reference_sub_id' => 2, 'created_by' => 1],
        ]);

        $balances = $this->balances();

        $this->assertSame(100.0, (float) $balances[10]['totalDebit']);
        $this->assertSame(0.0, (float) $balances[10]['totalCredit']);
    }

    public function test_journal_without_lines_falls_back_to_items_once(): void
    {
        // Journal with items but NO lines: fallback counts the items once.
        DB::table('journal_entries')->insert([
            'id' => 2, 'journal_id' => 2, 'date' => '2025-06-01',
            'reference' => 'MANUAL', 'created_by' => 1, 'accounting_period' => 6,
        ]);
        DB::table('journal_items')->insert([
            ['journal' => 2, 'account' => 10, 'debit' => 50, 'credit' => 0],
            ['journal' => 2, 'account' => 20, 'debit' => 0, 'credit' => 50],
        ]);

        $balances = $this->balances();

        $this->assertSame(50.0, (float) $balances[10]['totalDebit']);
        $this->assertSame(50.0, (float) $balances[20]['totalCredit']);
    }
}
