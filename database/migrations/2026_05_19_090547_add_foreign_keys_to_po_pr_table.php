<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_pr', function (Blueprint $table) {
            $table->foreign('purchase_request_id')
                ->references('id')
                ->on('purchase_requests')
                ->cascadeOnDelete();

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->cascadeOnDelete();

            $table->unique(
                ['purchase_request_id', 'purchase_order_id'],
                'po_pr_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('po_pr', function (Blueprint $table) {
            $table->dropUnique('po_pr_unique');
            $table->dropForeign(['purchase_request_id']);
            $table->dropForeign(['purchase_order_id']);
        });
    }
};
