<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Productos de la despensa del hogar.
 *
 *  - La foto va al disco privado ("local") y solo se sirve a miembros del hogar (ruta productos.foto).
 *  - Lista: básicos primero, luego por categoría y nombre. Los archivados solo se ven con su filtro.
 */
class ProductoService
{
    public const DISCO = 'local';

    public function __construct(private readonly DespensaService $despensa) {}

    public function crear(User $user, array $data, ?UploadedFile $foto = null): Producto
    {
        $producto = $user->productos()->create($data + ['user_id' => $user->id, 'activo' => true]);
        if ($foto) {
            $this->guardarFoto($producto, $foto);
        }

        return $producto;
    }

    public function actualizar(Producto $producto, array $data, ?UploadedFile $foto = null, bool $quitarFoto = false): Producto
    {
        $producto->update($data);
        if ($foto) {
            $this->guardarFoto($producto, $foto);
        } elseif ($quitarFoto) {
            $this->borrarFoto($producto);
            $producto->update(['foto' => null]);
        }

        return $producto;
    }

    public function eliminar(Producto $producto): void
    {
        $this->borrarFoto($producto);
        $producto->delete();
    }

    /**
     * @param  array{buscar?: ?string, categoria?: ?int, importancia?: ?string, archivados?: bool, faltan?: bool}  $filtros
     * @return Collection<int, Producto>
     */
    public function lista(User $user, array $filtros = []): Collection
    {
        $buscar = mb_strtolower(trim((string) ($filtros['buscar'] ?? '')));

        return $user->productos()->with('categoria')
            ->where('activo', ! ($filtros['archivados'] ?? false))
            ->when($buscar !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(nombre) LIKE ?', ["%{$buscar}%"])
                ->orWhereRaw("LOWER(COALESCE(marca, '')) LIKE ?", ["%{$buscar}%"])
                ->orWhere('codigo_barras', $buscar)))
            ->when($filtros['categoria'] ?? null, fn ($q, $id) => $q->where('categoria_despensa_id', $id))
            ->when(isset(Producto::IMPORTANCIAS[$filtros['importancia'] ?? '']), fn ($q) => $q->where('importancia', $filtros['importancia']))
            ->get()
            ->when($filtros['faltan'] ?? false, fn ($c) => $c->filter(fn (Producto $p) => $this->despensa->falta($p)))
            ->sortBy(fn (Producto $p) => [$p->esBasico() ? 0 : 1, $p->categoria->orden, mb_strtolower($p->categoria->nombre), mb_strtolower($p->nombre), mb_strtolower((string) $p->marca)])
            ->values();
    }

    /** Cuántos faltan y cuántos vencen pronto (o ya vencieron), de los que se compran. */
    public function resumen(User $user): array
    {
        $productos = $user->productos()->where('activo', true)->get();
        $hoy = $this->despensa->hoy($user);

        return [
            'faltan' => $productos->filter(fn (Producto $p) => $this->despensa->falta($p))->count(),
            'por_vencer' => $productos->filter(fn (Producto $p) => $this->despensa->vencimiento($p, $hoy)['aviso'] ?? false)->count(),
        ];
    }

    private function guardarFoto(Producto $producto, UploadedFile $foto): void
    {
        $this->borrarFoto($producto);
        $ruta = $foto->store("productos/{$producto->hogar_id}", self::DISCO);
        $producto->update(['foto' => $ruta]);
    }

    private function borrarFoto(Producto $producto): void
    {
        if ($producto->foto && Storage::disk(self::DISCO)->exists($producto->foto)) {
            Storage::disk(self::DISCO)->delete($producto->foto);
        }
    }
}
