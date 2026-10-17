<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_efectivo', function (Blueprint $table) {
            $table->boolean('es_saldo_arrastrado')->default(false)->after('tipo');
        });

        // Los saldos iniciales creados por cortes anteriores son arrastres; el más antiguo
        // de cada usuario es el efectivo real registrado en el wizard.
        $categorias = DB::table('categorias_personales')->where('nombre', 'Saldo inicial')->pluck('id');
        foreach ($categorias as $categoriaId) {
            $primero = DB::table('movimientos_efectivo')->where('categoria_personal_id', $categoriaId)
                ->orderBy('fecha')->orderBy('id')->value('id');
            DB::table('movimientos_efectivo')->where('categoria_personal_id', $categoriaId)
                ->where('id', '!=', $primero)->update(['es_saldo_arrastrado' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('movimientos_efectivo', function (Blueprint $table) {
            $table->dropColumn('es_saldo_arrastrado');
        });
    }
};
