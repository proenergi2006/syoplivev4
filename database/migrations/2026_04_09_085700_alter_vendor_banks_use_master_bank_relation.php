<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendor_banks', function (Blueprint $table) {
            // tambah dulu field baru
            $table->unsignedBigInteger('bank_id')->nullable()->after('vendor_id');
            $table->string('swift_code_snapshot', 50)->nullable()->after('alamat_bank');
        });

        Schema::table('vendor_banks', function (Blueprint $table) {
            // foreign key ke master_banks
            $table->foreign('bank_id')
                ->references('id')
                ->on('master_banks')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });

        // hapus kolom lama setelah migrasi data
        Schema::table('vendor_banks', function (Blueprint $table) {
            $table->dropColumn(['nama_bank', 'swift_code']);
        });

        // kalau semua data sudah berhasil termapping dan memang wajib
        // ubah bank_id jadi not null
        DB::statement("ALTER TABLE vendor_banks ALTER COLUMN bank_id SET NOT NULL");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('vendor_banks', function (Blueprint $table) {
            $table->string('nama_bank', 100)->nullable()->after('vendor_id');
            $table->string('swift_code', 50)->nullable()->after('alamat_bank');
        });

        Schema::table('vendor_banks', function (Blueprint $table) {
            $table->dropForeign(['bank_id']);
        });

        Schema::table('vendor_banks', function (Blueprint $table) {
            $table->dropColumn(['bank_id', 'swift_code_snapshot']);
        });
    }
};
