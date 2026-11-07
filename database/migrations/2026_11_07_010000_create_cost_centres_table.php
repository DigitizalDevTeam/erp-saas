<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centres', function (Blueprint $table) {
            $table->id();
            // Company scope: created_by is the creatorId() (company) and
            // company_id mirrors it, per the Cost Centre plan §5.
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            // User-typed, short, unique per company. Never reused after a
            // centre is deactivated — the unique key covers soft-deleted
            // rows too, so a deleted code stays burned.
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('manager_user_id')->nullable();
            // active | inactive
            $table->string('status', 20)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['created_by', 'code'], 'cc_company_code_uq');
            $table->index(['created_by', 'status'], 'cc_company_status_idx');
            $table->index(['parent_id'], 'cc_parent_idx');
            $table->index(['branch_id'], 'cc_branch_idx');
            $table->index(['manager_user_id'], 'cc_manager_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centres');
    }
};
