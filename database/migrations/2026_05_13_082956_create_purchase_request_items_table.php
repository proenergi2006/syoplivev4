<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('purchase_request_id');

            $table->string('nama_item');
            $table->integer('qty')->default(1);

            $table->string('satuan')->nullable();

            $table->text('spesifikasi')->nullable();
            $table->text('keterangan')->nullable();

            $table->decimal('harga_unit', 18, 2)->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('purchase_request_id')
                ->references('id')
                ->on('purchase_requests')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropForeign(['purchase_request_id']);
        });

        Schema::dropIfExists('purchase_request_items');
    }
};
