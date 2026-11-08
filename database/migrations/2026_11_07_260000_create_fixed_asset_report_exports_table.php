<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixed Assets Phase 6 — queued report exports (plan §9, §10).
     *
     * One row per requested XLSX/PDF export. The file itself lives
     * under storage/app/fixed-asset-exports/{creatorId}/{token}.{ext};
     * the row is the only thing that links a download to a company,
     * a report and an expiry — a token alone never grants access.
     * Expired rows (and their files) are removed by the
     * fixed-assets:prune-report-exports command.
     *
     * Mirrors the billing report exports table (Contract Phase 4)
     * so the same download rules apply here.
     */
    public function up(): void
    {
        Schema::create('fixed_asset_report_exports', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->string('report', 40);
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('token', 64);
            $table->string('status', 20)->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique('token', 'fare_token_uq');
            $table->index(['created_by', 'status'], 'fare_creator_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_report_exports');
    }
};
