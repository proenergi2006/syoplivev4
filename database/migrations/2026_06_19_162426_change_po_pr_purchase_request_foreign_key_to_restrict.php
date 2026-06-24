<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_pr', function (Blueprint $table) {
            $table->dropForeign([
                'purchase_request_id',
            ]);

            $table->foreign('purchase_request_id')
                ->references('id')
                ->on('purchase_requests')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('po_pr', function (Blueprint $table) {
            $table->dropForeign([
                'purchase_request_id',
            ]);

            $table->foreign('purchase_request_id')
                ->references('id')
                ->on('purchase_requests')
                ->cascadeOnDelete();
        });
    }
};
