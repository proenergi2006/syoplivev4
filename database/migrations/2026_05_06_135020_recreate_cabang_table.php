<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS cabang CASCADE');

        Schema::create('cabang', function (Blueprint $table) {

            $table->id();

            $table->foreignId('group_cabang_id')
                ->constrained('group_cabang')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('nama_cabang', 70);
            $table->string('inisial_cabang', 15);
            $table->string('inisial_segel', 20)->nullable();

            $table->string('catatan_cabang', 1000)->nullable();

            $table->integer('kode_barcode')->default(0);

            // Running Number
            $table->integer('urut_ba')->default(0);
            $table->integer('urut_spj')->default(0);
            $table->integer('urut_dn')->default(0);
            $table->integer('urut_dn_kpl')->default(0);

            $table->integer('urut_pr')->default(0);
            $table->integer('urut_po')->default(0);
            $table->integer('urut_po_kpl')->default(0);

            $table->integer('urut_ds')->default(0);
            $table->integer('urut_penawaran')->default(0);
            $table->integer('urut_oslog')->default(0);

            $table->integer('urut_lo')->default(0);
            $table->integer('urut_segel')->default(0);

            // TI
            $table->integer('urut_pr_ti')->default(0);
            $table->integer('urut_lo_ti')->default(0);
            $table->integer('urut_ds_ti')->default(0);
            $table->integer('urut_po_ti')->default(0);
            $table->integer('urut_spj_ti')->default(0);
            $table->integer('urut_dn_ti')->default(0);

            $table->integer('stok_segel')->default(0);

            $table->boolean('is_active')->default(true);

            // Audit
            $table->timestamp('created_time')->useCurrent();
            $table->string('created_ip', 45)->nullable();
            $table->string('created_by', 50)->nullable();

            $table->timestamp('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 45)->nullable();
            $table->string('lastupdate_by', 50)->nullable();

            $table->index('nama_cabang');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabang');
    }
};
