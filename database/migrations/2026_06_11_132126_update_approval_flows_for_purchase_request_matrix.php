<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | approval_flows
        |--------------------------------------------------------------------------
        | Table ini akan dipakai bersama:
        | - PO
        | - PR
        |
        | Untuk PR, flow dicari berdasarkan:
        | - document_type
        | - area_type
        | - cabang optional
        | - creator_department_id
        | - nominal min/max
        |--------------------------------------------------------------------------
        */
        Schema::table('approval_flows', function (Blueprint $table) {
            if (!Schema::hasColumn('approval_flows', 'document_type')) {
                $table->string('document_type', 50)
                    ->default('PO')
                    ->after('id');
            }

            if (!Schema::hasColumn('approval_flows', 'area_type')) {
                $table->string('area_type', 50)
                    ->nullable()
                    ->after('document_type');
            }

            if (!Schema::hasColumn('approval_flows', 'cabang')) {
                $table->string('cabang', 100)
                    ->nullable()
                    ->after('area_type');
            }

            if (!Schema::hasColumn('approval_flows', 'creator_department_id')) {
                $table->unsignedBigInteger('creator_department_id')
                    ->nullable()
                    ->after('cabang');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | approval_flow_steps
        |--------------------------------------------------------------------------
        | approval_mode:
        | - ANY = salah satu approver dalam step cukup approve
        | - ALL = semua approver dalam step wajib approve
        |
        | Contoh:
        | Adm / ADH disimpan sebagai 2 row dengan step_order yang sama.
        | approval_mode = ANY.
        |--------------------------------------------------------------------------
        */
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            if (!Schema::hasColumn('approval_flow_steps', 'approval_mode')) {
                $table->string('approval_mode', 20)
                    ->default('ANY')
                    ->after('label');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Backfill data lama
        |--------------------------------------------------------------------------
        | Approval flow existing diasumsikan punya PO.
        |--------------------------------------------------------------------------
        */
        if (Schema::hasColumn('approval_flows', 'document_type')) {
            DB::table('approval_flows')
                ->whereNull('document_type')
                ->update([
                    'document_type' => 'PO',
                ]);
        }

        if (Schema::hasColumn('approval_flow_steps', 'approval_mode')) {
            DB::table('approval_flow_steps')
                ->whereNull('approval_mode')
                ->update([
                    'approval_mode' => 'ANY',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Index tambahan
        |--------------------------------------------------------------------------
        | Supaya pencarian approval flow PR lebih cepat.
        |--------------------------------------------------------------------------
        */
        try {
            DB::statement('
                CREATE INDEX IF NOT EXISTS approval_flows_pr_matrix_idx
                ON approval_flows (
                    document_type,
                    area_type,
                    cabang,
                    creator_department_id
                )
            ');
        } catch (\Throwable $e) {
            //
        }

        try {
            DB::statement('
                CREATE INDEX IF NOT EXISTS approval_flow_steps_order_idx
                ON approval_flow_steps (
                    approval_flow_id,
                    step_order
                )
            ');
        } catch (\Throwable $e) {
            //
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS approval_flows_pr_matrix_idx');
        } catch (\Throwable $e) {
            //
        }

        try {
            DB::statement('DROP INDEX IF EXISTS approval_flow_steps_order_idx');
        } catch (\Throwable $e) {
            //
        }

        Schema::table('approval_flow_steps', function (Blueprint $table) {
            if (Schema::hasColumn('approval_flow_steps', 'approval_mode')) {
                $table->dropColumn('approval_mode');
            }
        });

        Schema::table('approval_flows', function (Blueprint $table) {
            if (Schema::hasColumn('approval_flows', 'creator_department_id')) {
                $table->dropColumn('creator_department_id');
            }

            if (Schema::hasColumn('approval_flows', 'cabang')) {
                $table->dropColumn('cabang');
            }

            if (Schema::hasColumn('approval_flows', 'area_type')) {
                $table->dropColumn('area_type');
            }

            if (Schema::hasColumn('approval_flows', 'document_type')) {
                $table->dropColumn('document_type');
            }
        });
    }
};
