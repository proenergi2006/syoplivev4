<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_vendor_approvals', function (Blueprint $table) {
            if (!Schema::hasColumn('master_vendor_approvals', 'approval_mode')) {
                $table->string('approval_mode', 20)
                    ->default('ANY')
                    ->after('approver_name_snapshot');
            }

            if (!Schema::hasColumn('master_vendor_approvals', 'label')) {
                $table->string('label')
                    ->nullable()
                    ->after('approval_mode');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL check constraint status
        |--------------------------------------------------------------------------
        */
        DB::statement('
            ALTER TABLE master_vendor_approvals
            DROP CONSTRAINT IF EXISTS master_vendor_approvals_status_check
        ');

        DB::statement("
            ALTER TABLE master_vendor_approvals
            ADD CONSTRAINT master_vendor_approvals_status_check
            CHECK (
                status IN (
                    'PENDING',
                    'WAITING',
                    'APPROVED',
                    'REJECTED',
                    'SKIPPED',
                    'CANCELLED'
                )
            )
        ");

        /*
        |--------------------------------------------------------------------------
        | Approval mode constraint
        |--------------------------------------------------------------------------
        */
        DB::statement('
            ALTER TABLE master_vendor_approvals
            DROP CONSTRAINT IF EXISTS master_vendor_approvals_approval_mode_check
        ');

        DB::statement("
            ALTER TABLE master_vendor_approvals
            ADD CONSTRAINT master_vendor_approvals_approval_mode_check
            CHECK (
                approval_mode IN ('ANY', 'ALL')
            )
        ");

        /*
        |--------------------------------------------------------------------------
        | Index pencarian approval aktif
        |--------------------------------------------------------------------------
        */
        DB::statement('
            CREATE INDEX IF NOT EXISTS mva_vendor_step_status_idx
            ON master_vendor_approvals (
                vendor_id,
                step_order,
                status
            )
        ');

        DB::statement('
            CREATE INDEX IF NOT EXISTS mva_approver_status_idx
            ON master_vendor_approvals (
                approver_type,
                approver_id,
                status
            )
        ');
    }

    public function down(): void
    {
        DB::statement('
            DROP INDEX IF EXISTS mva_vendor_step_status_idx
        ');

        DB::statement('
            DROP INDEX IF EXISTS mva_approver_status_idx
        ');

        DB::statement('
            ALTER TABLE master_vendor_approvals
            DROP CONSTRAINT IF EXISTS master_vendor_approvals_approval_mode_check
        ');

        DB::statement('
            ALTER TABLE master_vendor_approvals
            DROP CONSTRAINT IF EXISTS master_vendor_approvals_status_check
        ');

        DB::statement("
            ALTER TABLE master_vendor_approvals
            ADD CONSTRAINT master_vendor_approvals_status_check
            CHECK (
                status IN (
                    'PENDING',
                    'APPROVED',
                    'REJECTED',
                    'CANCELLED'
                )
            )
        ");

        Schema::table('master_vendor_approvals', function (Blueprint $table) {
            if (Schema::hasColumn('master_vendor_approvals', 'label')) {
                $table->dropColumn('label');
            }

            if (Schema::hasColumn('master_vendor_approvals', 'approval_mode')) {
                $table->dropColumn('approval_mode');
            }
        });
    }
};
