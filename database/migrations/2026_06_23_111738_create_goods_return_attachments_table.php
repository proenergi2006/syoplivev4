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
        Schema::create('goods_return_attachments', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Dokumen retur
            |--------------------------------------------------------------------------
            */
            $table
                ->foreignId('goods_return_id')
                ->constrained('goods_returns')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Jenis dokumen
            |--------------------------------------------------------------------------
            | Contoh:
            | FOTO_BARANG
            | SURAT_JALAN_RETUR
            | BERITA_ACARA
            | DOKUMEN_LAINNYA
            |--------------------------------------------------------------------------
            */
            $table
                ->string('document_type', 100)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Informasi file
            |--------------------------------------------------------------------------
            */
            $table
                ->string('file_name', 255);

            $table
                ->string('file_original_name', 255);

            $table
                ->text('file_path');

            $table
                ->string('file_mime_type', 150)
                ->nullable();

            $table
                ->unsignedBigInteger('file_size')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audit upload
            |--------------------------------------------------------------------------
            */
            $table
                ->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                [
                    'goods_return_id',
                    'document_type',
                ],
                'goods_return_attachments_return_type_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_return_attachments');
    }
};
