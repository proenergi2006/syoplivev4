<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_approvals', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Relation
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('purchase_request_id');
            $table->unsignedBigInteger('approval_flow_id')->nullable();
            $table->unsignedBigInteger('approval_flow_step_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Approval Step
            |--------------------------------------------------------------------------
            | Step order boleh sama untuk konsep alternatif approver.
            |
            | Contoh:
            | Step 1 Adm / ADH:
            | - step_order 1, approver ROLE Adm, approval_mode ANY
            | - step_order 1, approver ROLE ADH, approval_mode ANY
            |--------------------------------------------------------------------------
            */
            $table->integer('step_order')->default(1);
            $table->string('label', 150)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Approver
            |--------------------------------------------------------------------------
            | approver_type:
            | - USER
            | - ROLE
            |--------------------------------------------------------------------------
            */
            $table->string('approver_type', 20);
            $table->unsignedBigInteger('approver_id');
            $table->string('approver_name_snapshot', 150)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Approval Mode
            |--------------------------------------------------------------------------
            | ANY = salah satu approver dalam step cukup approve
            | ALL = semua approver dalam step wajib approve
            |--------------------------------------------------------------------------
            */
            $table->string('approval_mode', 20)->default('ANY');

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            | WAITING   = step aktif
            | PENDING   = step berikutnya
            | APPROVED  = sudah approve
            | REJECTED  = ditolak
            | SKIPPED   = dilewati karena alternatif approver sudah approve
            | CANCELLED = dibatalkan karena PR reject
            |--------------------------------------------------------------------------
            */
            $table->string('status', 30)->default('PENDING');

            /*
            |--------------------------------------------------------------------------
            | Signature & Action
            |--------------------------------------------------------------------------
            */
            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */
            $table->foreign('purchase_request_id')
                ->references('id')
                ->on('purchase_requests')
                ->cascadeOnDelete();

            $table->foreign('approval_flow_id')
                ->references('id')
                ->on('approval_flows')
                ->nullOnDelete();

            $table->foreign('approval_flow_step_id')
                ->references('id')
                ->on('approval_flow_steps')
                ->nullOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | Indexes
        |--------------------------------------------------------------------------
        */
        DB::statement('
            CREATE INDEX IF NOT EXISTS purchase_request_approvals_pr_status_idx
            ON purchase_request_approvals (purchase_request_id, status)
        ');

        DB::statement('
            CREATE INDEX IF NOT EXISTS purchase_request_approvals_pr_step_idx
            ON purchase_request_approvals (purchase_request_id, step_order)
        ');

        DB::statement('
            CREATE INDEX IF NOT EXISTS purchase_request_approvals_approver_idx
            ON purchase_request_approvals (approver_type, approver_id, status)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_approvals');
    }
};
