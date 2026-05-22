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
        Schema::create('approval_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_id')
                ->constrained('approval_flows')
                ->cascadeOnDelete();

            $table->integer('step_order');
            $table->string('approver_type', 50)->default('USER'); // USER / ROLE
            $table->unsignedBigInteger('approver_id');
            $table->string('label')->nullable(); // CEO Approval, CFO Approval
            $table->boolean('is_required')->default(true);

            $table->timestamps();

            $table->unique(['approval_flow_id', 'step_order']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('approval_flow_steps');
    }
};
