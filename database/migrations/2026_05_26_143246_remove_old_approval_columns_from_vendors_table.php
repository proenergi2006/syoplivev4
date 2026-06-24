<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->dropColumn([
                'approval_note',
                'approved_by',
                'approved_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->text('approval_note')->nullable();

            $table->unsignedBigInteger('approved_by')
                ->nullable();

            $table->timestamp('approved_at')
                ->nullable();
        });
    }
};
