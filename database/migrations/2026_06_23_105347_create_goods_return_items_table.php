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
        Schema::create('goods_return_items', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Header retur
            |--------------------------------------------------------------------------
            | Jika dokumen retur dihapus secara permanen, detail ikut terhapus.
            */
            $table
                ->foreignId('goods_return_id')
                ->constrained('goods_returns')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Referensi item asal
            |--------------------------------------------------------------------------
            */
            $table
                ->foreignId('goods_receive_item_id')
                ->constrained('goods_receive_items')
                ->restrictOnDelete();

            $table
                ->foreignId('purchase_order_item_id')
                ->constrained('purchase_order_items')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Informasi item
            |--------------------------------------------------------------------------
            | Nama item disimpan sebagai snapshot untuk kebutuhan histori.
            */
            $table
                ->string('nama_item', 255);

            $table
                ->foreignId('unit_id')
                ->nullable()
                ->constrained('units')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Informasi kuantitas
            |--------------------------------------------------------------------------
            | qty_received:
            | Qty yang diterima pada GR sumber.
            |
            | qty_returned_before:
            | Total retur POSTED sebelumnya dari item GR yang sama.
            |
            | qty_return:
            | Qty pada dokumen retur saat ini.
            |
            | qty_returned_after:
            | qty_returned_before + qty_return.
            |
            | qty_returnable_after:
            | qty_received - qty_returned_after.
            |--------------------------------------------------------------------------
            */
            $table
                ->decimal('qty_received', 18, 4)
                ->default(0);

            $table
                ->decimal('qty_returned_before', 18, 4)
                ->default(0);

            $table
                ->decimal('qty_return', 18, 4);

            $table
                ->decimal('qty_returned_after', 18, 4)
                ->default(0);

            $table
                ->decimal('qty_returnable_after', 18, 4)
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Alasan retur
            |--------------------------------------------------------------------------
            | Contoh:
            | DAMAGED
            | WRONG_ITEM
            | WRONG_SPECIFICATION
            | QUALITY_ISSUE
            | INCOMPLETE
            | OTHER
            */
            $table
                ->string('reason_code', 50);

            $table
                ->text('reason_notes')
                ->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Satu item GR hanya boleh satu kali dalam satu dokumen retur
            |--------------------------------------------------------------------------
            */
            $table->unique(
                [
                    'goods_return_id',
                    'goods_receive_item_id',
                ],
                'goods_return_items_return_receive_unique',
            );

            $table->index(
                [
                    'goods_receive_item_id',
                    'purchase_order_item_id',
                ],
                'goods_return_items_source_index',
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Validasi kuantitas tingkat database
        |--------------------------------------------------------------------------
        */
        DB::statement("
            ALTER TABLE goods_return_items
            ADD CONSTRAINT goods_return_items_qty_check
            CHECK (
                qty_received >= 0
                AND qty_returned_before >= 0
                AND qty_return > 0
                AND qty_returned_after >= qty_returned_before
                AND qty_returned_after <= qty_received
                AND qty_returnable_after >= 0
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_return_items');
    }
};
