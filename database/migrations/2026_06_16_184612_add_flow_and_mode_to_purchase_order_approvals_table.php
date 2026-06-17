<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_approvals', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Snapshot approval flow
            |--------------------------------------------------------------------------
            | Nullable agar data approval PO lama tetap aman.
            |--------------------------------------------------------------------------
            */
            if (!Schema::hasColumn(
                'purchase_order_approvals',
                'approval_flow_id',
            )) {
                $table
                    ->unsignedBigInteger('approval_flow_id')
                    ->nullable()
                    ->after('purchase_order_id');
            }

            if (!Schema::hasColumn(
                'purchase_order_approvals',
                'approval_flow_step_id',
            )) {
                $table
                    ->unsignedBigInteger('approval_flow_step_id')
                    ->nullable()
                    ->after('approval_flow_id');
            }

            /*
            |--------------------------------------------------------------------------
            | Mode approval pada satu step
            |--------------------------------------------------------------------------
            | ANY:
            | Salah satu kandidat approve → kandidat lainnya SKIPPED.
            |
            | ALL:
            | Semua kandidat pada step tersebut wajib approve.
            |--------------------------------------------------------------------------
            */
            if (!Schema::hasColumn(
                'purchase_order_approvals',
                'approval_mode',
            )) {
                $table
                    ->string('approval_mode', 20)
                    ->default('ANY')
                    ->after('approver_name_snapshot');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Index untuk pencarian approval aktif
        |--------------------------------------------------------------------------
        */
        Schema::table('purchase_order_approvals', function (Blueprint $table) {
            $table->index(
                [
                    'purchase_order_id',
                    'status',
                    'step_order',
                ],
                'po_approvals_po_status_step_idx',
            );

            $table->index(
                [
                    'approver_type',
                    'approver_id',
                    'status',
                ],
                'po_approvals_approver_status_idx',
            );

            $table->index(
                'approval_flow_id',
                'po_approvals_flow_id_idx',
            );

            $table->index(
                'approval_flow_step_id',
                'po_approvals_flow_step_id_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_approvals', function (Blueprint $table) {
            $table->dropIndex(
                'po_approvals_po_status_step_idx',
            );

            $table->dropIndex(
                'po_approvals_approver_status_idx',
            );

            $table->dropIndex(
                'po_approvals_flow_id_idx',
            );

            $table->dropIndex(
                'po_approvals_flow_step_id_idx',
            );

            $table->dropColumn([
                'approval_flow_id',
                'approval_flow_step_id',
                'approval_mode',
            ]);
        });
    }
};
