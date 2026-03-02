<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTipToPosPayments extends Migration
{
    public function up()
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->decimal('tip', 15, 2)->nullable()->default(0)->after('discount_amount');
        });
    }

    public function down()
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->dropColumn('tip');
        });
    }
}
