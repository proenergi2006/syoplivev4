<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::create('master_banks', function (Blueprint $table) {
            $table->id();
            $table->string('kode_bank', 10)->nullable()->unique();
            $table->string('nama_bank', 150)->unique();
            $table->string('nama_bank_pendek', 100)->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->string('tipe_bank', 30)->nullable(); // BUK, BUS, BPD, BPD_SYARIAH, DIGITAL, FOREIGN, etc
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('nama_bank');
            $table->index('nama_bank_pendek');
            $table->index('tipe_bank');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('master_banks');
    }
};
