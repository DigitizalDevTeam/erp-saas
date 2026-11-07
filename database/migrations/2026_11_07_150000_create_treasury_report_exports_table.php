<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Treasury Phase 9 — queued report exports (plan §9).
     *
     * One row per requested XLSX/PDF export. The file itself lives
     * under storage/app/treasury-exports/{creatorId}/{token}.{ext};
     * the row is the only thing that links a download to a company,
     * a report and an expiry — a token alone never grants access.
     * Expired rows (and their files) are removed by the
     * treasury:prune-report-exports command.
     */
    public function up(): void
    {
        Schema::create('treasury_report_exports', function (Blueprint $table) {
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

            $table->unique('token', 'tre_token_uq');
            $table->index(['created_by', 'status'], 'tre_creator_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_report_exports');
    }
};
