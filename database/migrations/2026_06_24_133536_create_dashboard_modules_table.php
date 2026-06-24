<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_modules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dashboard_module_group_id')
                ->constrained('dashboard_module_groups')
                ->cascadeOnDelete();

            $table->string('code', 100)->unique();
            $table->string('title', 150);
            $table->string('short_title', 50)->nullable();

            $table->text('description')->nullable();

            $table->string('icon')->nullable();
            $table->string('color', 30)->default('primary');

            /*
             * Contoh:
             * /dashboards/purchase-order
             */
            $table->string('route_path')->nullable();

            /*
             * Contoh:
             * dashboard.po.view
             */
            $table->string('permission_name')->nullable();

            $table->json('features')->nullable();

            /*
             * is_active:
             * Menentukan apakah modul ditampilkan sama sekali.
             *
             * is_available:
             * Modul tetap terlihat, tetapi card belum bisa dibuka.
             */
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index([
                'dashboard_module_group_id',
                'is_active',
                'sort_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_modules');
    }
};
