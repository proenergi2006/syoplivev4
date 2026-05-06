<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_cabang', function (Blueprint $table) {
            $table->id();
            $table->string('group_wilayah', 50);
            $table->boolean('is_active')->default(true);

            $table->timestamp('created_time')->useCurrent();
            $table->string('created_ip', 45)->nullable();
            $table->string('created_by', 50)->nullable();

            $table->timestamp('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 45)->nullable();
            $table->string('lastupdate_by', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_cabang');
    }
};
