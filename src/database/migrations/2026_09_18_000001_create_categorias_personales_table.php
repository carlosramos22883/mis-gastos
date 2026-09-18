<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categorias_personales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->string('tipo', 20)->default('egreso');
            $table->string('color', 7)->default('#64748B');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'nombre', 'tipo']);
            $table->index(['user_id', 'tipo', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_personales');
    }
};
