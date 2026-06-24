<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receives', function (Blueprint $table) {
            $table->id();

            $table->string('nomor_gr', 100)->nullable()->unique();

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnDelete();

            $table->foreignId('vendor_id')
                ->nullable()
                ->constrained('master_vendor')
                ->nullOnDelete();

            $table->date('tanggal_gr');

            $table->enum('status', [
                'DRAFT',
                'POSTED',
                'CANCELLED',
            ])->default('DRAFT');

            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['purchase_order_id', 'status']);
            $table->index(['tanggal_gr']);
        });

        Schema::create('goods_receive_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('goods_receive_id')
                ->constrained('goods_receives')
                ->cascadeOnDelete();

            $table->foreignId('purchase_order_item_id')
                ->constrained('purchase_order_items')
                ->restrictOnDelete();

            $table->foreignId('purchase_request_item_id')
                ->nullable()
                ->constrained('purchase_request_items')
                ->nullOnDelete();

            $table->string('nama_item', 255)->nullable();
            $table->string('unit', 50)->nullable();

            $table->decimal('qty_ordered', 18, 2)->default(0);
            $table->decimal('qty_received_before', 18, 2)->default(0);
            $table->decimal('qty_receive', 18, 2)->default(0);
            $table->decimal('qty_received_after', 18, 2)->default(0);
            $table->decimal('qty_outstanding', 18, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['goods_receive_id']);
            $table->index(['purchase_order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receive_items');
        Schema::dropIfExists('goods_receives');
    }
};
