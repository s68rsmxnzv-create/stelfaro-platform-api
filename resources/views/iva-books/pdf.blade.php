<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Libros de IVA</title>
    <style>
        @page {
            size: Letter landscape;
            margin: 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #000;
            font-family: "Times New Roman", serif;
            font-size: 8.5px;
            line-height: 1.2;
        }

        .book + .book {
            page-break-before: always;
        }

        header {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 2px 14px;
            align-items: end;
            margin-bottom: 8px;
        }

        header .company {
            font-size: 15px;
            font-weight: 700;
        }

        header .page {
            font-size: 11px;
            text-align: right;
        }

        header .subtitle {
            font-weight: 700;
            margin-top: 2px;
        }

        header .period,
        header .nrc {
            margin-top: 10px;
        }

        header .nrc {
            text-align: right;
        }

        header hr {
            grid-column: 1 / -1;
            width: 100%;
            border: none;
            border-top: 2px solid #000;
            margin: 6px 0 0;
        }

        .line {
            display: inline-block;
            min-width: 90px;
            border-bottom: 1px solid #000;
            text-align: center;
        }

        .section-label {
            font-weight: 700;
            margin: 10px 0 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th,
        td {
            padding: 2px 4px;
            vertical-align: top;
            overflow-wrap: break-word;
            hyphens: auto;
        }

        .book-table thead th {
            font-weight: 700;
            text-align: left;
            border-bottom: 2px solid #000;
            padding-bottom: 3px;
        }

        .book-table thead th.group-header {
            text-align: center;
            border: 1px solid #000;
        }

        .book-table thead th.number {
            text-align: right;
        }

        .book-table thead tr:first-child th:not(.group-header) {
            border-bottom: none;
        }

        .book-table tbody td {
            min-height: 15px;
        }

        .book-table tfoot td {
            font-weight: 700;
            border-top: 2px solid #000;
            padding-top: 3px;
        }

        .number {
            text-align: right;
            white-space: nowrap;
        }

        .center {
            text-align: center;
        }

        .empty {
            height: 120px;
            text-align: center;
            vertical-align: middle;
        }

        .operations-summary {
            margin: 22px auto 0;
            width: 320px;
        }

        .operations-summary-title {
            font-weight: 700;
            font-size: 10px;
            text-align: center;
            margin: 0 0 8px;
        }

        .operations-summary table {
            width: 100%;
        }

        .operations-summary td {
            padding: 4px 6px;
            font-size: 9.5px;
        }

        .operations-summary .operations-summary-label {
            padding-left: 24px;
        }

        .operations-summary .bold {
            font-weight: 700;
        }

        .operations-summary .rule-above td {
            border-top: 1px solid #000;
            padding-top: 3px;
        }

        .summary {
            margin-top: 18px;
        }

        .summary-table th,
        .summary-table td {
            border: 1px solid #000;
        }

        .summary-table th {
            font-weight: 700;
            text-align: center;
        }

        .summary-table td:first-child {
            text-align: left;
        }

        .summary-table tfoot td {
            font-weight: 700;
        }

        .signature {
            margin-top: 80px;
            margin-left: auto;
            width: 260px;
            font-weight: 700;
            text-align: center;
        }

        .signature .line {
            display: block;
            min-width: 260px;
            margin: 0 auto 6px;
        }

        .signature p {
            margin: 0;
        }
    </style>
</head>
<body>
@foreach ($books as $book)
    <section class="book">
        <header>
            <div class="company">{{ $company['name'] }}</div>
            <div class="page">{{ $loop->iteration }}</div>
            <div class="subtitle">{{ $book['title'] }}</div>
            <div></div>
            <div class="period">del {{ \Carbon\Carbon::parse($period['from'])->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($period['to'])->format('d/m/Y') }}</div>
            <div class="nrc">NRC: <span class="line">{{ $company['nrc'] }}</span></div>
            <hr>
        </header>

        @if (isset($book['sectionLabel']))
            <p class="section-label">{{ $book['sectionLabel'] }}</p>
        @endif

        <table class="book-table">
            <thead>
                @php
                    $hasGroups = collect($book['columns'])->contains(fn (array $column): bool => isset($column['group']));
                    $headerCells = [];
                    if ($hasGroups) {
                        foreach ($book['columns'] as $column) {
                            $groupKey = $column['group'] ?? null;
                            $lastIndex = count($headerCells) - 1;
                            if ($groupKey !== null && $lastIndex >= 0 && ($headerCells[$lastIndex]['group'] ?? null) === $groupKey) {
                                $headerCells[$lastIndex]['span']++;
                            } else {
                                $headerCells[] = ['group' => $groupKey, 'span' => 1, 'column' => $column];
                            }
                        }
                    }
                @endphp
                @if ($hasGroups)
                    <tr>
                        @foreach ($headerCells as $cell)
                            @if ($cell['group'] === null)
                                <th rowspan="2" class="{{ ($cell['column']['money'] ?? false) ? 'number' : '' }}">{{ $cell['column']['label'] }}</th>
                            @else
                                <th class="group-header" colspan="{{ $cell['span'] }}">{{ $cell['group'] }}</th>
                            @endif
                        @endforeach
                    </tr>
                    <tr>
                        @foreach ($book['columns'] as $column)
                            @if (isset($column['group']))
                                <th class="number">{{ $column['label'] }}</th>
                            @endif
                        @endforeach
                    </tr>
                @else
                    <tr>
                        @foreach ($book['columns'] as $column)
                            <th class="{{ ($column['money'] ?? false) ? 'number' : '' }}">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                @endif
            </thead>
            <tbody>
                @forelse ($book['rows'] as $row)
                    <tr>
                        @foreach ($book['columns'] as $column)
                            @php
                                $value = $row[$column['key']] ?? '';
                                $isMoney = (bool) ($column['money'] ?? false);
                                $isNumeric = $isMoney || is_int($value) || is_float($value);
                            @endphp
                            <td class="{{ $isNumeric ? 'number' : '' }}">
                                @if ($isMoney)
                                    {{ number_format((float) $value, 2) }}
                                @else
                                    {{ $value }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td class="empty" colspan="{{ count($book['columns']) }}">
                            Nota: En este mes no hubieron operaciones de {{ $book['emptyLabel'] ?: $book['title'] }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    @foreach ($book['columns'] as $index => $column)
                        @php
                            $total = $book['totals'][$column['key']] ?? null;
                            $isMoney = (bool) ($column['money'] ?? false);
                        @endphp
                        @if ($index === 0)
                            <td class="center">{{ $book['totalLabel'] ?? 'Total' }}</td>
                        @elseif ($isMoney)
                            <td class="number">$ {{ number_format((float) $total, 2) }}</td>
                        @else
                            <td></td>
                        @endif
                    @endforeach
                </tr>
            </tfoot>
        </table>

        @if (isset($book['operationsSummary']))
            <div class="operations-summary">
                <p class="operations-summary-title">Resumen de Operaciones</p>
                <table>
                    @foreach ($book['operationsSummary'] as $line)
                        <tr class="{{ ($line['ruleAbove'] ?? false) ? 'rule-above' : '' }} {{ ($line['bold'] ?? false) ? 'bold' : '' }}">
                            <td class="operations-summary-label">{{ $line['label'] }}</td>
                            <td class="number">$ {{ number_format($line['value'], 2) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

        @if (isset($book['crossBookSummary']))
            <div class="summary">
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>No Sujetas</th>
                            <th>Exentas</th>
                            <th>Gravadas</th>
                            <th>Exportaciones</th>
                            <th>Iva</th>
                            <th>Retencion</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($book['crossBookSummary'] as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td class="number">${{ number_format($row['no_sujetas'], 2) }}</td>
                                <td class="number">${{ number_format($row['exentas'], 2) }}</td>
                                <td class="number">${{ number_format($row['gravadas'], 2) }}</td>
                                <td class="number">${{ number_format($row['exportaciones'], 2) }}</td>
                                <td class="number">${{ number_format($row['iva'], 2) }}</td>
                                <td class="number">${{ number_format($row['retencion'], 2) }}</td>
                                <td class="number">${{ number_format($row['total'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="signature">
            <span class="line"></span>
            <p>Contador:<br>Firma</p>
        </div>
    </section>
@endforeach
</body>
</html>
