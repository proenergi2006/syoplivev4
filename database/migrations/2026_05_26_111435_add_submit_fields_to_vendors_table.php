<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->timestamp('submitted_at')
                ->nullable()
                ->after('status_approval');

            $table->string('submitted_by', 255)
                ->nullable()
                ->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_at',
                'submitted_by',
            ]);
        });
    }
};
