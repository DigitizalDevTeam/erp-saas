<?php

namespace Tests\Unit;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\ManagementAccountMapping;
use App\Models\ManagementCostCentre;
use App\Models\ManagementRule;
use App\Services\ManagementDocumentCosts;
use App\Services\ManagementJournalCosts;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SeedsFileDocuments;
use Tests\TestCase;

/**
 * Journal costs (the second cost source): mapped MANUAL journal
 * postings feed the management P&L; system-posted, pending and
 * rejected journals never count (the one-place-only rule — a cost
 * is either a document or a journal, never both).
 */
class ManagementJournalCostsTest extends TestCase
{
    use SeedsFileDocuments;

    private int $creatorId = 1;

    private int $electricityId;

    private int $deliveryId;

    private int $salariesId;

    private int $inssId;

    private int $unmappedId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createManagementSchema();
        ManagementRule::ensureSeeded($this->creatorId);
        ManagementCostCentre::ensureSeeded($this->creatorId);

        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->integer('parent')->nullable();
            $table->integer('is_enabled')->default(1);
            $table->timestamps();
        });

        Schema::create('journal_entries', function ($table) {
            $table->bigIncrements('id');
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('journal_id')->default(0);
            $table->integer('created_by')->default(0);
            $table->integer('status')->default(0);
            $table->string('source', 20)->nullable();
            $table->string('accounting_period')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_items', function ($table) {
            $table->bigIncrements('id');
            $table->integer('journal')->default(0);
            $table->integer('account')->default(0);
            $table->text('description')->nullable();
            $table->float('debit', 15, 2)->default(0.0);
            $table->float('credit', 15, 2)->default(0.0);
            $table->timestamps();
        });

        Schema::create('management_account_mappings', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->integer('chart_account_id');
            $table->string('group', 100);
            $table->string('subgroup', 100)->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'chart_account_id'], 'mgmt_acct_map_creator_acct_uq');
        });

        $this->electricityId = ChartOfAccount::create(['created_by' => 1, 'code' => '6241', 'name' => 'Eletricidade', 'is_enabled' => 1])->id;
        $this->deliveryId = ChartOfAccount::create(['created_by' => 1, 'code' => '6225_01', 'name' => 'Delivery Partner', 'is_enabled' => 1])->id;
        $this->salariesId = ChartOfAccount::create(['created_by' => 1, 'code' => '632', 'name' => 'Remunerações Pessoal', 'is_enabled' => 1])->id;
        $this->inssId = ChartOfAccount::create(['created_by' => 1, 'code' => '245', 'name' => 'Segurança Social', 'is_enabled' => 1])->id;
        $this->unmappedId = ChartOfAccount::create(['created_by' => 1, 'code' => '9999', 'name' => 'Unmapped Account', 'is_enabled' => 1])->id;

        ManagementAccountMapping::create(['created_by' => 1, 'chart_account_id' => $this->electricityId, 'group' => 'Custos Fixos', 'subgroup' => 'Utilities']);
        ManagementAccountMapping::create(['created_by' => 1, 'chart_account_id' => $this->deliveryId, 'group' => 'Custos Variáveis', 'subgroup' => 'Comissões Delivery']);
        ManagementAccountMapping::create(['created_by' => 1, 'chart_account_id' => $this->salariesId, 'group' => 'Pessoal - Remunerações', 'subgroup' => 'Remunerações']);
        ManagementAccountMapping::create(['created_by' => 1, 'chart_account_id' => $this->inssId, 'group' => 'Pessoal - Impostos', 'subgroup' => 'SS']);
    }

    protected function tearDown(): void
    {
        $this->dropManagementSchema();
        foreach (['chart_of_accounts', 'journal_entries', 'journal_items', 'management_account_mappings'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function postJournal(int $accountId, float $debit, string $date, ?string $source, int $status): void
    {
        $entry = JournalEntry::create([
            'date' => $date,
            'reference' => 'TEST',
            'description' => 'Test journal',
            'journal_id' => 1,
            'created_by' => $this->creatorId,
            'status' => $status,
            'source' => $source,
        ]);
        JournalItem::create([
            'journal' => $entry->id,
            'account' => $accountId,
            'debit' => $debit,
            'credit' => 0,
        ]);
    }

    public function test_no_mappings_means_no_journal_costs(): void
    {
        ManagementAccountMapping::where('created_by', 1)->delete();
        $this->postJournal($this->electricityId, 100.00, '2026-06-10', 'manual', JournalEntry::STATUS_APPROVED);

        $data = ManagementJournalCosts::forYear($this->creatorId, 2026);
        $this->assertEqualsWithDelta(0.0, array_sum($data['fixed']), 0.01);
        $this->assertSame([], $data['groups']);
    }

    public function test_manual_approved_journal_flows_into_the_right_lines(): void
    {
        $this->postJournal($this->electricityId, 100.00, '2026-06-10', 'manual', JournalEntry::STATUS_APPROVED);
        $this->postJournal($this->deliveryId, 50.00, '2026-06-11', 'manual', JournalEntry::STATUS_APPROVED);
        $this->postJournal($this->salariesId, 200.00, '2026-06-12', 'manual', JournalEntry::STATUS_APPROVED);
        $this->postJournal($this->inssId, 30.00, '2026-06-13', 'manual', JournalEntry::STATUS_APPROVED);

        $data = ManagementJournalCosts::forYear($this->creatorId, 2026);
        $this->assertEqualsWithDelta(100.00, $data['fixed'][6], 0.01);
        $this->assertEqualsWithDelta(50.00, $data['delivery'][6], 0.01);
        $this->assertEqualsWithDelta(50.00, $data['variables'][6], 0.01);
        $this->assertEqualsWithDelta(230.00, $data['personnel']['total'][6], 0.01);
        $this->assertEqualsWithDelta(30.00, $data['personnel']['inss'][6], 0.01);
        $electricityRow = collect($data['accounts'])->firstWhere('label', '6241 - Eletricidade');
        $this->assertNotNull($electricityRow);
        $this->assertEqualsWithDelta(100.00, $electricityRow['months'][6], 0.01);
    }

    public function test_system_pending_rejected_and_legacy_journals_never_count(): void
    {
        // System-posted (a bill's journal): the same cost already has
        // its document, so counting it would double-count.
        $this->postJournal($this->electricityId, 100.00, '2026-06-10', 'system', JournalEntry::STATUS_APPROVED);
        // Pending manual journal: not approved yet.
        $this->postJournal($this->electricityId, 100.00, '2026-06-10', 'manual', JournalEntry::STATUS_PENDING);
        // Rejected manual journal.
        $this->postJournal($this->electricityId, 100.00, '2026-06-10', 'manual', JournalEntry::STATUS_REJECTED);
        // Legacy journal (written before the source column existed).
        $this->postJournal($this->electricityId, 100.00, '2026-06-10', null, JournalEntry::STATUS_APPROVED);
        // Unmapped account, manual + approved: no mapping, no cost.
        $this->postJournal($this->unmappedId, 100.00, '2026-06-10', 'manual', JournalEntry::STATUS_APPROVED);

        $data = ManagementJournalCosts::forYear($this->creatorId, 2026);
        $this->assertEqualsWithDelta(0.0, array_sum($data['fixed']), 0.01);
        $this->assertEqualsWithDelta(0.0, array_sum($data['variables']), 0.01);
        $this->assertEqualsWithDelta(0.0, array_sum($data['personnel']['total']), 0.01);
        $this->assertSame([], array_filter(array_map(fn ($row) => $row['total'], $data['accounts'])));
    }

    public function test_journal_costs_merge_with_documents_in_the_pl(): void
    {
        $this->seedFileDocuments($this->creatorId);
        $this->postJournal($this->electricityId, 100.00, '2026-01-10', 'manual', JournalEntry::STATUS_APPROVED);

        $data = ManagementDocumentCosts::forYear($this->creatorId, 2026);
        // File FIXOS Jan = 2173.10 (the Ágoraluz electricity document)
        // + the 100.00 journal on the same account.
        $this->assertEqualsWithDelta(2273.10, $data['fixed'][1], 0.01);
        $this->assertEqualsWithDelta(14363.43, array_sum($data['total_geral']), 0.01);
        // The journal row shows under its group in the Costs table.
        $table = ManagementDocumentCosts::costsTable($this->creatorId, 2026, 'all');
        $journalRow = collect($table['rows'])->first(fn ($r) => $r['label'] === '6241 - Eletricidade' && $r['is_journal']);
        $this->assertNotNull($journalRow);
        $this->assertTrue($journalRow['is_journal']);
        $this->assertEqualsWithDelta(100.00, $journalRow['months'][1], 0.01);
    }

    public function test_file_totals_still_match_with_no_journals(): void
    {
        $this->seedFileDocuments($this->creatorId);

        $data = ManagementDocumentCosts::forYear($this->creatorId, 2026);
        $this->assertEqualsWithDelta(14263.43, array_sum($data['total_geral']), 0.01);
        $this->assertEqualsWithDelta(2939.05, array_sum($data['cogs']), 0.01);
    }
}
