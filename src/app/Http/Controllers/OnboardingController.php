<?php

namespace App\Http\Controllers;

use App\Models\Moneda;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function create()
    {
        $today = Carbon::today(request()->user()->zona_horaria ?: config('app.timezone'));
        $cutoff = min((int) (request()->user()->fecha_corte_dia ?: 31), $today->daysInMonth);
        $cycleEnd = $today->copy()->day($cutoff);
        if ($cycleEnd->lt($today)) {
            $cycleEnd->addMonthNoOverflow()->day(min($cutoff, $cycleEnd->daysInMonth));
        }

        return view('onboarding.create', [
            'monedas' => Moneda::query()->where('activo', true)->orderBy('nombre')->pluck('nombre', 'id'),
            'user' => request()->user(),
            'simbolo' => request()->user()->monedaPreferida?->simbolo ?? '$',
            'dateMin' => $today,
            'dateMax' => $cycleEnd,
            'draft' => $this->requestDraft(request()->user()),
        ]);
    }

    public function progress(Request $request)
    {
        abort_if($request->user()->onboarding_completed, 409, 'La configuración inicial ya fue completada.');
        if ($request->isMethod('GET')) {
            return response()->json(['data' => $this->requestDraft($request->user())]);
        }
        if (is_string($request->input('compromisos'))) {
            $request->merge(['compromisos' => json_decode($request->input('compromisos'), true, 512, JSON_THROW_ON_ERROR)]);
        }
        $data = $request->validate([
            'step' => ['required', 'integer', 'between:1,4'],
            'name' => ['nullable', 'string', 'max:255'],
            'moneda_preferida' => ['nullable', 'integer'],
            'fecha_corte_dia' => ['nullable', 'integer', 'between:1,31'],
            'zona_horaria' => ['nullable', 'timezone:all'],
            'categoria_nombre' => ['nullable', 'string', 'max:100'],
            'categoria_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'efectivo_inicial' => ['nullable', 'numeric', 'min:0'],
            'compromisos' => ['nullable', 'array'],
        ]);
        $user = $request->user();
        $user->update(['name' => $data['name'] ?? $user->name, 'onboarding_data' => array_merge($user->onboarding_data ?? [], $data)]);

        return response()->json(['success' => true]);
    }

    public function store(Request $request)
    {
        $request->merge(['email' => $request->user()->email]);
        $request->merge(['categoria_nombre' => 'Saldo inicial']);

        $validated = $request->validate([
            'moneda_preferida' => ['required', 'integer', Rule::exists('monedas', 'id')->where('activo', true)],
            'fecha_corte_dia' => ['required', 'integer', 'between:1,31'],
            'zona_horaria' => ['required', 'timezone:all'],
            'categoria_nombre' => ['required', 'string', 'max:100', 'in:Saldo inicial'],
            'categoria_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'efectivo_inicial' => ['required', 'numeric', 'min:0'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'avatar_base64' => ['nullable', 'string'],
            'compromisos' => ['nullable', 'array'],
            'compromisos.*.descripcion' => ['required', 'string', 'max:255'],
            'compromisos.*.monto' => ['required', 'numeric', 'min:0'],
            'compromisos.*.fecha_esperada' => ['required_if:compromisos.*.recurrencia_tipo,unica', 'nullable', 'date'],
            'compromisos.*.medio_pago' => ['required', 'in:efectivo,tarjeta,transferencia,por_definir'],
            'compromisos.*.recurrencia_tipo' => ['required', 'in:unica,recurrente'],
            'compromisos.*.frecuencia' => ['nullable', 'in:semanal,quincenal,mensual', 'required_if:compromisos.*.recurrencia_tipo,recurrente'],
            'compromisos.*.dia_pago' => ['nullable', 'integer', 'between:1,31'],
            'compromisos.*.dia_semana' => ['nullable', 'integer', 'between:0,6'],
            'compromisos.*.dia_secundario' => ['nullable', 'integer', 'between:1,31'],
            'compromisos.*.finalizacion' => ['nullable', 'in:indefinido,cuotas'],
            'compromisos.*.cuotas' => ['nullable', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($request, $validated) {
            $user = $request->user();
            $user->update(array_merge(
                collect($validated)->only(['name', 'email', 'moneda_preferida', 'fecha_corte_dia', 'zona_horaria'])->all(),
                ['onboarding_completed' => true],
            ));
            $user->update(['onboarding_data' => null]);
            if (filled($validated['avatar_base64'] ?? null) && preg_match('/^data:image\/(\w+);base64,/', $validated['avatar_base64'], $matches)) {
                $path = 'avatars/'.uniqid('avatar_', true).'.webp';
                Storage::disk('public')->put($path, base64_decode(substr($validated['avatar_base64'], strpos($validated['avatar_base64'], ',') + 1)));
                $user->update(['avatar' => $path]);
            }
            $categoria = $user->categoriasPersonales()->firstOrCreate(
                ['nombre' => 'Saldo inicial', 'tipo' => 'ingreso'],
                ['color' => $validated['categoria_color'], 'activo' => true],
            );
            $initialDate = now($validated['zona_horaria'])->toDateString();
            if (! $user->movimientosEfectivo()->where('categoria_personal_id', $categoria->id)->where('descripcion', 'Saldo inicial')->whereDate('fecha', $initialDate)->exists()) {
                $user->movimientosEfectivo()->create([
                    'categoria_personal_id' => $categoria->id,
                    'descripcion' => 'Saldo inicial',
                    'monto' => $validated['efectivo_inicial'],
                    'fecha' => $initialDate,
                    'tipo' => 'ingreso',
                ]);
            }
            foreach ($validated['compromisos'] ?? [] as $compromiso) {
                $compromiso['recurrencia_tipo'] = $compromiso['recurrencia_tipo'] ?? 'unica';
                $compromiso['recurrencia_unidad'] = $compromiso['frecuencia'] ?? null;
                $compromiso['recurrencia_intervalo'] = $compromiso['dia_pago'] ?? null;
                $compromiso['recurrencia_dia_semana'] = $compromiso['dia_semana'] ?? null;
                $compromiso['recurrencia_dia_secundario'] = $compromiso['dia_secundario'] ?? null;
                $compromiso['recurrencia_indefinida'] = ($compromiso['finalizacion'] ?? 'indefinido') === 'indefinido';
                $compromiso['recurrencia_ocurrencias'] = $compromiso['cuotas'] ?? null;
                unset($compromiso['frecuencia']);
                unset($compromiso['dia_pago']);
                unset($compromiso['dia_semana'], $compromiso['dia_secundario'], $compromiso['finalizacion'], $compromiso['cuotas']);
                $user->compromisos()->create($compromiso);
            }
        });

        return redirect()->route('dashboard')->with('success', 'Configuración inicial completada.');
    }

    private function requestDraft($user): array
    {
        return $user->onboarding_data ?? [];
    }
}
