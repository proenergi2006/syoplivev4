<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_jenis_pembayaran_check');

        DB::statement('ALTER TABLE purchase_orders DROP COLUMN IF EXISTS jenis_pembayaran');

        DB::statement('ALTER TABLE purchase_orders DROP COLUMN IF EXISTS top');
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE purchase_orders
            ADD COLUMN IF NOT EXISTS jenis_pembayaran VARCHAR(255) NOT NULL DEFAULT 'CREDIT'
        ");

        DB::statement("
            ALTER TABLE purchase_orders
            ADD COLUMN IF NOT EXISTS top INTEGER NULL
        ");

        DB::statement("
            ALTER TABLE purchase_orders
            ADD CONSTRAINT purchase_orders_jenis_pembayaran_check
            CHECK (jenis_pembayaran IN ('CREDIT', 'CBD', 'COD'))
        ");
    }
};
