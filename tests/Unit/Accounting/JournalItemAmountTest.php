<?php

namespace Tests\Unit\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Support\MoneyAmount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JournalItemAmountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedTinyInteger('accounting_period')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('journal')->default(0);
            $table->unsignedBigInteger('account')->default(0);
            $table->text('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('journal_items');
        Schema::dropIfExists('journal_entries');

        parent::tearDown();
    }

    public function test_journal_item_stores_comma_input_amount_exactly(): void
    {
        $amount = MoneyAmount::parse('22,87');

        $journal = JournalEntry::create([
            'date' => '2026-08-29',
            'reference' => 'TEST',
            'description' => 'Rounding test',
            'journal_id' => 1,
            'status' => JournalEntry::STATUS_PENDING,
            'created_by' => 1,
        ]);

        $item = JournalItem::create([
            'journal' => $journal->id,
            'account' => 101,
            'description' => 'Debit line',
            'debit' => $amount,
            'credit' => 0,
        ]);

        $item->refresh();
        $journal->refresh();

        $this->assertSame('22.87', (string) $item->debit);
        $this->assertSame(22.87, (float) $item->debit);
        $this->assertSame(22.87, $journal->totalDebit());
        $this->assertNotSame(22.90, (float) $item->debit);
    }

    public function test_journal_totals_remain_balanced_with_comma_amounts(): void
    {
        $journal = JournalEntry::create([
            'date' => '2026-08-29',
            'reference' => 'TEST',
            'description' => 'Balance test',
            'journal_id' => 2,
            'status' => JournalEntry::STATUS_PENDING,
            'created_by' => 1,
        ]);

        JournalItem::create([
            'journal' => $journal->id,
            'account' => 101,
            'debit' => MoneyAmount::parse('22,87'),
            'credit' => 0,
        ]);

        JournalItem::create([
            'journal' => $journal->id,
            'account' => 102,
            'debit' => 0,
            'credit' => MoneyAmount::parse('22,87'),
        ]);

        $journal->load('accounts');

        $this->assertTrue(MoneyAmount::equals($journal->totalDebit(), $journal->totalCredit()));
        $this->assertSame(22.87, $journal->totalDebit());
        $this->assertSame(22.87, $journal->totalCredit());
    }
}
