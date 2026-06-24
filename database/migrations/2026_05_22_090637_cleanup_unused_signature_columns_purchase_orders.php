<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {

            // hapus jika memang ada
            if (Schema::hasColumn('purchase_orders', 'requester_signature')) {
                $table->dropColumn('requester_signature');
            }

            if (Schema::hasColumn('purchase_orders', 'requester_agreement')) {
                $table->dropColumn('requester_agreement');
            }

            if (Schema::hasColumn('purchase_orders', 'signature_base64')) {
                $table->dropColumn('signature_base64');
            }
        });

        Schema::table('purchase_order_approvals', function (Blueprint $table) {

            if (Schema::hasColumn('purchase_order_approvals', 'approver_signature')) {
                $table->dropColumn('approver_signature');
            }

            if (Schema::hasColumn('purchase_order_approvals', 'approval_agreement')) {
                $table->dropColumn('approval_agreement');
            }

            if (Schema::hasColumn('purchase_order_approvals', 'signature_base64')) {
                $table->dropColumn('signature_base64');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {

            $table->longText('requester_signature')->nullable();
            $table->boolean('requester_agreement')->default(false);
            $table->longText('signature_base64')->nullable();
        });

        Schema::table('purchase_order_approvals', function (Blueprint $table) {

            $table->longText('approver_signature')->nullable();
            $table->boolean('approval_agreement')->default(false);
            $table->longText('signature_base64')->nullable();
        });
    }
};
