<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_order_items', 'purchase_request_item_id')) {
                $table->unsignedBigInteger('purchase_request_item_id')
                    ->nullable()
                    ->after('purchase_order_id');
            }
        });

        DB::statement("
            ALTER TABLE purchase_order_items
            ALTER COLUMN qty TYPE NUMERIC(18,2)
            USING qty::NUMERIC(18,2)
        ");

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreign('purchase_request_item_id')
                ->references('id')
                ->on('purchase_request_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['purchase_request_item_id']);
            $table->dropColumn('purchase_request_item_id');
        });

        DB::statement("
            ALTER TABLE purchase_order_items
            ALTER COLUMN qty TYPE INTEGER
            USING qty::INTEGER
        ");
    }
};
