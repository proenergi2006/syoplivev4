<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Drop field lama yang sudah tidak diperlukan
            |--------------------------------------------------------------------------
            */
            if (Schema::hasColumn('purchase_requests', 'requested_by')) {
                $table->dropColumn('requested_by');
            }

            if (Schema::hasColumn('purchase_requests', 'request_date')) {
                $table->dropColumn('request_date');
            }

            if (Schema::hasColumn('purchase_requests', 'current_level')) {
                $table->dropColumn('current_level');
            }
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_requests', 'pr_type')) {
                $table->string('pr_type', 50)
                    ->nullable()
                    ->after('kategori');
            }

            /*
            |--------------------------------------------------------------------------
            | Approval summary fields
            |--------------------------------------------------------------------------
            */
            if (!Schema::hasColumn('purchase_requests', 'final_approved_by')) {
                $table->unsignedBigInteger('final_approved_by')
                    ->nullable()
                    ->after('final_approved_at');
            }

            if (!Schema::hasColumn('purchase_requests', 'rejected_at')) {
                $table->timestamp('rejected_at')
                    ->nullable()
                    ->after('final_approved_by');
            }

            if (!Schema::hasColumn('purchase_requests', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')
                    ->nullable()
                    ->after('rejected_at');
            }

            if (!Schema::hasColumn('purchase_requests', 'rejected_notes')) {
                $table->text('rejected_notes')
                    ->nullable()
                    ->after('rejected_by');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Foreign key tambahan
        |--------------------------------------------------------------------------
        */
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_requests', 'final_approved_by')) {
                try {
                    $table->foreign('final_approved_by')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                } catch (\Throwable $e) {
                    //
                }
            }

            if (Schema::hasColumn('purchase_requests', 'rejected_by')) {
                try {
                    $table->foreign('rejected_by')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                } catch (\Throwable $e) {
                    //
                }
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Rapihkan default status
        |--------------------------------------------------------------------------
        */
        DB::table('purchase_requests')
            ->whereNull('status')
            ->update([
                'status' => 'DRAFT',
            ]);

        DB::table('purchase_requests')
            ->whereNull('status_po')
            ->update([
                'status_po' => 'OPEN',
            ]);
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            try {
                $table->dropForeign(['final_approved_by']);
            } catch (\Throwable $e) {
                //
            }

            try {
                $table->dropForeign(['rejected_by']);
            } catch (\Throwable $e) {
                //
            }
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_requests', 'rejected_notes')) {
                $table->dropColumn('rejected_notes');
            }

            if (Schema::hasColumn('purchase_requests', 'rejected_by')) {
                $table->dropColumn('rejected_by');
            }

            if (Schema::hasColumn('purchase_requests', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }

            if (Schema::hasColumn('purchase_requests', 'final_approved_by')) {
                $table->dropColumn('final_approved_by');
            }

            if (Schema::hasColumn('purchase_requests', 'pr_type')) {
                $table->dropColumn('pr_type');
            }
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_requests', 'requested_by')) {
                $table->string('requested_by', 150)->nullable();
            }

            if (!Schema::hasColumn('purchase_requests', 'request_date')) {
                $table->date('request_date')->nullable();
            }

            if (!Schema::hasColumn('purchase_requests', 'current_level')) {
                $table->integer('current_level')->default(1);
            }
        });
    }
};
