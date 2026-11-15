<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imported historical figures for the management reports (P&L,
     * %P&L). The client's Excel file is the only source for months
     * before the system went live: each row is one monthly line value
     * (revenue, F&B cost, personnel split, channel, ...) for one
     * company. MonthlyManagementData overlays these on the
     * system-computed dataset — a history row always wins for its
     * (month, line), so an imported month can never double-count with
     * system documents, and months without history stay fully
     * automatic.
     *
     * Company scoping follows this app's convention: `created_by`
     * holds the creator/company user id (see User::creatorId()).
     */
    public function up(): void
    {
        Schema::create('management_report_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('line', 100);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['created_by', 'year', 'month', 'line'], 'mgmt_hist_creator_ym_line_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_report_history');
    }
};
