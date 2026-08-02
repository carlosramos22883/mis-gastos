<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            /* DejaVu es la fuente por defecto de DomPDF que soporta tildes y ñ */
            font-size: 11px;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #0a0a5e;
            padding-bottom: 10px;
        }

        .header h1 {
            margin: 0;
            color: #0a0a5e;
            font-size: 18px;
            text-transform: uppercase;
        }

        .header p {
            margin: 5px 0 0;
            color: #666;
            font-size: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background-color: #0a0a5e;
            color: white;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
        }

        td {
            padding: 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 10px;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #999;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <p>Generado el: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($headers as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($data as $row)
                <tr>
                    @foreach ($headers as $key => $label)
                        <td>
                            @php
                                $value = $row->$key ?? 'N/A';

                                // Si es una colección (relación hasMany o belongsToMany)
                                if ($value instanceof \Illuminate\Support\Collection) {
                                    // Intentar obtener el campo 'name' de cada elemento
                                    $value = $value->pluck('name')->implode(', ');
                                }
                                // Si es un objeto con método format (fecha)
                                elseif (is_object($value) && method_exists($value, 'format')) {
                                    $value = $value->format('d/m/Y');
                                }
                                // Si es booleano
                                elseif (is_bool($value)) {
                                    $value = $value ? 'Sí' : 'No';
                                }
                                // Si es null o vacío
                                elseif (is_null($value) || $value === '') {
                                    $value = 'N/A';
                                }
                            @endphp
                            {{ $value }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}" style="text-align: center; padding: 20px;">
                        No se encontraron registros.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Sistema {{ config('app.name') }} &copy; {{ date('Y') }} - Documento generado automáticamente.
    </div>
</body>

</html>
