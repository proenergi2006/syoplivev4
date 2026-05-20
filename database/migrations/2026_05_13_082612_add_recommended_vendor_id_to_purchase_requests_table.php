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
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('recommended_vendor_id')
                ->nullable();

            $table->foreign('recommended_vendor_id')
                ->references('id')
                ->on('master_vendor')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('purchase_requests', function (Blueprint $table) {

            // Drop foreign key
            $table->dropForeign(['recommended_vendor_id']);

            // Drop column
            $table->dropColumn('recommended_vendor_id');
        });
    }
};
