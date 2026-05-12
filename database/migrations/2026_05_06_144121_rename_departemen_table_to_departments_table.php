<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('departemen') && !Schema::hasTable('departments')) {
            Schema::rename('departemen', 'departments');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('departments') && !Schema::hasTable('departemen')) {
            Schema::rename('departments', 'departemen');
        }
    }
};
