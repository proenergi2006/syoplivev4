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
        Schema::create('purchase_order_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->cascadeOnDelete();

            $table->integer('step_order');

            $table->string('approver_type', 50)->default('USER');
            $table->unsignedBigInteger('approver_id');
            $table->string('approver_name_snapshot')->nullable();

            $table->string('label')->nullable();
            $table->string('status', 50)->default('PENDING'); // PENDING / APPROVED / REJECTED

            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['purchase_order_id', 'status']);
            $table->index(['approver_type', 'approver_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('purchase_order_approvals');
    }
};
