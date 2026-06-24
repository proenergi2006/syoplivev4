<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE master_vendor
            ALTER COLUMN status_approval SET DEFAULT 'DRAFT'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE master_vendor
            ALTER COLUMN status_approval SET DEFAULT 'PENDING REVIEW'
        ");
    }
};
