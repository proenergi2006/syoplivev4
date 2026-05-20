<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->decimal('qty_po', 18, 2)
                ->default(0)
                ->after('qty');

            $table->decimal('qty_outstanding', 18, 2)
                ->default(0)
                ->after('qty_po');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropColumn([
                'qty_po',
                'qty_outstanding',
            ]);
        });
    }
};
