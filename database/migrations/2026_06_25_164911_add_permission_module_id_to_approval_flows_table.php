<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'approval_flows',
            function (Blueprint $table): void {
                $table
                    ->foreignId('permission_module_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('permission_modules')
                    ->nullOnDelete();

                $table->index(
                    [
                        'permission_module_id',
                        'is_active',
                    ],
                    'approval_flows_module_active_idx',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::table(
            'approval_flows',
            function (Blueprint $table): void {
                $table->dropConstrainedForeignId(
                    'permission_module_id',
                );
            },
        );
    }
};
