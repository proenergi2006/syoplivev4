<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabang', function (Blueprint $table) {
            $table->timestamps();
        });

        // Mengisi timestamp untuk data cabang yang sudah ada.
        DB::table('cabang')
            ->whereNull('created_at')
            ->update([
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('cabang', function (Blueprint $table) {
            $table->dropTimestamps();
        });
    }
};
