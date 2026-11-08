<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Assets Phase 0 — foundation tables (plan §5, §10).
 *
 * Seven tables only: categories, locations, assets, documents,
 * approvals, status history, audit events. Acquisition /
 * capitalization / assignment / transfer / depreciation /
 * maintenance / disposal / verification tables land in their own
 * phases — never in this migration.
 *
 * All tables are `fixed_asset_*` (never the HR `assets` table).
 * Every row carries created_by (= creatorId(), the company scope).
 * Money is DECIMAL(20,4), rates DECIMAL(20,10) — never float.
 * All index/unique names are explicit and ≤ 23 chars (MySQL's
 * 64-char identifier limit; SQLite tests do not catch this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            // User-typed, short, unique per company. Never reused after
            // a category is deactivated — the unique key covers
            // soft-deleted rows too, so a deleted code stays burned.
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            // tangible | intangible (spec §5).
            $table->string('classification', 20)->default('tangible');
            // Depreciation defaults for assets in this category
            // (spec §5; D6: useful life is per asset in weeks/months/
            // years — the category only holds the default).
            $table->unsignedInteger('default_useful_life')->nullable();
            $table->string('default_life_unit', 10)->default('year');
            // straight_line only in v1; reducing_balance +
            // units_of_production are v2 (plan §4).
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->decimal('depreciation_rate', 10, 4)->nullable();
            // none | percentage | fixed
            $table->string('residual_value_type', 20)->default('none');
            $table->decimal('residual_value_percent', 10, 4)->nullable();
            $table->decimal('residual_value', 20, 4)->nullable();
            $table->decimal('capitalization_threshold', 20, 4)->nullable();
            // Chart account IDs (ChartOfAccount rows, class 32 for the
            // asset account). Nullable — resolved by code in Phase 1;
            // no foreign key so a missing chart row never blocks the
            // register.
            $table->unsignedBigInteger('asset_account_id')->nullable();
            $table->unsignedBigInteger('accum_depreciation_account_id')->nullable();
            $table->unsignedBigInteger('depreciation_expense_account_id')->nullable();
            $table->unsignedBigInteger('impairment_account_id')->nullable();
            $table->unsignedBigInteger('disposal_gain_account_id')->nullable();
            $table->unsignedBigInteger('disposal_loss_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['created_by', 'code'], 'fac_company_code_uq');
            $table->index(['created_by', 'is_active'], 'fac_company_active_idx');
            $table->index(['branch_id'], 'fac_branch_idx');
        });

        Schema::create('fixed_asset_locations', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->string('room', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['created_by', 'code'], 'fal_company_code_uq');
            $table->index(['created_by', 'branch_id'], 'fal_company_branch_idx');
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            // Cost centre is the dedicated Cost Centre master (plan
            // §12b D3, §12c) — the Cost Centre module's cost_centres
            // table, reused here, never rebuilt.
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('category_id');
            // Custodian is an Employee (plan §5 — no separate
            // custodian table).
            $table->unsignedBigInteger('custodian_id')->nullable();
            $table->unsignedBigInteger('parent_asset_id')->nullable();
            // Asset code, e.g. HOYA-IT-2026-0001. Allocated only at
            // capitalization (drafts hold no number) and never reused
            // (spec §10, §24).
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('brand', 100)->nullable();
            // QR/barcode value printed on the label; scanning opens
            // the asset profile (spec §10).
            $table->string('barcode_value', 255)->nullable();
            $table->boolean('is_component')->default(false);
            // purchase | donation | opening_balance | transfer_in | other
            $table->string('acquisition_method', 30)->default('purchase');
            $table->string('supplier', 255)->nullable();
            $table->string('po_reference', 100)->nullable();
            $table->string('invoice_reference', 100)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('receipt_date')->nullable();
            // D2 (plan §12b): every asset stores its own currency +
            // exchange rate + base-currency value (base = MZN).
            $table->decimal('purchase_cost', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('capitalized_cost', 20, 4)->default(0);
            $table->date('warranty_start')->nullable();
            $table->date('warranty_end')->nullable();
            $table->date('capitalization_date')->nullable();
            // D6 (plan §12b): depreciation starts on the purchase /
            // capitalization date; useful life is per asset in
            // weeks/months/years.
            $table->date('depreciation_start_date')->nullable();
            $table->unsignedInteger('useful_life')->nullable();
            $table->string('life_unit', 10)->default('year');
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->decimal('depreciation_rate', 10, 4)->nullable();
            $table->decimal('residual_value', 20, 4)->default(0);
            $table->unsignedBigInteger('asset_account_id')->nullable();
            $table->unsignedBigInteger('accum_depreciation_account_id')->nullable();
            $table->unsignedBigInteger('depreciation_expense_account_id')->nullable();
            $table->decimal('opening_accumulated_depreciation', 20, 4)->default(0);
            // Plan §20 statuses: draft, pending_approval, approved,
            // available, active, assigned, in_transfer,
            // under_maintenance, out_of_service, damaged, lost,
            // stolen, verification_pending, fully_depreciated,
            // impaired, held_for_sale, disposal_pending, disposed,
            // written_off, inactive.
            $table->string('status', 30)->default('draft');
            // Denormalised summary, recalculated only from approved
            // transactions (plan §5).
            $table->decimal('current_book_value', 20, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['created_by', 'code'], 'fa_company_code_uq');
            $table->index(['created_by', 'category_id'], 'fa_company_cat_idx');
            $table->index(['created_by', 'status'], 'fa_company_status_idx');
            $table->index(['created_by', 'location_id'], 'fa_company_loc_idx');
            $table->index(['created_by', 'cost_centre_id'], 'fa_company_cc_idx');
            $table->index(['parent_asset_id'], 'fa_parent_idx');
            $table->index(['custodian_id'], 'fa_custodian_idx');
        });

        Schema::create('fixed_asset_documents', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            // photo | supplier_invoice | warranty | ownership |
            // manual | insurance | note | other
            $table->string('type', 30)->default('other');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // Attachment version history (spec §24): a new version
            // never overwrites the old row.
            $table->unsignedInteger('version')->default(1);
            $table->integer('uploaded_by');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'type'], 'fad_asset_type_idx');
            $table->index(['created_by'], 'fad_creator_idx');
        });

        Schema::create('fixed_asset_approvals', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            // acquisition | transfer | disposal | revaluation |
            // impairment | stock_adjustment (spec §3 — no user may
            // approve their own transaction, at any value).
            $table->string('transaction_type', 30);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedInteger('level')->default(1);
            $table->integer('requested_by');
            $table->integer('approver_id')->nullable();
            // pending | approved | returned | rejected
            $table->string('decision', 20)->default('pending');
            $table->text('comment')->nullable();
            // Base-currency amount the level was routed on (D5).
            $table->decimal('amount_base', 20, 4)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'transaction_type'], 'faa_asset_type_idx');
            $table->index(['created_by', 'decision'], 'faa_creator_dec_idx');
        });

        Schema::create('fixed_asset_status_history', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->integer('changed_by');
            $table->text('reason')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id'], 'fash_asset_idx');
        });

        Schema::create('fixed_asset_audit_events', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('entity', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 60);
            $table->integer('actor_id');
            $table->text('reason')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamps();

            $table->index(['asset_id'], 'faae_asset_idx');
            $table->index(['created_by', 'entity'], 'faae_creator_ent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_audit_events');
        Schema::dropIfExists('fixed_asset_status_history');
        Schema::dropIfExists('fixed_asset_approvals');
        Schema::dropIfExists('fixed_asset_documents');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_locations');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
