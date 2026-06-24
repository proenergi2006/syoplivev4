<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE purchase_request_items
            ALTER COLUMN qty TYPE NUMERIC(18,2)
            USING qty::NUMERIC(18,2)
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE purchase_request_items
            ALTER COLUMN qty TYPE INTEGER
            USING qty::INTEGER
        ");
    }
};
