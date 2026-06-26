<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'purchase_requests',
            function (Blueprint $table): void {
                /*
                 * Role aktif requester ketika PR disubmit.
                 *
                 * Nullable agar PR lama tetap valid.
                 */
                $table
                    ->foreignId('requester_role_id')
                    ->nullable()
                    ->constrained('roles')
                    ->nullOnDelete();

                /*
                 * Snapshot nama role untuk kebutuhan audit.
                 *
                 * Tetap disimpan meskipun nama role pada master
                 * nantinya berubah.
                 */
                $table
                    ->string(
                        'requester_role_name_snapshot',
                        150,
                    )
                    ->nullable();
            },
        );
    }

    public function down(): void
    {
        Schema::table(
            'purchase_requests',
            function (Blueprint $table): void {
                $table->dropConstrainedForeignId(
                    'requester_role_id',
                );

                $table->dropColumn(
                    'requester_role_name_snapshot',
                );
            },
        );
    }
};
