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
        Schema::create('inventory_vendor_po_history', function (Blueprint $table) {
           $table->bigInteger('id_master')->primary();

            $table->integer('id_accurate')->nullable();

            $table->unsignedBigInteger('id_po_supplier');
            $table->unsignedBigInteger('id_vendor');
            $table->unsignedBigInteger('id_produk');
            $table->unsignedBigInteger('id_terminal');

            $table->string('nomor_po', 300)->nullable();

            $table->date('tanggal_inven');

            $table->decimal('volume_po', 22, 4)->default(0.0000);
            $table->decimal('harga_po', 22, 4)->default(0.0000);
            $table->decimal('harga_tebus', 22, 4)->default(0.0000);

            $table->dateTime('created_time');
            $table->string('created_ip', 20);
            $table->string('created_by', 50);

            $table->dateTime('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 20)->nullable();
            $table->string('lastupdate_by', 50)->nullable();

            $table->string('kd_tax', 10)->nullable();
            $table->string('terms', 10)->nullable();
            $table->string('terms_day', 10)->nullable();

            $table->string('kategori_oa', 1)->nullable()->default('1');

            $table->integer('is_biaya')->nullable()->default(0);

            $table->string('kategori_plat', 50)->nullable();

            $table->decimal('ongkos_angkut', 22, 4)->nullable()->default(0.0000);
            $table->decimal('subtotal', 22, 4)->nullable()->default(0.0000);
            $table->decimal('ppn_11', 22, 4)->nullable()->default(0.0000);
            $table->decimal('ppn_12', 22, 4)->nullable()->default(0.0000);
            $table->decimal('dpp_11_12', 22, 4)->nullable()->default(0.0000);
            $table->decimal('pph_22', 22, 4)->nullable()->default(0.0000);
            $table->decimal('pbbkb_po', 22, 4)->nullable()->default(0.0000);

            $table->string('iuran_migas', 1)->nullable();

            $table->float('nilai_pbbkb')->nullable()->default(0);

            $table->decimal('nominal_migas', 22, 4)->default(0.0000);

            $table->decimal('pbbkb', 22, 4)->nullable()->default(0.0000);

            $table->decimal('total_order', 22, 4)->nullable();

            $table->text('keterangan')->nullable();

            $table->integer('is_close')->nullable()->default(0);
            $table->integer('is_cancel')->nullable()->default(0);

            $table->text('keterangan_cancel')->nullable();

            $table->date('tanggal_close')->nullable();

            $table->integer('volume_close')->nullable()->default(0);

            $table->tinyInteger('disposisi_po')->nullable()->default(0);

            $table->tinyInteger('cfo_result')->nullable()->default(0);
            $table->string('cfo_pic', 80)->nullable();
            $table->dateTime('cfo_tanggal')->nullable();
            $table->text('cfo_summary')->nullable();

            $table->tinyInteger('ceo_result')->nullable()->default(0);
            $table->string('ceo_pic', 80)->nullable();
            $table->dateTime('ceo_tanggal')->nullable();
            $table->text('ceo_summary')->nullable();

            $table->tinyInteger('revert_cfo')->nullable()->default(0);
            $table->text('revert_cfo_summary')->nullable();

            $table->tinyInteger('revert_ceo')->nullable();
            $table->text('revert_ceo_summary')->nullable();

            $table->decimal('volume_ri', 22, 4)->default(0.0000);

            $table->date('resubmission_date')->nullable();

            $table->tinyInteger('is_resubmission')->nullable()->default(0);
            $table->tinyInteger('resubmission_count')->nullable()->default(0);

            $table->tinyInteger('jenis_kirim')->nullable()->default(0);

            $table->text('internal_notes')->nullable();
            $table->tinyInteger('jenis_harga')->nullable()->default(0);
            $table->tinyInteger('is_price_changed')->nullable()->default(0);
            $table->text('keterangan_resubmission')->nullable();

            // INDEX
            $table->index('id_po_supplier', 'new_pro_inventory_vendor_po_idx1');
            $table->index('id_vendor', 'new_pro_inventory_vendor_po_idx4');
            $table->index('id_produk', 'new_pro_inventory_vendor_po_idx2');
            $table->index('id_terminal', 'new_pro_inventory_vendor_po_idx3');

            // FOREIGN KEY
            $table->foreign('id_po_supplier', 'new_pro_inventory_vendor_po_fk1')
                ->references('id')
                ->on('inventory_vendor_po')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreign('id_vendor', 'new_pro_inventory_vendor_po_fk1')
                ->references('id')
                ->on('master_vendor')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('id_produk', 'new_pro_inventory_vendor_po_fk2')
                ->references('id')
                ->on('produk')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('id_terminal', 'new_pro_inventory_vendor_po_fk3')
                ->references('id')
                ->on('terminal')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('inventory_vendor_po_history');
    }
};
