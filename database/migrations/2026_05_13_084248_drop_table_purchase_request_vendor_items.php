<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop child table terlebih dahulu
        Schema::dropIfExists('pr_vendor_offer_attachments');

        // Drop child table terlebih dahulu
        Schema::dropIfExists('purchase_request_vendor_items');

        // Baru drop parent table
        Schema::dropIfExists('purchase_request_vendors');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('purchase_request_vendors', function ($table) {
            $table->id();

            $table->unsignedBigInteger('purchase_request_id');
            $table->unsignedBigInteger('vendor_id');

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('purchase_request_id')
                ->references('id')
                ->on('purchase_requests')
                ->cascadeOnDelete();

            $table->foreign('vendor_id')
                ->references('id')
                ->on('master_vendors')
                ->cascadeOnDelete();
        });

        Schema::create('purchase_request_vendor_items', function ($table) {
            $table->id();

            $table->unsignedBigInteger('pr_vendor_id');

            $table->string('nama_item');
            $table->integer('qty')->default(1);

            $table->string('satuan')->nullable();

            $table->text('spesifikasi')->nullable();
            $table->text('keterangan')->nullable();

            $table->decimal('harga_unit', 18, 2)->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('pr_vendor_id')
                ->references('id')
                ->on('purchase_request_vendors')
                ->cascadeOnDelete();
        });

        Schema::create('pr_vendor_offer_attachments', function ($table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('pr_vendor_offer_id');

            $table->string('filename', 255);
            $table->string('filepath', 255);
            $table->integer('filesize')->nullable();
            $table->string('filetype', 50)->nullable();

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();

            $table->foreign(
                'pr_vendor_offer_id',
                'pr_vendor_offer_attachments_pr_vendor_offer_id_foreign'
            )
                ->references('id')
                ->on('purchase_request_vendors')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }
};
