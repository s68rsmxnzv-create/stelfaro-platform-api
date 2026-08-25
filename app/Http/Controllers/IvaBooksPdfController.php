<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\IvaBooks\IvaBooksReportService;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class IvaBooksPdfController extends Controller
{
    public function __invoke(Request $request, IvaBooksReportService $reports, BrowsershotPdfRenderer $pdfRenderer): Response
    {
        $validated = $request->validate([
            'book' => ['nullable', Rule::in(['all', 'taxpayer_sales', 'consumer_sales', 'purchases'])],
            'month' => ['required', 'numeric', 'between:1,12'],
            'year' => ['required', 'numeric', 'between:2000,2100'],
        ]);

        $tenant = $this->activeTenant($request);
        $book = (string) ($validated['book'] ?? 'all');
        $month = (int) $validated['month'];
        $year = (int) $validated['year'];
        $payload = $reports->build($request->user(), $tenant, $book, $month, $year);
        $html = view('iva-books.pdf', [
            ...$payload,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();
        $filename = sprintf('libros-iva-%s-%04d-%02d.pdf', $book, $year, $month);

        return response($pdfRenderer->render($html, ['landscape' => true, 'margins' => [10, 10, 10, 10]]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function activeTenant(Request $request): Tenant
    {
        $membership = $request->user()
            ?->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        abort_unless($membership?->tenant instanceof Tenant, 403, 'No hay un tenant activo para generar libros de IVA.');

        return $membership->tenant;
    }
}
