<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->string('approver_scope', 30)
                ->default('GLOBAL');
        });
    }

    public function down(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->dropColumn('approver_scope');
        });
    }
};
