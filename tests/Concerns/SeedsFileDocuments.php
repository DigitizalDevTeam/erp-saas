<?php

namespace Tests\Concerns;

use App\Models\Invoice;
use App\Models\InvoiceProduct;
use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;
use App\Models\Tax;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the 27 example documents from the client's file
 * (PnL2026_Baseline.xlsx, Documentos tab, Jan–May 2026) for a test
 * company, plus the two invoices that make the file's revenue
 * (Jan 12,000 + Feb 10,000 ex-VAT; IVA 1,946 + 1,700).
 * The file's verified Jan–May totals: revenue 22,000; F&B 2,939.05;
 * EBITDA 7,736.57; Costs TOTAL GERAL 14,263.43.
 *
 * erpsaas adaptation of the erpsaaspro trait: company scoping is
 * `created_by` (the app's creatorId() pattern — companies are users
 * of type "company", there is no companies table), and revenue comes
 * from Invoice + InvoiceProduct rows (POS sales ARE invoices here).
 */
trait SeedsFileDocuments
{
    protected function createManagementSchema(): void
    {
        Schema::create('management_rules', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('name');
            $table->string('group');
            $table->string('subgroup')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['created_by', 'code'], 'mr_creator_code_uq');
        });

        Schema::create('management_cost_centres', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('name');
            $table->timestamps();
            $table->unique(['created_by', 'code'], 'mcc_creator_code_uq');
        });

        Schema::create('management_supplier_categories', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->string('supplier_name');
            $table->unsignedBigInteger('management_rule_id');
            $table->timestamps();
            $table->unique(['created_by', 'supplier_name'], 'msc_creator_supplier_uq');
        });

        Schema::create('management_documents', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->date('doc_date');
            $table->string('supplier_nif', 50)->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('doc_number', 100)->nullable();
            $table->string('doc_type', 20);
            $table->decimal('base', 15, 2)->nullable();
            $table->decimal('iva', 15, 2)->nullable();
            $table->decimal('retention', 15, 2)->nullable();
            $table->decimal('total', 15, 2);
            $table->string('status', 50);
            $table->string('bank_account', 100)->nullable();
            $table->string('payment_method', 100)->nullable();
            $table->unsignedBigInteger('management_rule_id');
            $table->unsignedBigInteger('management_cost_centre_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('management_report_history', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->integer('year');
            $table->integer('month');
            $table->string('line');
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['created_by', 'year', 'month', 'line'], 'mgmt_hist_creator_ym_line_uq');
        });
    }

    protected function createRevenueSchema(): void
    {
        Schema::create('taxes', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->decimal('rate', 8, 2)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id')->nullable();
            $table->integer('customer_id')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->integer('status')->default(0);
            $table->string('document_type', 50)->nullable();
            $table->integer('category_id')->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('pos_id')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->decimal('quantity', 15, 2)->default(1);
            $table->string('tax')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('pos', function ($table) {
            $table->bigIncrements('id');
            $table->string('pos_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('order_type', 50)->nullable();
            $table->integer('table_id')->nullable();
            $table->integer('shift_id')->nullable();
            $table->integer('customer_id')->nullable();
            $table->date('pos_date')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('document_type', 50)->nullable();
            $table->string('status', 50)->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('pos_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('pos_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->decimal('quantity', 15, 2)->default(1);
            $table->string('tax')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_payments', function ($table) {
            $table->bigIncrements('id');
            $table->integer('pos_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->date('date')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
    }

    protected function dropManagementSchema(): void
    {
        foreach (['management_documents', 'management_supplier_categories', 'management_cost_centres', 'management_rules', 'management_report_history', 'invoices', 'invoice_products', 'taxes', 'pos', 'pos_products', 'pos_payments'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** The 27 Documentos rows, verbatim from the file. */
    protected static function fileDocuments(): array
    {
        return [
            [
                'doc_date' => '2026-03-05', 'supplier_nif' => '518068315', 'supplier_name' => 'Aayupaksh, Lda',
                'doc_number' => 'FR BOLTRH003/17', 'base' => 5.57, 'iva' => 0.33, 'retention' => null, 'total' => 5.9,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'C. Débito', 'category' => '6251_3 - Deslocações', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-01-31', 'supplier_nif' => '313593094', 'supplier_name' => 'Abhishek Ghale',
                'doc_number' => '01/2026', 'base' => null, 'iva' => null, 'retention' => null, 'total' => 1994.08,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '632 - Remunerações Pessoal', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-04-30', 'supplier_nif' => '313593094', 'supplier_name' => 'Abhishek Ghale',
                'doc_number' => '04/2026', 'base' => null, 'iva' => null, 'retention' => null, 'total' => 1134.59,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '632 - Remunerações Pessoal', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-03-31', 'supplier_nif' => '313593094', 'supplier_name' => 'Abhishek Ghale',
                'doc_number' => '03/2026', 'base' => null, 'iva' => null, 'retention' => null, 'total' => 911.05,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '632 - Remunerações Pessoal', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-02-24', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/1200', 'base' => 102.0, 'iva' => 13.26, 'retention' => null, 'total' => 115.26,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-05-10', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/2965', 'base' => 84.6, 'iva' => 11.0, 'retention' => null, 'total' => 95.6,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-02-11', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/875', 'base' => 84.6, 'iva' => 11.0, 'retention' => null, 'total' => 95.6,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-01-14', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/263', 'base' => 79.0, 'iva' => 10.27, 'retention' => null, 'total' => 89.27,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-02-17', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/1018', 'base' => 78.0, 'iva' => 10.14, 'retention' => null, 'total' => 88.14,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-04-14', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/2362', 'base' => 67.2, 'iva' => 8.74, 'retention' => null, 'total' => 75.94,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-03-11', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/1551', 'base' => 67.2, 'iva' => 8.74, 'retention' => null, 'total' => 75.94,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-03-24', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/1884', 'base' => 67.2, 'iva' => 8.74, 'retention' => null, 'total' => 75.94,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-01-21', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/441', 'base' => 67.2, 'iva' => 8.74, 'retention' => null, 'total' => 75.94,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-01-27', 'supplier_nif' => '506798410', 'supplier_name' => 'Acácio Pereira Magalhães - Sociedade Unipessoal , Lda',
                'doc_number' => 'FT 2026A1/574', 'base' => 67.2, 'iva' => 8.74, 'retention' => null, 'total' => 75.94,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_09 - Vinho e Bebidas Alcoólicas', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-01-29', 'supplier_nif' => '517690012', 'supplier_name' => 'Active Variations',
                'doc_number' => 'FR BOLTRH003/131', 'base' => 6.42, 'iva' => 0.38, 'retention' => null, 'total' => 6.8,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'C. Débito', 'category' => '6251_3 - Deslocações', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-03-17', 'supplier_nif' => '298546370', 'supplier_name' => 'Afiação Cristofolini',
                'doc_number' => 'FR M/1555', 'base' => 76.42, 'iva' => 17.58, 'retention' => 8.79, 'total' => 85.21,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'C. Débito', 'category' => '6226 - Conservação E Reparação', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-01-28', 'supplier_nif' => '516065742', 'supplier_name' => 'Ágoraluz Energia, Lda',
                'doc_number' => 'FT FA.AG26/851', 'base' => 2173.1, 'iva' => 499.32, 'retention' => null, 'total' => 2672.42,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Débito Directo', 'category' => '6241 - Eletricidade', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-04-28', 'supplier_nif' => '516065742', 'supplier_name' => 'Ágoraluz Energia, Lda',
                'doc_number' => 'FT FA.AG26/4528', 'base' => 1751.62, 'iva' => 402.38, 'retention' => null, 'total' => 2154.0,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Débito Directo', 'category' => '6241 - Eletricidade', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-04-01', 'supplier_nif' => '516065742', 'supplier_name' => 'Ágoraluz Energia, Lda',
                'doc_number' => 'FT FA.AG26/3556', 'base' => 1680.96, 'iva' => 386.18, 'retention' => null, 'total' => 2067.14,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Débito Directo', 'category' => '6241 - Eletricidade', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-03-03', 'supplier_nif' => '516065742', 'supplier_name' => 'Ágoraluz Energia, Lda',
                'doc_number' => 'FT FA.AG26/2343', 'base' => 1590.57, 'iva' => 365.33, 'retention' => null, 'total' => 1955.9,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Débito Directo', 'category' => '6241 - Eletricidade', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-02-09', 'supplier_nif' => '515030384', 'supplier_name' => 'Aquela Kombucha, Lda',
                'doc_number' => 'FT 2026/339', 'base' => 108.0, 'iva' => 24.84, 'retention' => null, 'total' => 132.84,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_07 - Águas, Refrigerantes e Outros', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-04-29', 'supplier_nif' => '515051713', 'supplier_name' => 'Arriaga & Bragança, Lda',
                'doc_number' => 'FT 2026/1702', 'base' => 544.32, 'iva' => 32.66, 'retention' => null, 'total' => 576.98,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_01 - Mercearia', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-04-06', 'supplier_nif' => '515051713', 'supplier_name' => 'Arriaga & Bragança, Lda',
                'doc_number' => 'FT 2026/1326', 'base' => 322.68, 'iva' => 19.36, 'retention' => null, 'total' => 342.04,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_01 - Mercearia', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-02-11', 'supplier_nif' => '515051713', 'supplier_name' => 'Arriaga & Bragança, Lda',
                'doc_number' => 'FT 2026/491', 'base' => 314.72, 'iva' => 18.88, 'retention' => null, 'total' => 333.6,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_01 - Mercearia', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-03-18', 'supplier_nif' => '515051713', 'supplier_name' => 'Arriaga & Bragança, Lda',
                'doc_number' => 'FT 2026/1014', 'base' => 303.76, 'iva' => 18.23, 'retention' => null, 'total' => 321.99,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_01 - Mercearia', 'cc' => '102 | Group',
            ],
            [
                'doc_date' => '2026-04-15', 'supplier_nif' => '515051713', 'supplier_name' => 'Arriaga & Bragança, Lda',
                'doc_number' => 'FT 2026/1465', 'base' => 300.92, 'iva' => 18.06, 'retention' => null, 'total' => 318.98,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_01 - Mercearia', 'cc' => '101 | Restaurante',
            ],
            [
                'doc_date' => '2026-02-14', 'supplier_nif' => '515051713', 'supplier_name' => 'Arriaga & Bragança, Lda',
                'doc_number' => 'FT 2026/544', 'base' => 280.45, 'iva' => 16.83, 'retention' => null, 'total' => 297.28,
                'status' => 'Conciliado', 'bank_account' => 'BCP Ordem', 'payment_method' => 'Tran. Bancária', 'category' => '312_01 - Mercearia', 'cc' => '101 | Restaurante',
            ],
        ];
    }

    protected function seedFileDocuments(int $creatorId): void
    {
        foreach (self::fileDocuments() as $row) {
            $rule = ManagementRule::where('created_by', $creatorId)
                ->where('code', explode(' - ', $row['category'])[0])
                ->firstOrFail();
            $cc = null;
            if ($row['cc']) {
                $code = explode(' | ', $row['cc'])[0];
                $cc = ManagementCostCentre::where('created_by', $creatorId)
                    ->where('code', $code)
                    ->firstOrFail();
            }
            ManagementDocument::create([
                'created_by' => $creatorId,
                'doc_date' => $row['doc_date'],
                'supplier_nif' => $row['supplier_nif'],
                'supplier_name' => $row['supplier_name'],
                'doc_number' => $row['doc_number'],
                'doc_type' => 'invoice',
                'base' => $row['base'],
                'iva' => $row['iva'],
                'retention' => $row['retention'],
                'total' => $row['total'],
                'status' => $row['status'],
                'bank_account' => $row['bank_account'],
                'payment_method' => $row['payment_method'],
                'management_rule_id' => $rule->id,
                'management_cost_centre_id' => $cc?->id,
            ]);
        }
    }

    /**
     * The file's revenue: Jan 12,000 + Feb 10,000 ex-VAT (Sales tab).
     * Jan = Restaurante 10,000 @ 17% IVA (1,700) + Bar 2,000 @ 12.3%
     * (246); Feb = Restaurante 10,000 @ 17% (1,700).
     */
    protected function seedFileRevenue(int $creatorId): void
    {
        $tax17 = Tax::create(['name' => 'IVA 17%', 'rate' => 17, 'created_by' => $creatorId]);
        $tax123 = Tax::create(['name' => 'IVA 12.3%', 'rate' => 12.3, 'created_by' => $creatorId]);

        $jan = Invoice::create([
            'invoice_id' => 1, 'issue_date' => '2026-01-15', 'due_date' => '2026-02-14',
            'status' => Invoice::STATUS_SENT, 'created_by' => $creatorId,
        ]);
        InvoiceProduct::create(['invoice_id' => $jan->id, 'quantity' => 1, 'price' => 10000, 'discount' => 0, 'tax' => (string) $tax17->id]);
        InvoiceProduct::create(['invoice_id' => $jan->id, 'quantity' => 1, 'price' => 2000, 'discount' => 0, 'tax' => (string) $tax123->id]);

        $feb = Invoice::create([
            'invoice_id' => 2, 'issue_date' => '2026-02-15', 'due_date' => '2026-03-17',
            'status' => Invoice::STATUS_SENT, 'created_by' => $creatorId,
        ]);
        InvoiceProduct::create(['invoice_id' => $feb->id, 'quantity' => 1, 'price' => 10000, 'discount' => 0, 'tax' => (string) $tax17->id]);
    }
}
