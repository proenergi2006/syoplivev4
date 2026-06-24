<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE purchase_requests
            ALTER COLUMN status_po DROP DEFAULT
        ");

        DB::statement("
            ALTER TABLE purchase_requests
            ALTER COLUMN status_po DROP NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE purchase_requests
            ALTER COLUMN status_po SET DEFAULT 'OPEN'
        ");
    }
};
