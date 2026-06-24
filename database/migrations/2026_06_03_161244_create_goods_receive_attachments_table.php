<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receive_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('goods_receive_id')
                ->constrained('goods_receives')
                ->cascadeOnDelete();

            $table->string('document_type', 100)->nullable();
            $table->string('file_name', 255);
            $table->string('file_original_name', 255)->nullable();
            $table->string('file_path', 500);
            $table->string('file_mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receive_attachments');
    }
};
