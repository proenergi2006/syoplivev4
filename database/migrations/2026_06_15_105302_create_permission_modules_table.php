<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_modules', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Identitas module
            |--------------------------------------------------------------------------
            | code harus sama dengan permissions.module.
            | Contoh: purchase_request, purchase_order, vendor
            |--------------------------------------------------------------------------
            */
            $table->string('code', 100)->unique();

            $table->string('name', 150);

            $table->text('description')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Prefix halaman frontend
            |--------------------------------------------------------------------------
            | Contoh:
            | /non_trade/purchase_request
            | /non_trade/purchase_order
            |--------------------------------------------------------------------------
            */
            $table->string('route_prefix', 255)
                ->nullable()
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Pengaturan tampilan dan status
            |--------------------------------------------------------------------------
            */
            $table->integer('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_modules');
    }
};
