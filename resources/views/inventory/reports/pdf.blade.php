<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #0f172a;
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.35;
        }

        header {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #0f172a;
        }

        h1 {
            margin: 0 0 6px;
            font-size: 20px;
            letter-spacing: 0;
        }

        p {
            margin: 2px 0;
        }

        .muted {
            color: #475569;
        }

        .meta {
            min-width: 220px;
            text-align: right;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th,
        td {
            padding: 6px;
            border: 1px solid #cbd5e1;
            vertical-align: top;
            word-wrap: break-word;
        }

        th {
            background: #e2e8f0;
            color: #334155;
            font-size: 9px;
            text-align: left;
            text-transform: uppercase;
        }

        tr:nth-child(even) td {
            background: #f8fafc;
        }

        .right {
            text-align: right;
        }

        .writeable {
            height: 28px;
        }

        .empty {
            padding: 26px;
            text-align: center;
            color: #64748b;
        }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>{{ $title }}</h1>
            <p><strong>{{ $tenant->name }}</strong></p>
            <p class="muted">Sucursal: {{ $scope }}</p>
        </div>
        <div class="meta">
            <p><strong>Periodo</strong></p>
            <p class="muted">{{ $period }}</p>
            <p><strong>Generado</strong></p>
            <p class="muted">{{ $generatedAt }}</p>
            <p><strong>Filas</strong></p>
            <p class="muted">{{ count($rows) }}</p>
        </div>
    </header>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th class="{{ ($column['align'] ?? '') === 'right' ? 'right' : '' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $column)
                        @php
                            $value = data_get($row, $column['key']);
                            if (($column['money'] ?? false) && $value !== null && $value !== '') {
                                $value = '$'.number_format((float) $value, 2);
                            } elseif (($column['percent'] ?? false) && $value !== null && $value !== '') {
                                $value = number_format((float) $value, 2).'%';
                            } elseif (is_numeric($value) && ! is_string($value)) {
                                $value = floor((float) $value) == (float) $value
                                    ? number_format((float) $value, 0)
                                    : number_format((float) $value, 3);
                            }
                        @endphp
                        <td class="{{ trim((($column['align'] ?? '') === 'right' ? 'right ' : '').(($column['writeable'] ?? false) ? 'writeable' : '')) }}">{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ count($columns) }}">Sin datos para mostrar.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
