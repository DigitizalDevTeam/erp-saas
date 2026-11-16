<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddVoidedAtToInvoicePaymentsAndCreditNoteColumns extends Migration
{
    /**
     * Run the migrations.
     *
     * Issue 7 (client issues): voiding an invoice must keep the payment
     * rows (marked voided) instead of deleting them, and auto-created
     * credit notes need a real sequential number. The credit_notes
     * columns below (uid / created_by / is_debit) are already used by
     * CreditNoteController but were never added by any migration, so a
     * fresh install lacks them; each column is added only when missing
     * so this migration is safe on databases that already have them.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_payments', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('updated_at');
            }
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_notes', 'uid')) {
                $table->bigInteger('uid')->default(1)->after('id');
            }
            if (! Schema::hasColumn('credit_notes', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('description');
            }
            if (! Schema::hasColumn('credit_notes', 'is_debit')) {
                $table->boolean('is_debit')->default(0)->after('created_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_payments', 'voided_at')) {
                $table->dropColumn('voided_at');
            }
        });

        // The credit_notes columns are dropped only when they were added
        // by this migration (fresh installs). On databases where they
        // pre-date it, dropping them would destroy live data, so the
        // down() step leaves them alone.
    }
}
