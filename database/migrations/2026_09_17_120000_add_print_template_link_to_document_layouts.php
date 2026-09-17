<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_layouts', function (Blueprint $table) {
            $table->string('base_template', 64)->nullable()->after('name');
            $table->string('print_template_key', 64)->nullable()->after('base_template');
            $table->unique(['created_by', 'document_type', 'print_template_key'], 'dl_creator_type_print_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('document_layouts', function (Blueprint $table) {
            $table->dropUnique('dl_creator_type_print_key_unique');
            $table->dropColumn(['base_template', 'print_template_key']);
        });
    }
};
