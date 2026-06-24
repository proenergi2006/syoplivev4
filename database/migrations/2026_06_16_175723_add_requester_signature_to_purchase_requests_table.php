<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table
                ->unsignedBigInteger('requester_signed_by')
                ->nullable()
                ->after('submitted_by');

            $table
                ->string('requester_signature_path')
                ->nullable()
                ->after('requester_signed_by');

            $table
                ->timestamp('requester_signed_at')
                ->nullable()
                ->after('requester_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn([
                'requester_signed_by',
                'requester_signature_path',
                'requester_signed_at',
            ]);
        });
    }
};
