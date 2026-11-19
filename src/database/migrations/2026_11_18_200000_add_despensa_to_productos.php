<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Despensa, parte 2: lo que hay en casa de cada producto. "en_casa" nulo = no se lleva la cuenta (solo se marca
 * «se está acabando» o «se acabó»). "vence_el" = el vencimiento más próximo de lo que hay (solo perecederos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedSmallInteger('en_casa')->nullable()->after('avisar_cuando');
            $table->boolean('por_acabarse')->default(false)->after('en_casa');
            $table->date('vence_el')->nullable()->after('por_acabarse');
            $table->timestamp('despensa_actualizada_at')->nullable()->after('vence_el');
        });
    }

    public function down(): void
    {
        Schema::table('productos', fn (Blueprint $table) => $table->dropColumn(['en_casa', 'por_acabarse', 'vence_el', 'despensa_actualizada_at']));
    }
};
