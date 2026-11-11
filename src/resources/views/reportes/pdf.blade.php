<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1f2937; }
        .encabezado { border-bottom: 2px solid #0a0a5e; padding-bottom: 8px; margin-bottom: 14px; }
        .encabezado h1 { margin: 0; color: #0a0a5e; font-size: 17px; }
        .encabezado p { margin: 3px 0 0; color: #6b7280; font-size: 9px; }
        h2 { font-size: 11.5px; color: #0a0a5e; margin: 16px 0 4px; }
        table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        th { background: #e9ecf1; color: #374151; text-align: left; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; white-space: nowrap; }
        .negativo { color: #b91c1c; }
        tfoot td { font-weight: bold; border-top: 1.5px solid #9ca3af; border-bottom: none; }
        .vacio { color: #9ca3af; font-style: italic; }
        .pie { position: fixed; bottom: -14px; left: 0; right: 0; text-align: right; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    @php($numero = fn ($v) => is_numeric($v) ? number_format((float) $v, 2) : $v)
    <div class="encabezado">
        <h1>{{ $titulo }}</h1>
        <p>{{ $periodo }} · {{ $hogar }} · generado el {{ $generado }}</p>
    </div>

    @foreach ($secciones as $s)
        <h2>{{ $s['titulo'] }}</h2>
        <table>
            <thead>
                <tr>@foreach ($s['columnas'] as $i => $c)<th class="{{ in_array($i, $s['numericas']) ? 'num' : '' }}">{{ $c }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @forelse ($s['filas'] as $fila)
                    <tr>
                        @foreach ($fila as $i => $v)
                            <td class="{{ in_array($i, $s['numericas']) ? 'num' : '' }} {{ in_array($i, $s['numericas']) && is_numeric($v) && $v < 0 ? 'negativo' : '' }}">{{ in_array($i, $s['numericas']) ? $numero($v) : $v }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td class="vacio" colspan="{{ count($s['columnas']) }}">Sin datos en este período.</td></tr>
                @endforelse
            </tbody>
            @if ($s['total'] && $s['filas'])
                <tfoot>
                    <tr>@foreach ($s['total'] as $i => $v)<td class="{{ in_array($i, $s['numericas']) ? 'num' : '' }}">{{ in_array($i, $s['numericas']) && $v !== '' ? $numero($v) : $v }}</td>@endforeach</tr>
                </tfoot>
            @endif
        </table>
    @endforeach

    <div class="pie">Mis Gastos</div>
</body>
</html>
