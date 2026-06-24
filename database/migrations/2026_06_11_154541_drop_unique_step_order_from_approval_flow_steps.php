<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Drop unique constraint approval_flow_id + step_order
        |--------------------------------------------------------------------------
        | Dibutuhkan agar 1 step bisa punya beberapa approver.
        |
        | Contoh:
        | Step 1 = Adm / ADH
        | Maka akan ada 2 row dengan approval_flow_id dan step_order yang sama.
        |--------------------------------------------------------------------------
        */
        DB::statement('
            ALTER TABLE approval_flow_steps
            DROP CONSTRAINT IF EXISTS approval_flow_steps_approval_flow_id_step_order_unique
        ');

        /*
        |--------------------------------------------------------------------------
        | Index biasa untuk performa sorting/filter
        |--------------------------------------------------------------------------
        | Ini bukan unique, jadi multiple approver dalam step yang sama tetap aman.
        |--------------------------------------------------------------------------
        */
        DB::statement('
            CREATE INDEX IF NOT EXISTS approval_flow_steps_flow_step_idx
            ON approval_flow_steps (approval_flow_id, step_order)
        ');
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Rollback
        |--------------------------------------------------------------------------
        | Hati-hati: ini akan gagal kalau sudah ada data multiple approver
        | dalam step yang sama.
        |--------------------------------------------------------------------------
        */
        DB::statement('
            DROP INDEX IF EXISTS approval_flow_steps_flow_step_idx
        ');

        DB::statement('
            ALTER TABLE approval_flow_steps
            ADD CONSTRAINT approval_flow_steps_approval_flow_id_step_order_unique
            UNIQUE (approval_flow_id, step_order)
        ');
    }
};
