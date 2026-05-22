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
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('requester_signature_path')->nullable()->after('submitted_at');
            $table->timestamp('requester_signed_at')->nullable()->after('requester_signature_path');
            $table->unsignedBigInteger('requester_signed_by')->nullable()->after('requester_signed_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'requester_signature_path',
                'requester_signed_at',
                'requester_signed_by'
            ]);
        });
    }
};
