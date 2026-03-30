<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('notification_email', 255)->nullable()->after('email');
            $table->string('notification_phone', 64)->nullable()->after('notification_email');
            $table->boolean('notify_account_email')->default(true);
            $table->boolean('notify_account_sms')->default(false);
            $table->boolean('notify_account_whatsapp')->default(false);
            $table->boolean('notify_payment_email')->default(true);
            $table->boolean('notify_payment_sms')->default(false);
            $table->boolean('notify_payment_whatsapp')->default(false);
            $table->boolean('notify_invoice_email')->default(true);
            $table->boolean('notify_invoice_sms')->default(false);
            $table->boolean('notify_invoice_whatsapp')->default(false);
        });

        Schema::table('venders', function (Blueprint $table) {
            $table->string('notification_email', 255)->nullable()->after('email');
            $table->string('notification_phone', 64)->nullable()->after('notification_email');
            $table->boolean('notify_account_email')->default(true);
            $table->boolean('notify_account_sms')->default(false);
            $table->boolean('notify_account_whatsapp')->default(false);
            $table->boolean('notify_payment_email')->default(true);
            $table->boolean('notify_payment_sms')->default(false);
            $table->boolean('notify_payment_whatsapp')->default(false);
            $table->boolean('notify_bill_email')->default(true);
            $table->boolean('notify_bill_sms')->default(false);
            $table->boolean('notify_bill_whatsapp')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'notification_email',
                'notification_phone',
                'notify_account_email',
                'notify_account_sms',
                'notify_account_whatsapp',
                'notify_payment_email',
                'notify_payment_sms',
                'notify_payment_whatsapp',
                'notify_invoice_email',
                'notify_invoice_sms',
                'notify_invoice_whatsapp',
            ]);
        });

        Schema::table('venders', function (Blueprint $table) {
            $table->dropColumn([
                'notification_email',
                'notification_phone',
                'notify_account_email',
                'notify_account_sms',
                'notify_account_whatsapp',
                'notify_payment_email',
                'notify_payment_sms',
                'notify_payment_whatsapp',
                'notify_bill_email',
                'notify_bill_sms',
                'notify_bill_whatsapp',
            ]);
        });
    }
};
