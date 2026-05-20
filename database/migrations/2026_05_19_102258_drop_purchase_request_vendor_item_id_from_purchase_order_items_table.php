<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE purchase_order_items
            DROP CONSTRAINT IF EXISTS purchase_order_items_purchase_request_vendor_item_id_foreign
        ");

        DB::statement("
            ALTER TABLE purchase_order_items
            DROP COLUMN IF EXISTS purchase_request_vendor_item_id
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE purchase_order_items
            ADD COLUMN IF NOT EXISTS purchase_request_vendor_item_id BIGINT NULL
        ");
    }
};
