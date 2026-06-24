<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_vendor_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')
                ->constrained('master_vendor')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Approval Flow
            |--------------------------------------------------------------------------
            */
            $table->foreignId('approval_flow_id')
                ->nullable()
                ->constrained('approval_flows')
                ->nullOnDelete();

            $table->foreignId('approval_flow_step_id')
                ->nullable()
                ->constrained('approval_flow_steps')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Approval Step
            |--------------------------------------------------------------------------
            */
            $table->unsignedInteger('step_order')
                ->default(1);

            /*
            |--------------------------------------------------------------------------
            | Approver
            |--------------------------------------------------------------------------
            */
            $table->enum('approver_type', [
                'USER',
                'ROLE',
            ])->default('USER');

            $table->unsignedBigInteger('approver_id')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Approval Status
            |--------------------------------------------------------------------------
            */
            $table->enum('status', [
                'PENDING',
                'APPROVED',
                'REJECTED',
                'CANCELLED',
            ])->default('PENDING');

            /*
            |--------------------------------------------------------------------------
            | Snapshot
            |--------------------------------------------------------------------------
            */
            $table->string('approver_name_snapshot')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */
            $table->text('notes')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Action Dates
            |--------------------------------------------------------------------------
            */
            $table->timestamp('approved_at')
                ->nullable();

            $table->timestamp('rejected_at')
                ->nullable();

            $table->timestamp('cancelled_at')
                ->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Index
            |--------------------------------------------------------------------------
            */
            $table->index([
                'vendor_id',
                'status',
            ]);

            $table->index([
                'approver_type',
                'approver_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_vendor_approvals');
    }
};
