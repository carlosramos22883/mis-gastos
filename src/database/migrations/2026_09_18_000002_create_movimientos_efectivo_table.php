<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Some installations created this table during the initial prototype.
        // Complete that schema instead of failing on an existing database.
        if (Schema::hasTable('movimientos_efectivo')) {
            Schema::table('movimientos_efectivo', function (Blueprint $table) {
                if (!Schema::hasColumn('movimientos_efectivo', 'categoria_personal_id')) {
                    $table->foreignId('categoria_personal_id')->nullable()->after('user_id');
                }
            });
            Schema::table('movimientos_efectivo', function (Blueprint $table) {
                try {
                    $table->foreign('categoria_personal_id')->references('id')->on('categorias_personales')->nullOnDelete();
                } catch (\Throwable) {
                    // The constraint may already exist in a partially migrated database.
                }
            });
            return;
        }
        Schema::create('movimientos_efectivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('categoria_personal_id')->constrained('categorias_personales')->restrictOnDelete();
            $table->string('descripcion', 255);
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->string('tipo', 20);
            $table->timestamps();
            $table->index(['user_id', 'fecha']);
            $table->index(['user_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_efectivo');
    }
};
