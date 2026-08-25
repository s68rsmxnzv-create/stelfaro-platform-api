<?php

namespace App\Services\IvaBooks;

use App\Models\Tenant;
use App\Models\User;
use App\Services\CoreBillingSessionBroker;
use App\Services\Inventory\InventoryPurchaseAnnexExportService;
use App\Services\TenantFiscalLinkResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IvaBooksReportService
{
    public function __construct(
        private readonly CoreBillingSessionBroker $coreSessions,
        private readonly InventoryPurchaseAnnexExportService $purchaseAnnexes,
        private readonly TenantFiscalLinkResolver $fiscalLinks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, Tenant $tenant, string $book, int $month, int $year): array
    {
        $period = $this->period($month, $year);
        $company = $this->company($user, $tenant);

        $sales = $this->salesAnnex($user, (int) $company['id'], $period['from'], $period['to']);
        $purchases = $this->purchaseAnnexes->build($tenant, $period['from'], $period['to']);

        $taxpayerBook = $this->taxpayerSalesBook($sales['data']['ventas_contribuyente']['official_rows'] ?? []);
        $consumerBook = $this->consumerSalesBook($sales['data']['ventas_consumidor_final']['official_rows'] ?? []);
        $purchasesBook = $this->purchasesBook($purchases['data']['compras']['official_rows'] ?? []);

        $taxpayerBook['crossBookSummary'] = $this->summary($taxpayerBook, $consumerBook);

        $books = [];

        if (in_array($book, ['all', 'taxpayer_sales'], true)) {
            $books[] = $taxpayerBook;
        }

        if (in_array($book, ['all', 'consumer_sales'], true)) {
            $books[] = $consumerBook;
        }

        if (in_array($book, ['all', 'purchases'], true)) {
            $books[] = $purchasesBook;
        }

        return [
            'company' => $company,
            'period' => [
                'month' => $month,
                'month_name' => $this->monthName($month),
                'year' => $year,
                'from' => $period['from'],
                'to' => $period['to'],
            ],
            'books' => $books,
        ];
    }

    /**
     * @return array<int, array{label: string, no_sujetas: float, exentas: float, gravadas: float, exportaciones: float, iva: float, retencion: float, total: float}>
     */
    private function summary(array $taxpayerBook, array $consumerBook): array
    {
        $creditoFiscal = [
            'label' => 'Libro de Credito Fiscal',
            'no_sujetas' => $taxpayerBook['totals']['no_sujetas'] ?? 0.0,
            'exentas' => $taxpayerBook['totals']['exentas'] ?? 0.0,
            'gravadas' => $taxpayerBook['totals']['gravadas'] ?? 0.0,
            'exportaciones' => $taxpayerBook['totals']['exportaciones'] ?? 0.0,
            'iva' => $taxpayerBook['totals']['iva'] ?? 0.0,
            'retencion' => $taxpayerBook['totals']['retencion'] ?? 0.0,
            'total' => $taxpayerBook['totals']['total'] ?? 0.0,
        ];

        $consumidorFinal = [
            'label' => 'Libro de Consumidor Final',
            'no_sujetas' => $consumerBook['totals']['no_sujetas'] ?? 0.0,
            'exentas' => $consumerBook['totals']['exentas'] ?? 0.0,
            'gravadas' => $consumerBook['totals']['gravadas'] ?? 0.0,
            'exportaciones' => $consumerBook['totals']['exportaciones'] ?? 0.0,
            'iva' => $consumerBook['totals']['iva'] ?? 0.0,
            'retencion' => $consumerBook['totals']['retenido'] ?? 0.0,
            'total' => $consumerBook['totals']['total'] ?? 0.0,
        ];

        // No existe una fuente de datos separada para facturas de exportación;
        // el anexo de ventas no distingue este tipo de documento todavía.
        $exportacion = [
            'label' => 'Facturas de Exportacion',
            'no_sujetas' => 0.0,
            'exentas' => 0.0,
            'gravadas' => 0.0,
            'exportaciones' => 0.0,
            'iva' => 0.0,
            'retencion' => 0.0,
            'total' => 0.0,
        ];

        $rows = [$creditoFiscal, $consumidorFinal, $exportacion];
        $total = ['label' => 'Total'];
        foreach (['no_sujetas', 'exentas', 'gravadas', 'exportaciones', 'iva', 'retencion', 'total'] as $key) {
            $total[$key] = collect($rows)->sum($key);
        }
        $rows[] = $total;

        return $rows;
    }

    /**
     * @return array{id:int,name:string,nit:string,nrc:string}
     */
    private function company(User $user, Tenant $tenant): array
    {
        $empresaId = $this->fiscalLinks->coreEmpresaId($tenant);
        $context = $this->coreGet($user, 'billing/context');
        $empresa = collect($context['empresas'] ?? [])->first(
            fn (array $item): bool => (int) ($item['id'] ?? 0) === $empresaId
        );

        if (! is_array($empresa)) {
            throw new RuntimeException('No fue posible resolver la empresa fiscal para generar el libro.');
        }

        return [
            'id' => $empresaId,
            'name' => (string) ($empresa['razon_social'] ?? $empresa['nombre_comercial'] ?? $tenant->name),
            'nit' => (string) ($empresa['nit'] ?? $empresa['fiscal_document_number'] ?? ''),
            'nrc' => (string) ($empresa['nrc'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salesAnnex(User $user, int $empresaId, string $from, string $to): array
    {
        return $this->coreGet($user, 'dte/annexes/sales', [
            'empresa_id' => $empresaId,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     */
    private function coreGet(User $user, string $path, array $query = []): array
    {
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('La conexion con el core fiscal no esta configurada.');
        }

        $session = $this->coreSessions->openFor($user);
        $token = (string) ($session['token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('No fue posible abrir la sesion fiscal.');
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(30)
            ->get($baseUrl.'/'.ltrim($path, '/'), $query);

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('message') ?? 'No fue posible consultar la informacion fiscal.'));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('El core fiscal devolvio una respuesta inesperada.');
        }

        return $payload;
    }

    /**
     * @return array{from:string,to:string}
     */
    private function period(int $month, int $year): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return [
            'from' => $start->toDateString(),
            'to' => $start->endOfMonth()->toDateString(),
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<string, mixed>
     */
    private function taxpayerSalesBook(array $rows): array
    {
        $mapped = collect($rows)->values()->map(fn (array $row, int $index): array => [
            'no' => $index + 1,
            'fecha' => $row[0] ?? '',
            'numero' => $row[3] ?? '',
            'nombre' => $row[8] ?? '',
            'registro' => $row[7] ?? '',
            'no_sujetas' => $this->number($row[10] ?? 0),
            'exentas' => $this->number($row[9] ?? 0),
            'gravadas' => $this->number($row[11] ?? 0),
            'exportaciones' => $this->number($row[6] ?? 0),
            'iva' => $this->number($row[12] ?? 0),
            'retencion' => 0.0,
            'total' => $this->number($row[15] ?? 0),
        ])->all();

        return $this->book(
            'taxpayer_sales',
            'Libro de Iva Ventas Contribuyentes',
            [
                ['key' => 'no', 'label' => 'No.'],
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'numero', 'label' => 'Numero'],
                ['key' => 'nombre', 'label' => 'Nombre'],
                ['key' => 'registro', 'label' => 'Registro'],
                ['key' => 'no_sujetas', 'label' => 'No Sujetas', 'money' => true, 'group' => 'No Gravadas'],
                ['key' => 'exentas', 'label' => 'Exentas', 'money' => true, 'group' => 'No Gravadas'],
                ['key' => 'gravadas', 'label' => 'Locales', 'money' => true, 'group' => 'Gravadas'],
                ['key' => 'exportaciones', 'label' => 'Exportaciones', 'money' => true, 'group' => 'Gravadas'],
                ['key' => 'iva', 'label' => 'Iva', 'money' => true],
                ['key' => 'retencion', 'label' => 'Retencion', 'money' => true],
                ['key' => 'total', 'label' => 'Monto', 'money' => true],
            ],
            $mapped,
            ['no_sujetas', 'exentas', 'gravadas', 'exportaciones', 'iva', 'retencion', 'total'],
            'Ventas a Contribuyentes'
        );
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<string, mixed>
     */
    private function consumerSalesBook(array $rows): array
    {
        $mapped = collect($rows)->values()->map(function (array $row, int $index): array {
            // El anexo de ventas a consumidor final solo entrega el monto bruto
            // (gravadas + iva incluido) en el índice 13; no expone el IVA por
            // separado, así que se extrae del bruto a la tasa del 13%.
            $gravadas = $this->number($row[13] ?? 0);
            $neto = round($gravadas / 1.13, 2);

            return [
                'no' => $index + 1,
                'fecha' => $row[0] ?? '',
                'del_no' => $row[7] ?? '',
                'al_no' => $row[8] ?? '',
                'exentas' => $this->number($row[10] ?? 0),
                'exportaciones' => $this->number($row[11] ?? 0),
                'no_sujetas' => $this->number($row[12] ?? 0),
                'gravadas' => $gravadas,
                'iva' => round($gravadas - $neto, 2),
                'retenido' => $this->number($row[15] ?? 0),
                'total' => $this->number($row[18] ?? 0),
            ];
        })->all();

        $book = $this->book(
            'consumer_sales',
            'Libro de Iva Ventas Consumidor Final',
            [
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'del_no', 'label' => 'Del'],
                ['key' => 'al_no', 'label' => 'Al'],
                ['key' => 'no_sujetas', 'label' => 'No sujetas', 'money' => true],
                ['key' => 'exentas', 'label' => 'Exentas', 'money' => true],
                ['key' => 'gravadas', 'label' => 'Locales', 'money' => true],
                ['key' => 'exportaciones', 'label' => 'Exterior', 'money' => true],
                ['key' => 'total', 'label' => 'Total', 'money' => true],
            ],
            $mapped,
            ['exentas', 'no_sujetas', 'gravadas', 'exportaciones', 'iva', 'retenido', 'total'],
            'Ventas a Consumidor Final'
        );

        $book['operationsSummary'] = $this->consumerOperationsSummary($book['totals']);

        return $book;
    }

    /**
     * @param  array<string, float>  $totals
     * @return array<int, array{label: string, value: float, bold?: bool, ruleAbove?: bool}>
     */
    private function consumerOperationsSummary(array $totals): array
    {
        $gravadas = $totals['gravadas'] ?? 0.0;
        $iva = $totals['iva'] ?? 0.0;
        $retenido = $totals['retenido'] ?? 0.0;
        $exentas = $totals['exentas'] ?? 0.0;
        $exterior = $totals['exportaciones'] ?? 0.0;
        $noSujetas = $totals['no_sujetas'] ?? 0.0;
        $totalGravadas = $gravadas;
        $totalGeneral = $totalGravadas + $exentas + $exterior + $noSujetas;

        return [
            ['label' => 'Ventas Netas', 'value' => $gravadas - $iva],
            ['label' => 'Iva(Debito Fiscal)', 'value' => $iva],
            ['label' => 'Iva Retenido', 'value' => $retenido],
            ['label' => 'Total Ventas', 'value' => $totalGravadas, 'bold' => true, 'ruleAbove' => true],
            ['label' => 'Ventas Exentas', 'value' => $exentas],
            ['label' => 'Ventas al Exterior', 'value' => $exterior],
            ['label' => 'Total Ventas', 'value' => $noSujetas, 'ruleAbove' => true],
            ['label' => 'Total Ventas', 'value' => $totalGeneral, 'bold' => true],
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<string, mixed>
     */
    private function purchasesBook(array $rows): array
    {
        $mapped = collect($rows)->values()->map(fn (array $row, int $index): array => [
            'no' => $index + 1,
            'fecha' => $row[0] ?? '',
            'numero' => $row[3] ?? '',
            'registro' => $row[4] ?? '',
            'nombre' => $row[5] ?? '',
            'exentas' => $this->number($row[6] ?? 0) + $this->number($row[7] ?? 0) + $this->number($row[8] ?? 0),
            'importaciones' => $this->number($row[10] ?? 0) + $this->number($row[11] ?? 0) + $this->number($row[12] ?? 0),
            'locales' => $this->number($row[9] ?? 0),
            'iva' => $this->number($row[13] ?? 0),
            'retencion' => 0.0,
            'percepcion' => 0.0,
            'total' => $this->number($row[14] ?? 0),
        ])->all();

        $book = $this->book(
            'purchases',
            'Libro de Iva Compras',
            [
                ['key' => 'no', 'label' => 'No.'],
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'numero', 'label' => 'Numero'],
                ['key' => 'registro', 'label' => 'Registro'],
                ['key' => 'nombre', 'label' => 'Nombre'],
                ['key' => 'exentas', 'label' => 'Exentas', 'money' => true],
                ['key' => 'importaciones', 'label' => 'Import.', 'money' => true],
                ['key' => 'locales', 'label' => 'Locales', 'money' => true],
                ['key' => 'iva', 'label' => 'Iva', 'money' => true],
                ['key' => 'retencion', 'label' => 'Retenc.', 'money' => true],
                ['key' => 'percepcion', 'label' => 'Percep.', 'money' => true],
                ['key' => 'total', 'label' => 'Monto', 'money' => true],
            ],
            $mapped,
            ['exentas', 'importaciones', 'locales', 'iva', 'retencion', 'percepcion', 'total'],
            'Compras'
        );

        $book['sectionLabel'] = 'Compras';
        $book['totalLabel'] = 'Totales';

        return $book;
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $totalKeys
     * @return array<string, mixed>
     */
    private function book(string $key, string $title, array $columns, array $rows, array $totalKeys, string $emptyLabel = ''): array
    {
        $totals = [];

        foreach ($totalKeys as $totalKey) {
            $totals[$totalKey] = collect($rows)->sum(fn (array $row): float => $this->number($row[$totalKey] ?? 0));
        }

        return compact('key', 'title', 'columns', 'rows', 'totals', 'emptyLabel');
    }

    private function number(mixed $value): float
    {
        $normalized = str_replace([',', '$'], '', (string) $value);

        return is_numeric($normalized) ? round((float) $normalized, 2) : 0.0;
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'ENERO',
            2 => 'FEBRERO',
            3 => 'MARZO',
            4 => 'ABRIL',
            5 => 'MAYO',
            6 => 'JUNIO',
            7 => 'JULIO',
            8 => 'AGOSTO',
            9 => 'SEPTIEMBRE',
            10 => 'OCTUBRE',
            11 => 'NOVIEMBRE',
            12 => 'DICIEMBRE',
        ][$month];
    }
}
