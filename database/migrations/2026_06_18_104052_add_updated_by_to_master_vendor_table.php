<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | User terakhir yang mengubah Master Vendor
            |--------------------------------------------------------------------------
            |
            | Nullable agar data lama tetap valid.
            | nullOnDelete agar penghapusan akun tidak menghapus data Vendor.
            |--------------------------------------------------------------------------
            */
            $table->foreignId('updated_by')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->dropForeign([
                'updated_by',
            ]);

            $table->dropColumn('updated_by');
        });
    }
};
