<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Management Documents (Documentos) — the client's Excel file
     * (PnL2026_Baseline.xlsx) as database tables, per company.
     *
     * The file is the spec: Documentos (every expense document, one row
     * each) -> Rules (each category maps to one P&L group + subgroup) ->
     * Costs tabs (SUMIFS by category x month, and by cost centre).
     *
     *  management_rules — the Rules catalogue, one row per category
     *    (code + name, e.g. 6263 / Seguros), its P&L group + subgroup +
     *    order. Each company has its OWN list it can change.
     *  management_cost_centres — the company's cost centres (101, 102,
     *    ... or none at all: a document may have no cost centre).
     *  management_supplier_categories — supplier -> default category:
     *    every later document from that supplier is auto-assigned the
     *    same category (the user can still change it per document).
     *  management_documents — the Documentos rows: date, supplier
     *    (NIF + name), doc number, BASE, IVA, retention, TOTAL, status,
     *    bank account, payment method, category (rule), cost centre,
     *    notes + a doc TYPE (invoice / credit_note / quotation /
     *    bank_transfer / manual). A credit note carries NEGATIVE
     *    amounts, so it reduces costs. A quotation is stored but
     *    EXCLUDED from costs. A cost with NO document is a Documentos
     *    row with an empty doc number + type "manual" (flagged in the
     *    UI) — if Danilo later wants a separate screen, the data model
     *    already supports it.
     *
     * Company scoping follows this app's convention: `created_by` holds
     * the creator/company user id (see User::creatorId()). MÊS, GRUPO
     * P&L and VALOR NET are computed, not stored (the file's three
     * "NÃO MEXER" columns): month from the date, group from the
     * category's rule, net = BASE when present and non-zero, else TOTAL.
     */
    public function up(): void
    {
        Schema::create('management_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->string('code', 50);
            $table->string('name', 255)->default('');
            $table->string('group', 100);
            $table->string('subgroup', 100)->default('');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['created_by', 'code'], 'mgmt_rules_creator_code_uq');
            $table->index('created_by', 'mgmt_rules_creator_idx');
        });

        Schema::create('management_cost_centres', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->string('code', 20);
            $table->string('name', 255);
            $table->timestamps();
            $table->unique(['created_by', 'code'], 'mgmt_cc_creator_code_uq');
            $table->index('created_by', 'mgmt_cc_creator_idx');
        });

        Schema::create('management_supplier_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->string('supplier_name', 255);
            $table->string('supplier_nif', 50)->nullable();
            $table->foreignId('management_rule_id')->constrained('management_rules')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['created_by', 'supplier_name'], 'msc_creator_supplier_uq');
            $table->index('created_by', 'msc_creator_idx');
        });

        Schema::create('management_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->date('doc_date');
            $table->string('supplier_nif', 50)->nullable();
            $table->string('supplier_name', 255);
            $table->string('doc_number', 100)->nullable();
            $table->string('doc_type', 20)->default('invoice');
            $table->decimal('base', 14, 2)->nullable();
            $table->decimal('iva', 14, 2)->nullable();
            $table->decimal('retention', 14, 2)->nullable();
            $table->decimal('total', 14, 2);
            $table->string('status', 50)->default('Conciliado');
            $table->string('bank_account', 255)->nullable();
            $table->string('payment_method', 100)->nullable();
            $table->foreignId('management_rule_id')->constrained('management_rules')->restrictOnDelete();
            $table->foreignId('management_cost_centre_id')->nullable()->constrained('management_cost_centres')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'doc_date'], 'mgmt_docs_creator_date_idx');
            $table->index(['created_by', 'management_rule_id'], 'mgmt_docs_creator_rule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_documents');
        Schema::dropIfExists('management_supplier_categories');
        Schema::dropIfExists('management_cost_centres');
        Schema::dropIfExists('management_rules');
    }
};
