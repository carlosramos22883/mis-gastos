<?php

namespace App\Services;

use App\Exceptions\ExcedePresupuesto;
use App\Models\CategoriaPersonal;
use App\Models\CompraPago;
use App\Models\Cuenta;
use App\Models\ListaCompra;
use App\Models\ListaCompraItem;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\TarjetaCredito;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Pagar aquí»: pagar lo que está en el carrito en la tienda donde se está comprando.
 *
 *  - Se paga lo del carrito que aún no se paga (de esa tienda o sin tienda). El total se sugiere con cantidad × precio
 *    y se puede corregir con el del ticket.
 *  - Extras (lo que se compró y no estaba en la lista): un solo total con una categoría, o cada cosa con su precio y
 *    su categoría (jalea → Súper, camisa → Ropa). Los detallados quedan anotados en la lista como comprados.
 *  - Se registra un gasto por categoría desde efectivo, una cuenta o una tarjeta, con las reglas de cada módulo
 *    (saldo, disponible, presupuesto). Mientras exista el pago, esos gastos se gestionan desde la lista.
 *  - Deshacer el pago borra sus gastos y deja las cosas otra vez en el carrito sin pagar.
 *
 * Categoría: el id de una categoría de gasto del hogar o "nueva:Nombre" / "nuevo:Nombre" (se crea al pagar).
 */
class CompraPagoService
{
    /** Colores para las categorías que se crean al pagar. */
    private const COLORES = ['#16A34A', '#2563EB', '#DB2777', '#EA580C', '#7C3AED', '#0891B2', '#CA8A04', '#DC2626'];

    public function __construct(
        private readonly CicloEfectivoService $ciclos,
        private readonly CuentaService $cuentas,
        private readonly TarjetaService $tarjetas,
        private readonly MovimientoEfectivoService $efectivo,
        private readonly PresupuestoTraspasoService $traspasos,
        private readonly PrecioService $precios,
    ) {}

    /** Desde dónde puede pagar el usuario (según sus permisos), con el saldo o disponible de cada opción. */
    public function origenes(User $user): array
    {
        $origenes = [];
        if ($user->can('efectivo.create')) {
            $origenes['efectivo'] = 'Efectivo ($ '.number_format($this->ciclos->balance($user), 2).')';
        }
        if ($user->can('cuentas.create')) {
            foreach ($user->cuentas()->where('activo', true)->orderBy('nombre')->get() as $cuenta) {
                $origenes['cuenta:'.$cuenta->id] = $cuenta->nombre.' ($ '.number_format($this->cuentas->saldo($cuenta), 2).')';
            }
        }
        if ($user->can('tarjetas.create')) {
            foreach ($user->tarjetasCredito()->where('activo', true)->orderBy('nombre')->get() as $tarjeta) {
                $origenes['tarjeta:'.$tarjeta->id] = 'Tarjeta '.$tarjeta->nombre.' (disponible $ '.number_format($this->tarjetas->disponible($tarjeta), 2).')';
            }
        }

        return $origenes;
    }

    /** Lo del carrito que aún no se paga en la tienda donde se está (o que se echó sin elegir tienda). */
    public function porPagar(ListaCompra $lista): Collection
    {
        return $lista->items()->where('comprado', true)->whereNull('compra_pago_id')
            ->where(fn ($q) => $q->whereNull('tienda_id')->when($lista->tienda_actual_id, fn ($q) => $q->orWhere('tienda_id', $lista->tienda_actual_id)))
            ->orderBy('descripcion')->get();
    }

    /** @return array{items: Collection<int, ListaCompraItem>, total: float, sin_precio: int} */
    public function sugerido(ListaCompra $lista): array
    {
        $items = $this->porPagar($lista);

        return ['items' => $items, 'total' => round($items->sum(fn (ListaCompraItem $i) => $i->subtotal() ?? 0), 2), 'sin_precio' => $items->whereNull('precio')->count()];
    }

    /** La categoría de lo de la lista en el último pago del hogar, o una que se llame como el súper. */
    public function categoriaSugerida(User $user): ?int
    {
        $ultima = CompraPago::delHogar($user)->latest('id')->first()?->movimientos()->orderBy('id')->value('categoria_personal_id');
        if ($ultima && $this->categorias($user)->has($ultima)) {
            return $ultima;
        }

        return $this->categorias($user)->search(fn ($nombre) => preg_match('/s[uú]per/iu', $nombre)) ?: null;
    }

    /** Categorías de gasto activas del hogar: id => nombre. @return Collection<int, string> */
    public function categorias(User $user): Collection
    {
        return CategoriaPersonal::where('hogar_id', $user->hogar_id)->where('activo', true)->where('tipo', 'egreso')
            ->where('nombre', '!=', CicloEfectivoService::CATEGORIA_SALDO_INICIAL)->orderBy('nombre')->pluck('nombre', 'id');
    }

    /**
     * @param array{origen: string, fecha: string, user_id?: ?int, categoria?: ?string, monto_lista?: ?numeric,
     *     extras_modo: string, extras_total?: ?numeric, extras_categoria?: ?string,
     *     extras?: list<array{descripcion: string, precio: numeric, categoria: string}>,
     *     presupuestos?: array<string, array{modo: string, traspasos?: array}>, confirmar?: bool} $data
     */
    public function pagar(ListaCompra $lista, User $user, array $data): CompraPago
    {
        return DB::transaction(function () use ($lista, $user, $data) {
            $lista = ListaCompra::whereKey($lista->id)->lockForUpdate()->firstOrFail();
            if (! $lista->abierta()) {
                throw ValidationException::withMessages(['lista' => 'Esta lista ya se terminó.']);
            }
            $tienda = $lista->tiendaActual ?? throw ValidationException::withMessages(['lista' => 'Elige primero en qué tienda estás.']);
            $items = $this->porPagar($lista);
            $grupos = $this->grupos($user, $tienda->nombre, $items->isNotEmpty(), $data);
            if ($grupos === []) {
                throw ValidationException::withMessages(['monto_lista' => 'No hay nada que pagar: echa algo al carrito o anota los extras.']);
            }

            $pago = CompraPago::create(['user_id' => $data['user_id'] ?? $user->id, 'lista_compra_id' => $lista->id, 'tienda_id' => $tienda->id,
                'origen' => $data['origen'], 'total' => round(array_sum(array_column($grupos, 'monto')), 2), 'fecha' => $data['fecha']]);

            foreach ($grupos as $clave => $grupo) {
                $categoria = $grupo['categoria'];
                $mov = ['descripcion' => $grupo['descripcion'], 'monto' => $grupo['monto'], 'fecha' => $data['fecha'],
                    'categoria_personal_id' => $categoria->id, 'user_id' => $data['user_id'] ?? $user->id];
                $plan = $this->revisarPresupuesto($user, $clave, $mov, $data['presupuestos'][$clave] ?? null);
                $movimiento = $this->registrar($user, $data['origen'], $mov, ! empty($data['confirmar']));
                if ($plan !== null) {
                    $this->traspasos->aplicar($movimiento, $plan);
                }
                $pago->movimientos()->create(['categoria_personal_id' => $categoria->id, 'monto' => $grupo['monto']] + $this->enlace($movimiento));
            }

            foreach ($items as $item) {
                $sinTienda = $item->tienda_id === null;
                $item->update(['compra_pago_id' => $pago->id, 'tienda_id' => $tienda->id]);
                if ($sinTienda) {
                    $this->precios->sincronizarCompra($item, $user); // ya tiene tienda: su precio pasa al historial
                }
            }
            foreach ($this->extrasComoItems($data) as $extra) {
                $lista->items()->create($extra + ['cantidad' => 1, 'comprado' => true, 'tienda_id' => $tienda->id, 'compra_pago_id' => $pago->id, 'extra' => true]);
            }

            return $pago->load('movimientos.categoria');
        });
    }

    /** Borra los gastos del pago y deja sus cosas en el carrito sin pagar (los extras se quitan de la lista). */
    public function deshacer(CompraPago $pago): void
    {
        DB::transaction(function () use ($pago) {
            $pago = CompraPago::whereKey($pago->id)->lockForUpdate()->firstOrFail();
            if (! $pago->lista->abierta()) {
                throw ValidationException::withMessages(['pago' => 'La lista ya se terminó: elimina el gasto desde su movimiento.']);
            }
            foreach ($pago->movimientos as $enlace) {
                $movimiento = $enlace->movimiento();
                $enlace->delete(); // suelto: su módulo ya deja eliminarlo
                match (true) {
                    $movimiento instanceof MovimientoEfectivo => $this->efectivo->eliminar($movimiento),
                    $movimiento instanceof MovimientoCuenta => $this->cuentas->eliminarMovimiento($movimiento),
                    $movimiento instanceof MovimientoTarjeta => $this->tarjetas->eliminarMovimiento($movimiento),
                    default => null,
                };
            }
            $pago->items()->where('extra', true)->delete();
            $pago->items()->update(['compra_pago_id' => null]);
            $pago->delete();
        });
    }

    /** Pagos de la lista con sus gastos, del más reciente al más antiguo. */
    public function deLista(ListaCompra $lista): Collection
    {
        return $lista->pagos()->with('tienda', 'user', 'movimientos.categoria')->latest('id')->get();
    }

    /** Texto de dónde salió el dinero ("Efectivo", "Cuenta X", "Tarjeta X"). */
    public function nombreOrigen(CompraPago $pago): string
    {
        if (preg_match('/^(cuenta|tarjeta):(\d+)$/', $pago->origen, $m)) {
            $nombre = $m[1] === 'cuenta'
                ? Cuenta::whereKey($m[2])->value('nombre')
                : TarjetaCredito::whereKey($m[2])->value('nombre');

            return ($m[1] === 'cuenta' ? 'Cuenta ' : 'Tarjeta ').($nombre ?? '');
        }

        return 'Efectivo';
    }

    /**
     * Lo que se paga agrupado por categoría (clave = lo que mandó el formulario: id o "nueva:Nombre").
     *
     * @return array<string, array{categoria: CategoriaPersonal, monto: float, descripcion: string}>
     */
    private function grupos(User $user, string $tienda, bool $hayItems, array $data): array
    {
        $partes = []; // [clave, monto, descripción del extra o null si es lo de la lista]
        if ($hayItems) {
            $monto = round((float) ($data['monto_lista'] ?? 0), 2);
            if ($monto <= 0) {
                throw ValidationException::withMessages(['monto_lista' => 'Escribe cuánto pagaste por lo de la lista.']);
            }
            $partes[] = [$this->clave($data['categoria'] ?? null, 'categoria'), $monto, null];
        }
        $modo = $data['extras_modo'] ?? 'ninguno';
        if ($modo === 'total' && round((float) ($data['extras_total'] ?? 0), 2) > 0) {
            $partes[] = [$this->clave($data['extras_categoria'] ?? null, 'extras_categoria'), round((float) $data['extras_total'], 2), 'otras cosas'];
        } elseif ($modo === 'detalle') {
            foreach (array_values($data['extras'] ?? []) as $i => $extra) {
                $partes[] = [$this->clave($extra['categoria'] ?? null, "extras.{$i}.categoria"), round((float) $extra['precio'], 2), trim($extra['descripcion'])];
            }
        }

        $grupos = [];
        foreach ($partes as [$valor, $monto, $extra]) {
            $clave = str_starts_with($valor, 'nueva:') ? mb_strtolower($valor) : $valor; // la misma nueva escrita dos veces es una sola
            $grupos[$clave] ??= ['categoria' => $this->resolverCategoria($user, $valor), 'monto' => 0.0, 'lista' => false, 'extras' => []];
            $grupos[$clave]['monto'] = round($grupos[$clave]['monto'] + $monto, 2);
            $extra === null ? $grupos[$clave]['lista'] = true : $grupos[$clave]['extras'][] = $extra;
        }

        return array_map(fn ($g) => ['categoria' => $g['categoria'], 'monto' => $g['monto'],
            'descripcion' => mb_substr($g['lista']
                ? "Compras en {$tienda}".($g['extras'] ? ' (y '.implode(', ', $g['extras']).')' : '')
                : "{$tienda}: ".implode(', ', $g['extras']), 0, 255)], $grupos);
    }

    private function clave(?string $valor, string $campo): string
    {
        $valor = trim((string) $valor);
        if (str_starts_with($valor, 'nuevo:')) {
            $valor = 'nueva:'.mb_substr($valor, 6); // así la manda el selector que deja crear
        }
        if ($valor === '' || (! ctype_digit($valor) && ! str_starts_with($valor, 'nueva:'))) {
            throw ValidationException::withMessages([$campo => 'Elige la categoría.']);
        }

        return str_starts_with($valor, 'nueva:') ? 'nueva:'.trim(mb_substr($valor, 6)) : $valor;
    }

    /** Una categoría de gasto activa del hogar, o la nueva (si ya existe una con ese nombre, se usa esa). */
    private function resolverCategoria(User $user, string $clave): CategoriaPersonal
    {
        $base = CategoriaPersonal::where('hogar_id', $user->hogar_id)->where('tipo', 'egreso');
        if (! str_starts_with($clave, 'nueva:')) {
            return (clone $base)->where('activo', true)->where('nombre', '!=', CicloEfectivoService::CATEGORIA_SALDO_INICIAL)->find((int) $clave)
                ?? throw ValidationException::withMessages(['categoria' => 'Elige una de tus categorías de gasto.']);
        }
        $nombre = trim(mb_substr($clave, 6));
        if ($nombre === '' || mb_strlen($nombre) > 100 || mb_strtolower($nombre) === mb_strtolower(CicloEfectivoService::CATEGORIA_SALDO_INICIAL)) {
            throw ValidationException::withMessages(['categoria' => 'Escribe un nombre de categoría de hasta 100 letras.']);
        }
        $existente = (clone $base)->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first();
        if ($existente) {
            if (! $existente->activo) {
                $existente->update(['activo' => true]);
            }

            return $existente;
        }
        $total = (clone $base)->count();

        return $user->categoriasPersonales()->create(['nombre' => mb_strtoupper(mb_substr($nombre, 0, 1)).mb_substr($nombre, 1), // «ropa» → «Ropa»
            'tipo' => 'egreso', 'color' => self::COLORES[$total % count(self::COLORES)], 'activo' => true]);
    }

    /**
     * Revisa el presupuesto de la categoría como cualquier gasto. Sin decisión para esta categoría se revisa
     * («revisar»): si se pasa, avisa con la clave de la categoría para que el cliente decida y reenvíe.
     *
     * @return array<int, float>|null lo que hay que mover de otras categorías
     */
    private function revisarPresupuesto(User $user, string $clave, array $mov, ?array $decision): ?array
    {
        $data = ['presupuesto' => $decision['modo'] ?? 'revisar', 'traspasos' => $decision['traspasos'] ?? []];
        try {
            return $this->traspasos->revisar($data, $user->hogar_id, $mov['categoria_personal_id'], $mov['fecha'], (float) $mov['monto'], null);
        } catch (ExcedePresupuesto $e) {
            throw new ExcedePresupuesto($e->detalle + ['clave' => $clave]);
        }
    }

    private function registrar(User $user, string $origen, array $mov, bool $confirmar): Model
    {
        if ($origen === 'efectivo') {
            abort_unless($user->can('efectivo.create'), 403);

            return $this->efectivo->registrar($user, $mov + ['tipo' => 'egreso']);
        }
        if (preg_match('/^cuenta:(\d+)$/', $origen, $m)) {
            abort_unless($user->can('cuentas.create'), 403);
            $cuenta = $user->cuentas()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['origen' => 'Selecciona una cuenta válida.']);

            return $this->cuentas->registrarMovimiento($cuenta, $mov + ['tipo' => 'egreso']);
        }
        if (preg_match('/^tarjeta:(\d+)$/', $origen, $m)) {
            abort_unless($user->can('tarjetas.create'), 403);
            $tarjeta = $user->tarjetasCredito()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['origen' => 'Selecciona una tarjeta válida.']);

            return $this->tarjetas->registrarMovimiento($tarjeta, $mov + ['tipo' => 'compra', 'confirmar' => $confirmar]);
        }
        throw ValidationException::withMessages(['origen' => 'Elige con qué pagas.']);
    }

    /** @return list<array{descripcion: string, precio: float}> */
    private function extrasComoItems(array $data): array
    {
        return match ($data['extras_modo'] ?? 'ninguno') {
            'total' => round((float) ($data['extras_total'] ?? 0), 2) > 0 ? [['descripcion' => 'Otras cosas', 'precio' => round((float) $data['extras_total'], 2)]] : [],
            'detalle' => array_map(fn ($e) => ['descripcion' => mb_substr(trim($e['descripcion']), 0, 200), 'precio' => round((float) $e['precio'], 2)], array_values($data['extras'] ?? [])),
            default => [],
        };
    }

    private function enlace(Model $movimiento): array
    {
        return match (true) {
            $movimiento instanceof MovimientoEfectivo => ['movimiento_efectivo_id' => $movimiento->id],
            $movimiento instanceof MovimientoCuenta => ['movimiento_cuenta_id' => $movimiento->id],
            $movimiento instanceof MovimientoTarjeta => ['movimiento_tarjeta_id' => $movimiento->id],
        };
    }
}
