<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('goods_receives', function (Blueprint $table) {
            $table
                ->bigInteger('cabang')
                ->nullable()
                ->index();

            $table
                ->bigInteger('id_department')
                ->nullable()
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receives', function (Blueprint $table) {
            $table->dropIndex([
                'cabang',
            ]);

            $table->dropIndex([
                'id_department',
            ]);

            $table->dropColumn([
                'cabang',
                'id_department',
            ]);
        });
    }
};
