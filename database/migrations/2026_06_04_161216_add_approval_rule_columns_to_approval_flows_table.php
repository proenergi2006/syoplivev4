<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flows', function (Blueprint $table) {
            if (!Schema::hasColumn('approval_flows', 'document_type')) {
                $table->string('document_type', 50)
                    ->nullable()
                    ->after('module_name');
            }

            if (!Schema::hasColumn('approval_flows', 'min_amount')) {
                $table->decimal('min_amount', 18, 2)
                    ->nullable()
                    ->after('name');
            }

            if (!Schema::hasColumn('approval_flows', 'max_amount')) {
                $table->decimal('max_amount', 18, 2)
                    ->nullable()
                    ->after('min_amount');
            }

            if (!Schema::hasColumn('approval_flows', 'description')) {
                $table->text('description')
                    ->nullable()
                    ->after('max_amount');
            }

            if (!Schema::hasColumn('approval_flows', 'created_by')) {
                $table->unsignedBigInteger('created_by')
                    ->nullable()
                    ->after('is_active');
            }

            if (!Schema::hasColumn('approval_flows', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')
                    ->nullable()
                    ->after('created_by');
            }

            if (!Schema::hasColumn('approval_flows', 'deleted_at')) {
                $table->softDeletes()
                    ->after('updated_at');
            }
        });

        try {
            DB::statement('CREATE INDEX IF NOT EXISTS approval_flows_document_type_is_active_index ON approval_flows (document_type, is_active)');
            DB::statement('CREATE INDEX IF NOT EXISTS approval_flows_module_document_index ON approval_flows (module_name, document_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS approval_flows_amount_range_index ON approval_flows (min_amount, max_amount)');
        } catch (\Throwable $e) {
            // Abaikan jika index sudah ada.
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS approval_flows_amount_range_index');
            DB::statement('DROP INDEX IF EXISTS approval_flows_module_document_index');
            DB::statement('DROP INDEX IF EXISTS approval_flows_document_type_is_active_index');
        } catch (\Throwable $e) {
            // Abaikan jika index tidak ada.
        }

        Schema::table('approval_flows', function (Blueprint $table) {
            if (Schema::hasColumn('approval_flows', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('approval_flows', 'updated_by')) {
                $table->dropColumn('updated_by');
            }

            if (Schema::hasColumn('approval_flows', 'created_by')) {
                $table->dropColumn('created_by');
            }

            if (Schema::hasColumn('approval_flows', 'description')) {
                $table->dropColumn('description');
            }

            if (Schema::hasColumn('approval_flows', 'max_amount')) {
                $table->dropColumn('max_amount');
            }

            if (Schema::hasColumn('approval_flows', 'min_amount')) {
                $table->dropColumn('min_amount');
            }

            if (Schema::hasColumn('approval_flows', 'document_type')) {
                $table->dropColumn('document_type');
            }
        });
    }
};
