<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->unsignedBigInteger('id_department')
                ->nullable();

            $table->foreign('id_department')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->dropForeign(['id_department']);
            $table->dropColumn('id_department');
        });
    }
};
