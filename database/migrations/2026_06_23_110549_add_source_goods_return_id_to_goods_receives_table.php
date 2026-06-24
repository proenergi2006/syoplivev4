<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('goods_receives', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Referensi Goods Return
            |--------------------------------------------------------------------------
            | NULL  : GR penerimaan biasa.
            | Terisi: GR penerimaan barang replacement dari dokumen retur.
            |--------------------------------------------------------------------------
            */
            $table
                ->foreignId('source_goods_return_id')
                ->nullable()
                ->constrained('goods_returns')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receives', function (Blueprint $table) {
            $table->dropForeign([
                'source_goods_return_id',
            ]);

            $table->dropColumn(
                'source_goods_return_id',
            );
        });
    }
};
