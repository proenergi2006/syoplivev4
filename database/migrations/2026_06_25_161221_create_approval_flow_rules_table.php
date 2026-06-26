<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flow_rules', function (Blueprint $table) {
            $table->id();

            /*
             * Approval flow yang akan digunakan apabila
             * kondisi rule ini cocok.
             */
            $table
                ->foreignId('approval_flow_id')
                ->constrained('approval_flows')
                ->cascadeOnDelete();

            /*
             * Role user yang membuat atau submit PR.
             */
            $table
                ->foreignId('requester_role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            /*
             * Kondisi tambahan.
             *
             * Nullable berarti rule berlaku untuk seluruh
             * cabang atau seluruh departemen.
             */
            $table
                ->foreignId('cabang_id')
                ->nullable()
                ->constrained('cabang')
                ->nullOnDelete();

            $table
                ->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            /*
             * Batas nominal bersifat opsional.
             *
             * Jika keduanya null, rule berlaku untuk
             * nominal berapa pun.
             */
            $table
                ->decimal('min_amount', 18, 2)
                ->nullable();

            $table
                ->decimal('max_amount', 18, 2)
                ->nullable();

            /*
             * Semakin besar priority, semakin didahulukan.
             *
             * Contoh:
             * - rule khusus role: 100
             * - rule umum: 10
             */
            $table
                ->unsignedInteger('priority')
                ->default(100);

            $table
                ->boolean('is_active')
                ->default(true);

            $table
                ->string('notes')
                ->nullable();

            $table->timestamps();

            $table->index(
                [
                    'requester_role_id',
                    'is_active',
                    'priority',
                ],
                'approval_flow_rules_role_active_priority_idx',
            );

            $table->index(
                [
                    'cabang_id',
                    'department_id',
                ],
                'approval_flow_rules_area_idx',
            );

            $table->index(
                [
                    'min_amount',
                    'max_amount',
                ],
                'approval_flow_rules_amount_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_flow_rules');
    }
};
