<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('goods_return_reasons', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Kode alasan retur
            |--------------------------------------------------------------------------
            | Contoh:
            | DAMAGED
            | WRONG_ITEM
            | WRONG_SPECIFICATION
            | QUALITY_ISSUE
            | INCOMPLETE
            | OTHER
            |--------------------------------------------------------------------------
            */
            $table
                ->string('code', 50)
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Nama alasan
            |--------------------------------------------------------------------------
            */
            $table
                ->string('name', 150);

            /*
            |--------------------------------------------------------------------------
            | Penjelasan tambahan
            |--------------------------------------------------------------------------
            */
            $table
                ->text('description')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Status aktif
            |--------------------------------------------------------------------------
            | Hanya alasan aktif yang ditampilkan pada form retur.
            |--------------------------------------------------------------------------
            */
            $table
                ->boolean('is_active')
                ->default(true)
                ->index();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Index pencarian
            |--------------------------------------------------------------------------
            */
            $table->index([
                'is_active',
                'name',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_return_reasons');
    }
};
