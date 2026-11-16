<?php

namespace Tests\Unit;

use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;
use App\Services\ManagementDocumentCosts;
use Tests\Concerns\SeedsFileDocuments;
use Tests\TestCase;

/**
 * Documents -> Costs aggregation (the file's SUMIFS) against the
 * client's verified Jan–May 2026 numbers from PnL2026_Baseline.xlsx.
 */
class ManagementDocumentCostsTest extends TestCase
{
    use SeedsFileDocuments;

    private int $creatorId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createManagementSchema();
        ManagementRule::ensureSeeded($this->creatorId);
        ManagementCostCentre::ensureSeeded($this->creatorId);
        $this->seedFileDocuments($this->creatorId);
    }

    protected function tearDown(): void
    {
        $this->dropManagementSchema();
        parent::tearDown();
    }

    public function test_aggregation_matches_file_costs_total(): void
    {
        $data = ManagementDocumentCosts::forYear($this->creatorId, 2026);

        // F&B = CMVM Food + Beverage + Staff Food (file's PnL row 7).
        $this->assertEquals([1 => 213.40, 2 => 967.77, 3 => 438.16, 4 => 1235.12, 5 => 84.60], $this->firstFive($data['cogs']));
        $this->assertEqualsWithDelta(2939.05, array_sum($data['cogs']), 0.01);

        // Custos Variáveis (file's Costs-Total row 21).
        $this->assertEquals([1 => 6.42, 2 => 0.0, 3 => 5.57, 4 => 0.0, 5 => 0.0], $this->firstFive($data['variables']));
        $this->assertEqualsWithDelta(11.99, array_sum($data['variables']), 0.01);

        // Pessoal = Remunerações (all on CC 101) + Impostos (0).
        $this->assertEqualsWithDelta(4039.72, array_sum($data['personnel']['total']), 0.01);
        $this->assertEqualsWithDelta(4039.72, array_sum($data['personnel']['departments']['Restaurante (CC 101)']), 0.01);
        $this->assertEqualsWithDelta(0.0, array_sum($data['personnel']['inss']), 0.01);

        // FIXOS (file's Costs-Total row 37) and OUTROS (row 64).
        $this->assertEqualsWithDelta(7196.25, array_sum($data['fixed']), 0.01);
        $this->assertEqualsWithDelta(76.42, array_sum($data['other']), 0.01);

        // TOTAL GERAL (file's row 84) = all P&L groups + unmapped.
        $this->assertEqualsWithDelta(14263.43, array_sum($data['total_geral']), 0.01);

        // No documents without a cost centre, no unmapped categories.
        $this->assertEqualsWithDelta(0.0, array_sum($data['no_cost_centre']), 0.01);
        $this->assertEqualsWithDelta(0.0, array_sum($data['unmapped']), 0.01);

        // Delivery commission is 0 in the file (no 6225_01 documents).
        $this->assertEqualsWithDelta(0.0, array_sum($data['delivery']), 0.01);
    }

    public function test_net_value_falls_back_to_total_when_base_empty(): void
    {
        // The file has 3 rows with an empty BASE (the three salary docs).
        $doc = ManagementDocument::where('created_by', $this->creatorId)
            ->whereNull('base')
            ->firstOrFail();
        $this->assertEqualsWithDelta((float) $doc->total, $doc->netValue(), 0.01);

        // And the retention row: BASE + IVA − retention = TOTAL, net = BASE.
        $retention = ManagementDocument::where('created_by', $this->creatorId)
            ->where('doc_number', 'FR M/1555')
            ->firstOrFail();
        $this->assertEqualsWithDelta(76.42, $retention->netValue(), 0.01);
        $this->assertEqualsWithDelta(85.21, (float) $retention->total, 0.01);
    }

    public function test_credit_note_reduces_costs(): void
    {
        $rule = ManagementRule::where('created_by', $this->creatorId)->where('code', '632')->firstOrFail();
        ManagementDocument::create([
            'created_by' => $this->creatorId,
            'doc_date' => '2026-01-20',
            'supplier_name' => 'Abhishek Bhardwaj',
            'doc_number' => 'CN-001',
            'doc_type' => 'credit_note',
            'base' => -100.00,
            'iva' => 0,
            'retention' => 0,
            'total' => -100.00,
            'status' => 'Conciliado',
            'management_rule_id' => $rule->id,
        ]);

        $data = ManagementDocumentCosts::forYear($this->creatorId, 2026);
        $this->assertEqualsWithDelta(1894.08, $data['personnel']['total'][1], 0.01);
        $this->assertEqualsWithDelta(3939.72, array_sum($data['personnel']['total']), 0.01);
    }

    public function test_quotation_is_stored_but_excluded_from_costs(): void
    {
        $rule = ManagementRule::where('created_by', $this->creatorId)->where('code', '312_01')->firstOrFail();
        ManagementDocument::create([
            'created_by' => $this->creatorId,
            'doc_date' => '2026-01-20',
            'supplier_name' => 'Big Supplier',
            'doc_number' => 'QT-001',
            'doc_type' => 'quotation',
            'base' => 5000.00,
            'iva' => 0,
            'retention' => 0,
            'total' => 5000.00,
            'status' => 'Pendente',
            'management_rule_id' => $rule->id,
        ]);

        $data = ManagementDocumentCosts::forYear($this->creatorId, 2026);
        $this->assertEqualsWithDelta(213.40, $data['cogs'][1], 0.01);
        $this->assertEqualsWithDelta(14263.43, array_sum($data['total_geral']), 0.01);
    }

    public function test_delivery_commission_is_split_out_of_variables(): void
    {
        $rule = ManagementRule::where('created_by', $this->creatorId)->where('code', '6225_01')->firstOrFail();
        ManagementDocument::create([
            'created_by' => $this->creatorId,
            'doc_date' => '2026-01-20',
            'supplier_name' => 'Delivery Partner',
            'doc_number' => 'DP-001',
            'doc_type' => 'invoice',
            'base' => 50.00,
            'iva' => 0,
            'retention' => 0,
            'total' => 50.00,
            'status' => 'Conciliado',
            'management_rule_id' => $rule->id,
        ]);

        $data = ManagementDocumentCosts::forYear($this->creatorId, 2026);
        $this->assertEqualsWithDelta(50.00, $data['delivery'][1], 0.01);
        // Variables still include delivery (the P&L VARIAVEIS row is the
        // group total); the %PnL splits it out.
        $this->assertEqualsWithDelta(56.42, $data['variables'][1], 0.01);
    }

    public function test_anulado_document_is_excluded_from_costs(): void
    {
        $rule = ManagementRule::where('created_by', $this->creatorId)->where('code', '312_01')->firstOrFail();
        ManagementDocument::create([
            'created_by' => $this->creatorId,
            'doc_date' => '2026-01-20',
            'supplier_name' => 'Void Supplier',
            'doc_number' => 'VOID-001',
            'doc_type' => 'invoice',
            'base' => 500.00,
            'iva' => 0,
            'retention' => 0,
            'total' => 500.00,
            'status' => 'Anulado',
            'management_rule_id' => $rule->id,
        ]);

        $data = ManagementDocumentCosts::forYear($this->creatorId, 2026);
        $this->assertEqualsWithDelta(213.40, $data['cogs'][1], 0.01);
        $this->assertEqualsWithDelta(14263.43, array_sum($data['total_geral']), 0.01);
    }

    public function test_pendente_document_still_counts(): void
    {
        $rule = ManagementRule::where('created_by', $this->creatorId)->where('code', '312_01')->firstOrFail();
        ManagementDocument::create([
            'created_by' => $this->creatorId,
            'doc_date' => '2026-01-20',
            'supplier_name' => 'Pending Supplier',
            'doc_number' => 'PEND-001',
            'doc_type' => 'invoice',
            'base' => 500.00,
            'iva' => 0,
            'retention' => 0,
            'total' => 500.00,
            'status' => 'Pendente',
            'management_rule_id' => $rule->id,
        ]);

        $data = ManagementDocumentCosts::forYear($this->creatorId, 2026);
        // Accrual: a pending document is a cost, like in the file.
        $this->assertEqualsWithDelta(713.40, $data['cogs'][1], 0.01);
    }

    public function test_net_value_returns_zero_when_base_is_zero(): void
    {
        // The file's formula: empty BASE -> TOTAL, but BASE 0 -> 0.
        $rule = ManagementRule::where('created_by', $this->creatorId)->where('code', '312_01')->firstOrFail();
        $doc = ManagementDocument::create([
            'created_by' => $this->creatorId,
            'doc_date' => '2026-06-10',
            'supplier_name' => 'Zero Base',
            'doc_number' => 'ZB-001',
            'doc_type' => 'invoice',
            'base' => 0,
            'iva' => 0,
            'retention' => 0,
            'total' => 100.00,
            'status' => 'Conciliado',
            'management_rule_id' => $rule->id,
        ]);
        $this->assertSame(0.0, $doc->netValue());
    }

    public function test_costs_table_matches_file_total_geral_and_cc102_view(): void
    {
        $table = ManagementDocumentCosts::costsTable($this->creatorId, 2026, 'all');
        $this->assertEqualsWithDelta(14263.43, array_sum($table['total_geral']), 0.01);
        $this->assertEqualsWithDelta(0.0, array_sum($table['no_cost_centre']), 0.01);

        // Costs-102: the single CC-102 document (303.76, Mar, Mercearia).
        $cc102 = ManagementCostCentre::where('created_by', $this->creatorId)->where('code', '102')->firstOrFail();
        $table102 = ManagementDocumentCosts::costsTable($this->creatorId, 2026, $cc102->id);
        $this->assertEqualsWithDelta(303.76, array_sum($table102['total_geral']), 0.01);
        $this->assertEqualsWithDelta(303.76, $table102['total_geral'][3], 0.01);
    }

    private function firstFive(array $months): array
    {
        return [1 => $months[1], 2 => $months[2], 3 => $months[3], 4 => $months[4], 5 => $months[5]];
    }
}
