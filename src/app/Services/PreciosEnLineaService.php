<?php

namespace App\Services;

use App\Models\ConsultaPrecios;
use App\Models\Hogar;
use App\Models\ListaCompra;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\ProductoEnLinea;
use App\Models\Tienda;
use App\Models\User;
use App\Services\PreciosEnLinea\Fuente;
use App\Services\PreciosEnLinea\FuenteNoResponde;
use App\Services\PreciosEnLinea\FuenteSelectos;
use App\Services\PreciosEnLinea\FuenteVtex;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Precios en línea de Walmart, La Despensa de Don Juan y Súper Selectos (sin IA: su búsqueda pública).
 *
 *  - Cada producto se **liga una vez** con el suyo en cada tienda («Buscar en Walmart» → «Es este»). Con código de
 *    barras, Walmart y Don Juan lo encuentran solos (sale primero, marcado). Walmart y Don Juan comparten catálogo:
 *    al ligar uno se liga también el otro.
 *  - «Consultar precios» de una lista consulta solo sus productos ligados, **de uno en uno**: la pantalla pide el
 *    siguiente y el servidor exige una pausa entre cada uno (avance visible, "7 de 15").
 *
 * Límites para no ser bloqueados:
 *  1. Un precio se reutiliza si se leyó **el mismo día** (zona del usuario) y hace menos de 12 horas.
 *  2. Pausa de {@see PAUSA_ENTRE_PASOS} s entre productos (por hogar).
 *  3. {@see CONSULTAS_POR_DIA} consultas de lista por hogar al día (continuar una sin terminar no cuenta otra).
 *     Las búsquedas para ligar: {@see BUSQUEDAS_POR_HORA} por hogar por hora.
 *  4. Si una tienda falla o limita, se pausa {@see PAUSA_FUENTE_MINUTOS} min para todos y se usa su último precio.
 */
class PreciosEnLineaService
{
    public const CONSULTAS_POR_DIA = 3;

    public const PAUSA_ENTRE_PASOS = 2;

    public const PAUSA_FUENTE_MINUTOS = 120;

    public const BUSQUEDAS_POR_HORA = 30;

    public const VIGENCIA_HORAS = 12;

    /** Las que comparten catálogo (mismo id de producto). */
    private const VTEX = ['walmart' => 'https://www.walmart.com.sv', 'donjuan' => 'https://www.ladespensadedonjuan.com.sv'];

    public function __construct(private readonly DespensaService $despensa) {}

    public function fuente(string $clave): Fuente
    {
        return match ($clave) {
            'walmart', 'donjuan' => new FuenteVtex(self::VTEX[$clave]),
            'selectos' => new FuenteSelectos,
        };
    }

    /** Fuentes con al menos una tienda activa del hogar: fuente => tiendas. @return Collection<string, Collection<int, Tienda>> */
    public function fuentesDelHogar(User $user): Collection
    {
        return $user->tiendas()->where('activo', true)->whereNotNull('fuente')->orderBy('nombre')->get()->groupBy('fuente');
    }

    /**
     * Busca el producto en una tienda para ligarlo. Sin texto: por su código de barras (y si no sale, por su nombre).
     *
     * @return list<array{codigo: string, nombre: string, marca: ?string, precio: ?float, ean: ?string, url: ?string, imagen: ?string, mismo_codigo: bool}>
     */
    public function buscar(User $user, Producto $producto, string $fuente, ?string $texto = null): array
    {
        $this->asegurarFuente($user, $fuente);
        $this->asegurarActiva($fuente);
        $clave = 'precios-busqueda:'.$user->hogar_id;
        if (RateLimiter::tooManyAttempts($clave, self::BUSQUEDAS_POR_HORA)) {
            throw ValidationException::withMessages(['buscar' => 'Ya buscaste muchas veces en la última hora. Intenta en '.max(1, (int) ceil(RateLimiter::availableIn($clave) / 60)).' min.']);
        }
        RateLimiter::hit($clave, 3600);

        $texto = trim((string) $texto);
        $codigo = $producto->codigo_barras;
        try {
            $resultados = $texto !== '' ? $this->fuente($fuente)->buscar($texto) : [];
            if ($texto === '') {
                $resultados = $codigo && $fuente !== 'selectos' ? $this->fuente($fuente)->buscar($codigo) : [];
                if ($resultados === []) {
                    $resultados = $this->fuente($fuente)->buscar($producto->nombreCompleto());
                }
            }
        } catch (FuenteNoResponde) {
            $this->pausar($fuente);
            throw ValidationException::withMessages(['buscar' => Tienda::FUENTES[$fuente].' no está respondiendo. Intenta más tarde.']);
        }

        return collect($resultados)->filter(fn ($r) => $r['codigo'] !== '' && $r['nombre'] !== '')
            ->map(fn ($r) => $r + ['mismo_codigo' => $codigo !== null && $r['ean'] === $codigo])
            ->sortByDesc('mismo_codigo')->values()->all();
    }

    /**
     * Liga el producto con el de la tienda (y el gemelo de Walmart/Don Juan si el hogar tiene esa tienda). Si se
     * eligió con su precio, ese precio queda leído hoy.
     *
     * @param  array{codigo: string, nombre: string, url?: ?string, imagen?: ?string, precio?: ?numeric, unidades?: ?int}  $data
     */
    public function enlazar(User $user, Producto $producto, string $fuente, array $data): ProductoEnLinea
    {
        $fuentes = $this->asegurarFuente($user, $fuente);

        return DB::transaction(function () use ($user, $producto, $fuente, $data, $fuentes) {
            $datos = ['codigo' => $data['codigo'], 'nombre' => mb_substr($data['nombre'], 0, 200), 'url' => $data['url'] ?? null,
                'imagen' => $data['imagen'] ?? null, 'unidades' => $data['unidades'] ?? 1, 'error' => null];
            $enlace = ProductoEnLinea::updateOrCreate(['producto_id' => $producto->id, 'fuente' => $fuente], $datos);
            if (isset($data['precio']) && (float) $data['precio'] > 0) {
                $this->guardarPrecio($user, $enlace, (float) $data['precio'], $fuentes[$fuente]);
            }
            $gemelo = array_key_exists($fuente, self::VTEX) ? array_key_first(array_diff_key(self::VTEX, [$fuente => 1])) : null;
            if ($gemelo && $fuentes->has($gemelo) && ! ProductoEnLinea::where('producto_id', $producto->id)->where('fuente', $gemelo)->exists()) {
                ProductoEnLinea::create(['producto_id' => $producto->id, 'fuente' => $gemelo, 'url' => isset($datos['url']) ? str_replace(self::VTEX[$fuente], self::VTEX[$gemelo], $datos['url']) : null] + $datos);
            }

            return $enlace;
        });
    }

    public function desenlazar(ProductoEnLinea $enlace): void
    {
        $enlace->delete();
    }

    /** Ligas del producto por fuente. @return Collection<string, ProductoEnLinea> */
    public function enlaces(Producto $producto): Collection
    {
        return ProductoEnLinea::where('producto_id', $producto->id)->get()->keyBy('fuente');
    }

    /**
     * Empieza (o continúa, si quedó a medias hoy) la consulta de los productos ligados que faltan en la lista. Null si
     * todos los precios ya se leyeron hoy (no gasta una consulta).
     */
    public function iniciar(User $user, ListaCompra $lista): ?ConsultaPrecios
    {
        if (! $lista->abierta()) {
            throw ValidationException::withMessages(['consulta' => 'Esta lista ya se terminó.']);
        }
        $hoy = $this->despensa->hoy($user)->toDateString();

        return DB::transaction(function () use ($user, $lista, $hoy) {
            Hogar::whereKey($user->hogar_id)->lockForUpdate()->first(); // dos toques a la vez no gastan dos consultas
            $abierta = ConsultaPrecios::delHogar($user)->where('lista_compra_id', $lista->id)->whereDate('dia', $hoy)->whereNull('terminada_at')->latest('id')->first();
            if ($abierta) {
                return $abierta;
            }
            $fuentes = $this->fuentesDelHogar($user);
            if ($fuentes->isEmpty()) {
                throw ValidationException::withMessages(['consulta' => 'Agrega en Tiendas las que tienen precios en línea (Walmart, Don Juan o Selectos).']);
            }
            $productos = $lista->items()->where('comprado', false)->whereNotNull('producto_id')->with('producto')->get()->pluck('producto')->filter()
                ->filter(fn (Producto $p) => $this->enlacesConTienda($p, $fuentes)->isNotEmpty())->values();
            if ($productos->isEmpty()) {
                throw ValidationException::withMessages(['consulta' => 'Ningún producto pendiente de la lista está ligado con una tienda en línea. Lígalos en Despensa → Precios.']);
            }
            if ($productos->every(fn (Producto $p) => $this->enlacesConTienda($p, $fuentes)->every(fn ($e) => $this->vigente($user, $p, $fuentes[$e->fuente])))) {
                return null;
            }
            $usadas = ConsultaPrecios::delHogar($user)->whereDate('dia', $hoy)->count();
            if ($usadas >= self::CONSULTAS_POR_DIA) {
                throw ValidationException::withMessages(['consulta' => 'Ya consultaste precios '.self::CONSULTAS_POR_DIA.' veces hoy. Mañana puedes volver a consultar; mientras tanto se muestran los últimos precios.']);
            }

            return ConsultaPrecios::create(['user_id' => $user->id, 'lista_compra_id' => $lista->id, 'dia' => $hoy, 'productos' => $productos->pluck('id')->all()]);
        });
    }

    /**
     * Consulta el siguiente producto. Si no ha pasado la pausa desde el anterior (en este hogar), no consulta y dice
     * cuánto esperar.
     *
     * @return array{hechos: int, total: int, nuevos: int, reusados: int, fallas: int, terminada: bool, espera?: int, producto?: string}
     */
    public function siguiente(ConsultaPrecios $consulta, User $user): array
    {
        if ($consulta->terminada()) {
            return $this->estado($consulta);
        }
        if (! Cache::add('precios-paso:'.$user->hogar_id, 1, self::PAUSA_ENTRE_PASOS)) {
            return $this->estado($consulta) + ['espera' => self::PAUSA_ENTRE_PASOS * 1000];
        }

        return DB::transaction(function () use ($consulta, $user) {
            $consulta = ConsultaPrecios::whereKey($consulta->id)->lockForUpdate()->firstOrFail();
            if ($consulta->terminada()) {
                return $this->estado($consulta);
            }
            $producto = Producto::delHogar($user)->find($consulta->productos[$consulta->hechos] ?? 0);
            $conteo = ['nuevos' => 0, 'reusados' => 0, 'fallas' => 0];
            if ($producto) {
                $conteo = $this->consultarProducto($user, $producto);
            }
            $hechos = $consulta->hechos + 1;
            $consulta->update(['hechos' => $hechos, 'nuevos' => $consulta->nuevos + $conteo['nuevos'], 'reusados' => $consulta->reusados + $conteo['reusados'],
                'fallas' => $consulta->fallas + $conteo['fallas'], 'terminada_at' => $hechos >= $consulta->total() ? now() : null]);

            return $this->estado($consulta) + ['producto' => $producto?->nombreCompleto()];
        });
    }

    /** @return array{nuevos: int, reusados: int, fallas: int} */
    public function consultarProducto(User $user, Producto $producto): array
    {
        $conteo = ['nuevos' => 0, 'reusados' => 0, 'fallas' => 0];
        $fuentes = $this->fuentesDelHogar($user);
        foreach ($this->enlacesConTienda($producto, $fuentes) as $enlace) {
            $tiendas = $fuentes[$enlace->fuente];
            if ($this->vigente($user, $producto, $tiendas)) {
                $conteo['reusados']++;

                continue;
            }
            if ($this->pausadaHasta($enlace->fuente)) {
                $conteo['fallas']++;

                continue;
            }
            try {
                $leido = $this->fuente($enlace->fuente)->consultar($enlace);
            } catch (FuenteNoResponde) {
                $this->pausar($enlace->fuente);
                $conteo['fallas']++;

                continue;
            }
            if ($leido === null || $leido['precio'] === null) {
                $enlace->update(['consultado_at' => now(), 'error' => $leido === null ? 'no_encontrado' : 'sin_precio']);
                $conteo['fallas']++;

                continue;
            }
            $this->guardarPrecio($user, $enlace, $leido['precio'], $tiendas);
            $enlace->update(['consultado_at' => now(), 'error' => null, 'nombre' => mb_substr($leido['nombre'] ?: $enlace->nombre, 0, 200)]);
            $conteo['nuevos']++;
        }

        return $conteo;
    }

    public function estado(ConsultaPrecios $consulta): array
    {
        return ['hechos' => $consulta->hechos, 'total' => $consulta->total(), 'nuevos' => $consulta->nuevos, 'reusados' => $consulta->reusados,
            'fallas' => $consulta->fallas, 'terminada' => $consulta->terminada()];
    }

    /** Cuántas consultas de lista le quedan hoy al hogar. */
    public function restantesHoy(User $user): int
    {
        return max(0, self::CONSULTAS_POR_DIA - ConsultaPrecios::delHogar($user)->whereDate('dia', $this->despensa->hoy($user)->toDateString())->count());
    }

    public function pausadaHasta(string $fuente): ?Carbon
    {
        $hasta = Cache::get('precios-pausa:'.$fuente);

        return $hasta && $hasta > now()->timestamp ? Carbon::createFromTimestamp($hasta) : null;
    }

    private function pausar(string $fuente): void
    {
        Cache::put('precios-pausa:'.$fuente, now()->addMinutes(self::PAUSA_FUENTE_MINUTOS)->timestamp, now()->addMinutes(self::PAUSA_FUENTE_MINUTOS));
    }

    /** Ya hay un precio en línea de hoy (zona del usuario) leído hace menos de 12 horas en las tiendas de esa fuente. */
    private function vigente(User $user, Producto $producto, Collection $tiendas): bool
    {
        return Precio::where('producto_id', $producto->id)->whereIn('tienda_id', $tiendas->pluck('id'))->where('origen', 'en_linea')
            ->whereDate('fecha', $this->despensa->hoy($user)->toDateString())->where('created_at', '>=', now()->subHours(self::VIGENCIA_HORAS))->exists();
    }

    private function guardarPrecio(User $user, ProductoEnLinea $enlace, float $precio, Collection $tiendas): void
    {
        foreach ($tiendas as $tienda) {
            Precio::create(['user_id' => $user->id, 'producto_id' => $enlace->producto_id, 'tienda_id' => $tienda->id, 'precio' => $precio,
                'unidades' => max(1, $enlace->unidades), 'origen' => 'en_linea', 'fecha' => $this->despensa->hoy($user)->toDateString()]);
        }
    }

    /** @return Collection<int, ProductoEnLinea> ligas del producto con fuentes que el hogar tiene */
    private function enlacesConTienda(Producto $producto, Collection $fuentes): Collection
    {
        return ProductoEnLinea::where('producto_id', $producto->id)->whereIn('fuente', $fuentes->keys())->orderBy('fuente')->get();
    }

    /** @return Collection<string, Collection<int, Tienda>> */
    private function asegurarFuente(User $user, string $fuente): Collection
    {
        $fuentes = $this->fuentesDelHogar($user);
        if (! $fuentes->has($fuente)) {
            throw ValidationException::withMessages(['fuente' => 'Agrega primero esa tienda en Tiendas (con precios en línea).']);
        }

        return $fuentes;
    }

    private function asegurarActiva(string $fuente): void
    {
        if ($hasta = $this->pausadaHasta($fuente)) {
            throw ValidationException::withMessages(['buscar' => Tienda::FUENTES[$fuente].' no estaba respondiendo. Intenta después de las '.$hasta->timezone(auth()->user()?->zona_horaria ?: config('app.timezone'))->format('g:i a').'.']);
        }
    }
}
