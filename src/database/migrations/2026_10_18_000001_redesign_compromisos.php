<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa la regla de un compromiso recurrente (compromisos_recurrentes) de cada pago
 * concreto que genera (compromisos). Cada pago guarda el ciclo al que pertenece y su
 * número de cuota; el estado visual (vencido, antiguo, parcial) se calcula al vuelo.
 */
return new class extends Migration
{
    private const OLD_COLUMNS = [
        'recurrencia_tipo', 'recurrencia_intervalo', 'recurrencia_unidad', 'recurrencia_dia_semana',
        'recurrencia_dia_secundario', 'recurrencia_ocurrencias', 'recurrencia_hasta', 'cuotas_pagadas',
        'recurrencia_indefinida',
    ];

    public function up(): void
    {
        Schema::create('compromisos_recurrentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('categoria_personal_id')->nullable()->constrained('categorias_personales')->nullOnDelete();
            $table->string('descripcion', 255);
            $table->text('comentario')->nullable();
            $table->decimal('monto', 15, 2);
            $table->string('medio_pago', 30)->default('por_definir');
            $table->string('frecuencia', 20);
            $table->unsignedTinyInteger('dia_semana')->nullable();
            $table->unsignedTinyInteger('dia_pago')->nullable();
            $table->unsignedTinyInteger('dia_pago_2')->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->unsignedInteger('total_cuotas')->nullable();
            $table->unsignedInteger('cuotas_generadas')->default(0);
            $table->date('generado_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'activo']);
        });

        Schema::table('compromisos', function (Blueprint $table) {
            $table->foreignId('compromiso_recurrente_id')->nullable()->after('user_id')->constrained('compromisos_recurrentes')->nullOnDelete();
            $table->foreignId('categoria_personal_id')->nullable()->after('compromiso_recurrente_id')->constrained('categorias_personales')->nullOnDelete();
            $table->text('comentario')->nullable()->after('descripcion');
            $table->date('ciclo_inicio')->nullable()->after('fecha_esperada');
            $table->unsignedInteger('numero_cuota')->nullable()->after('ciclo_inicio');
            $table->unsignedInteger('total_cuotas')->nullable()->after('numero_cuota');
        });

        $this->migrateExistingData();

        Schema::table('compromisos', function (Blueprint $table) {
            $table->dropColumn(self::OLD_COLUMNS);
        });
        Schema::table('compromisos', function (Blueprint $table) {
            $table->index(['user_id', 'estado', 'ciclo_inicio']);
        });
    }

    public function down(): void
    {
        Schema::table('compromisos', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'estado', 'ciclo_inicio']);
            $table->dropConstrainedForeignId('compromiso_recurrente_id');
            $table->dropConstrainedForeignId('categoria_personal_id');
            $table->dropColumn(['comentario', 'ciclo_inicio', 'numero_cuota', 'total_cuotas']);
            $table->string('recurrencia_tipo', 20)->default('unica');
            $table->unsignedInteger('recurrencia_intervalo')->nullable();
            $table->string('recurrencia_unidad', 20)->nullable();
            $table->unsignedTinyInteger('recurrencia_dia_semana')->nullable();
            $table->unsignedTinyInteger('recurrencia_dia_secundario')->nullable();
            $table->unsignedInteger('recurrencia_ocurrencias')->nullable();
            $table->unsignedInteger('cuotas_pagadas')->default(0);
            $table->boolean('recurrencia_indefinida')->default(false);
            $table->date('recurrencia_hasta')->nullable();
        });
        DB::table('compromisos')->where('estado', 'pendiente')->update(['estado' => 'activo']);
        Schema::dropIfExists('compromisos_recurrentes');
    }

    private function migrateExistingData(): void
    {
        foreach (DB::table('compromisos')->orderBy('id')->get() as $old) {
            $user = DB::table('users')->find($old->user_id);
            $fecha = $old->fecha_esperada ? Carbon::parse($old->fecha_esperada) : Carbon::parse($old->created_at);

            if ($old->recurrencia_tipo === 'recurrente') {
                $frecuencia = in_array($old->recurrencia_unidad, ['semanal', 'quincenal'], true) ? $old->recurrencia_unidad : 'mensual';
                // En el modelo anterior "recurrencia_intervalo" guardaba el día de pago
                $diaPago = $old->recurrencia_intervalo ? min(31, max(1, (int) $old->recurrencia_intervalo)) : (int) $fecha->day;
                $recurrenteId = DB::table('compromisos_recurrentes')->insertGetId([
                    'user_id' => $old->user_id,
                    'descripcion' => $old->descripcion,
                    'monto' => $old->monto,
                    'medio_pago' => $old->medio_pago,
                    'frecuencia' => $frecuencia,
                    'dia_semana' => $frecuencia === 'semanal' ? ($old->recurrencia_dia_semana ?? $fecha->dayOfWeek) : null,
                    'dia_pago' => $frecuencia === 'semanal' ? null : $diaPago,
                    'dia_pago_2' => $frecuencia === 'quincenal' ? ($old->recurrencia_dia_secundario ?? min(31, $diaPago + 15)) : null,
                    // La plantilla vieja guardaba en fecha_esperada su próximo pago: la serie arranca ahí,
                    // salvo que el sistema anterior ya hubiera creado ese pago como compromiso suelto
                    'fecha_inicio' => $fecha->toDateString(),
                    'generado_hasta' => DB::table('compromisos')->where('user_id', $old->user_id)->where('id', '!=', $old->id)
                        ->where('descripcion', $old->descripcion)->where('recurrencia_tipo', 'unica')
                        ->whereDate('fecha_esperada', $fecha->toDateString())->exists() ? $fecha->toDateString() : null,
                    'fecha_fin' => $old->recurrencia_hasta,
                    'total_cuotas' => $old->recurrencia_indefinida ? null : $old->recurrencia_ocurrencias,
                    'cuotas_generadas' => (int) $old->cuotas_pagadas,
                    'activo' => $old->estado === 'activo',
                    'created_at' => $old->created_at,
                    'updated_at' => now(),
                ]);

                // La fila "plantilla" del modelo anterior solo se conserva si ya recibió pagos
                if (! DB::table('movimientos_efectivo')->where('compromiso_id', $old->id)->exists()) {
                    DB::table('compromisos')->where('id', $old->id)->delete();

                    continue;
                }
                DB::table('compromisos')->where('id', $old->id)->update(['compromiso_recurrente_id' => $recurrenteId]);
            }

            $saldo = $old->saldo_pendiente ?? $old->monto;
            DB::table('compromisos')->where('id', $old->id)->update([
                'saldo_pendiente' => $saldo,
                'ciclo_inicio' => $this->cycleStart($user, $fecha),
                'estado' => match (true) {
                    $old->estado === 'cancelado' => 'cancelado',
                    (float) $saldo <= 0 || $old->estado === 'pagado' => 'pagado',
                    default => 'pendiente',
                },
            ]);
        }
    }

    /** Inicio del ciclo que contiene la fecha (misma regla que CicloEfectivoService). */
    private function cycleStart(?object $user, Carbon $date): string
    {
        $cutoff = max(1, min((int) ($user->fecha_corte_dia ?? 31), 31));
        $cutoffDate = fn (Carbon $month) => $month->copy()->day(min($cutoff, $month->daysInMonth));
        $end = $cutoffDate($date);
        if ($date->greaterThan($end)) {
            $end = $cutoffDate($date->copy()->startOfMonth()->addMonthNoOverflow());
        }

        return $cutoffDate($end->copy()->startOfMonth()->subMonthNoOverflow())->addDay()->toDateString();
    }
};
