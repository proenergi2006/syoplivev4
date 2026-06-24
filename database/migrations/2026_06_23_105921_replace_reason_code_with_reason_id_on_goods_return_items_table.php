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
        Schema::table('goods_return_items', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Hapus reason_code lama
            |--------------------------------------------------------------------------
            */
            $table->dropColumn('reason_code');
        });

        Schema::table('goods_return_items', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Relasi ke master alasan retur
            |--------------------------------------------------------------------------
            */
            $table
                ->foreignId('reason_id')
                ->after('qty_returnable_after')
                ->constrained('goods_return_reasons')
                ->restrictOnDelete();

            $table->index(
                [
                    'reason_id',
                    'goods_receive_item_id',
                ],
                'goods_return_items_reason_receive_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_return_items', function (Blueprint $table) {
            $table->dropIndex(
                'goods_return_items_reason_receive_index',
            );

            $table->dropForeign([
                'reason_id',
            ]);

            $table->dropColumn('reason_id');
        });

        Schema::table('goods_return_items', function (Blueprint $table) {
            $table
                ->string('reason_code', 50)
                ->nullable();
        });
    }
};
