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
        Schema::create('goods_returns', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Nomor dokumen retur
            |--------------------------------------------------------------------------
            | Boleh null ketika masih draft apabila nomor baru dibuat saat posting.
            */
            $table
                ->string('nomor_return', 100)
                ->nullable()
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Referensi dokumen asal
            |--------------------------------------------------------------------------
            | Satu GR dapat mempunyai lebih dari satu retur.
            */
            $table
                ->foreignId('goods_receive_id')
                ->constrained('goods_receives')
                ->restrictOnDelete();

            $table
                ->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Vendor
            |--------------------------------------------------------------------------
            */
            $table
                ->foreignId('vendor_id')
                ->nullable()
                ->constrained('master_vendor')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Snapshot organisasi dari GR asal
            |--------------------------------------------------------------------------
            | Penamaan mengikuti goods_receives dan purchase_orders yang sekarang.
            */
            $table
                ->bigInteger('cabang')
                ->index();

            $table
                ->foreignId('id_department')
                ->constrained('departments')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Informasi retur
            |--------------------------------------------------------------------------
            */
            $table->date('tanggal_return');

            $table
                ->string('status', 30)
                ->default('DRAFT')
                ->index();

            $table
                ->text('notes')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audit creator
            |--------------------------------------------------------------------------
            */
            $table
                ->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Audit posting
            |--------------------------------------------------------------------------
            */
            $table
                ->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table
                ->timestamp('posted_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audit pembatalan
            |--------------------------------------------------------------------------
            */
            $table
                ->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table
                ->timestamp('cancelled_at')
                ->nullable();

            $table
                ->text('cancel_notes')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Index pencarian
            |--------------------------------------------------------------------------
            */
            $table->index([
                'goods_receive_id',
                'status',
            ]);

            $table->index([
                'id_department',
                'status',
            ]);

            $table->index([
                'tanggal_return',
                'status',
            ]);
        });

        /*
        |--------------------------------------------------------------------------
        | Status yang diperbolehkan
        |--------------------------------------------------------------------------
        | Retur tidak menggunakan approval.
        |--------------------------------------------------------------------------
        */
        DB::statement("
            ALTER TABLE goods_returns
            ADD CONSTRAINT goods_returns_status_check
            CHECK (
                status IN (
                    'DRAFT',
                    'POSTED',
                    'CANCELLED'
                )
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_returns');
    }
};
