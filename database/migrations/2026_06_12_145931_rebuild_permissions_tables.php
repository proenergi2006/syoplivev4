<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Drop table lama
        |--------------------------------------------------------------------------
        | Urutan penting:
        | 1. role_permissions dulu karena punya FK ke permissions dan roles
        | 2. permissions setelahnya
        |--------------------------------------------------------------------------
        */

        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');

        Schema::enableForeignKeyConstraints();

        /*
        |--------------------------------------------------------------------------
        | Recreate permissions
        |--------------------------------------------------------------------------
        */

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            $table->string('module', 100);
            $table->string('action', 50);
            $table->string('code', 150)->unique();

            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['module', 'action']);
            $table->index('is_active');
        });

        /*
        |--------------------------------------------------------------------------
        | Recreate role_permissions
        |--------------------------------------------------------------------------
        | Scope disimpan di pivot, karena permission yang sama bisa beda scope
        | tergantung role.
        |--------------------------------------------------------------------------
        */

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Scope akses data
            |--------------------------------------------------------------------------
            | NONE           = tidak punya akses data
            | OWN_DEPARTMENT = hanya department user login
            | OWN_CABANG     = hanya cabang user login
            | ALL            = semua data
            |--------------------------------------------------------------------------
            */
            $table->string('scope', 50)->default('NONE');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
            $table->index(['role_id', 'scope']);
            $table->index(['permission_id', 'scope']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');

        Schema::enableForeignKeyConstraints();
    }
};
