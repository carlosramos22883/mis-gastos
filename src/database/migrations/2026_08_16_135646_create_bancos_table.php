<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bancos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');            
            $table->string('logo')->nullable();       // <-- AGREGADO
            $table->boolean('activo')->default(true); // <-- AGREGADO
            $table->timestamps();                     // <-- AGREGADO (Muy importante)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bancos');
    }
};