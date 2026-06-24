<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('
            ALTER TABLE purchase_requests
            ALTER COLUMN kategori DROP NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Pastikan tidak ada NULL sebelum dikembalikan menjadi NOT NULL
        |--------------------------------------------------------------------------
        */
        DB::table('purchase_requests')
            ->whereNull('kategori')
            ->update([
                'kategori' => '-',
            ]);

        DB::statement('
            ALTER TABLE purchase_requests
            ALTER COLUMN kategori SET NOT NULL
        ');
    }
};
